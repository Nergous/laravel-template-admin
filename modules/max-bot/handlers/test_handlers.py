from types import SimpleNamespace
from unittest.mock import AsyncMock

from maxapi.enums import ParseMode

from handlers.bot_added import handle_bot_added
from handlers.bot_started import handle_bot_started


def _deps(mock_bot, text):
    """A deps stand-in: text from the registry, no attachments."""
    return SimpleNamespace(
        bot=mock_bot,
        cfg=SimpleNamespace(media_root="/nonexistent"),
        messages=SimpleNamespace(
            get=AsyncMock(return_value=text),
            get_attachments=AsyncMock(return_value=[]),
        ),
    )


async def test_bot_started_sends_welcome(mock_bot):
    deps = _deps(mock_bot, "Привет!")
    event = SimpleNamespace(chat_id=123)

    await handle_bot_started(event, deps)

    mock_bot.send_message.assert_awaited_once_with(
        chat_id=123, text="Привет!", attachments=None, format=ParseMode.HTML
    )


async def test_bot_added_sends_message(mock_bot):
    deps = _deps(mock_bot, "Спасибо!")
    event = SimpleNamespace(chat_id=456)

    await handle_bot_added(event, deps)

    mock_bot.send_message.assert_awaited_once_with(
        chat_id=456, text="Спасибо!", attachments=None, format=ParseMode.HTML
    )
