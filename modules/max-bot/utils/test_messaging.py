from types import SimpleNamespace
from unittest.mock import AsyncMock

from maxapi.enums import ParseMode

from utils.messaging import send_bot_message


def _deps(mock_bot, text, attachments=None):
    """A deps stand-in: text from the registry, optional attachments."""
    return SimpleNamespace(
        bot=mock_bot,
        cfg=SimpleNamespace(media_root="/nonexistent"),
        messages=SimpleNamespace(
            get=AsyncMock(return_value=text),
            get_attachments=AsyncMock(return_value=attachments or []),
        ),
    )


async def test_edit_message_id_edits_in_place(mock_bot):
    """edit_message_id set → the message is edited, not re-sent."""
    deps = _deps(mock_bot, "Привет!")

    await send_bot_message(deps, chat_id=1, code="welcome", edit_message_id="mid-42")

    mock_bot.send_message.assert_not_awaited()
    mock_bot.edit_message.assert_awaited_once()
    kwargs = mock_bot.edit_message.await_args.kwargs
    assert kwargs["message_id"] == "mid-42"
    assert kwargs["text"] == "Привет!"
    assert kwargs["format"] == ParseMode.HTML


async def test_no_edit_id_sends_new_message(mock_bot):
    """Without edit_message_id the message is sent fresh (unchanged behavior)."""
    deps = _deps(mock_bot, "Привет!")

    await send_bot_message(deps, chat_id=7, code="welcome")

    mock_bot.edit_message.assert_not_awaited()
    mock_bot.send_message.assert_awaited_once()
    assert mock_bot.send_message.await_args.kwargs["chat_id"] == 7


async def test_empty_message_neither_sends_nor_edits(mock_bot):
    """No text, no attachments, no keyboard → nothing happens, even in edit mode."""
    deps = _deps(mock_bot, "")

    await send_bot_message(deps, chat_id=1, code="empty", edit_message_id="mid-1")

    mock_bot.edit_message.assert_not_awaited()
    mock_bot.send_message.assert_not_awaited()


async def test_text_override_and_keyboard_keep_message_attachment_lookup(mock_bot):
    deps = _deps(mock_bot, "Default text")
    keyboard = {"type": "inline_keyboard", "payload": {"buttons": []}}

    await send_bot_message(
        deps, chat_id=7, code="welcome", text_override="Personalized text", keyboard=keyboard
    )

    deps.messages.get.assert_not_awaited()
    deps.messages.get_attachments.assert_awaited_once_with("welcome")
    kwargs = mock_bot.send_message.await_args.kwargs
    assert kwargs["text"] == "Personalized text"
    assert kwargs["attachments"] == [keyboard]
