"""Unit tests for utils.messaging (text + media attachments dispatch).

No DB: deps are stubbed. Covers the MIME→upload-type mapping, the missing-file
skip, and the "send nothing when empty" guard.
"""
import os
from types import SimpleNamespace
from unittest.mock import AsyncMock

from maxapi.enums import ParseMode
from maxapi.enums.upload_type import UploadType
from maxapi.types.input_media import InputMedia

from repositories.messages import MessageAttachment
from utils.messaging import _upload_type, send_bot_message


def _deps(media_root, text, attachments):
    bot = SimpleNamespace(send_message=AsyncMock())
    return SimpleNamespace(
        bot=bot,
        cfg=SimpleNamespace(media_root=str(media_root)),
        messages=SimpleNamespace(
            get=AsyncMock(return_value=text),
            get_attachments=AsyncMock(return_value=attachments),
        ),
    )


def test_upload_type_mapping():
    assert _upload_type("image/webp") == UploadType.IMAGE
    assert _upload_type("video/mp4") == UploadType.VIDEO
    assert _upload_type("audio/mpeg") == UploadType.AUDIO
    assert _upload_type("application/pdf") == UploadType.FILE
    assert _upload_type(None) == UploadType.FILE


async def test_sends_text_only_when_no_attachments():
    deps = _deps("/nope", "Привет!", [])

    await send_bot_message(deps, chat_id=1, code="welcome")

    deps.bot.send_message.assert_awaited_once_with(
        chat_id=1, text="Привет!", attachments=None, format=ParseMode.HTML
    )


async def test_skips_missing_file_but_still_sends_text():
    att = MessageAttachment(
        filename="media/gone.webp", mime_type="image/webp", original_name="gone.webp"
    )
    deps = _deps("/nonexistent-root", "hi", [att])

    await send_bot_message(deps, chat_id=2, code="welcome")

    deps.bot.send_message.assert_awaited_once_with(
        chat_id=2, text="hi", attachments=None, format=ParseMode.HTML
    )


async def test_sends_nothing_when_no_text_and_no_usable_media():
    att = MessageAttachment(
        filename="media/gone.pdf", mime_type="application/pdf", original_name="gone.pdf"
    )
    deps = _deps("/nonexistent-root", "", [att])

    await send_bot_message(deps, chat_id=3, code="welcome")

    deps.bot.send_message.assert_not_awaited()


async def test_attaches_existing_file_with_mapped_type(tmp_path):
    media_dir = tmp_path / "media"
    media_dir.mkdir()
    pdf = media_dir / "doc.pdf"
    pdf.write_bytes(b"%PDF-1.4 fake")

    att = MessageAttachment(
        filename="media/doc.pdf", mime_type="application/pdf", original_name="doc.pdf"
    )
    deps = _deps(tmp_path, "see attached", [att])

    await send_bot_message(deps, chat_id=4, code="welcome")

    deps.bot.send_message.assert_awaited_once()
    kwargs = deps.bot.send_message.await_args.kwargs
    assert kwargs["chat_id"] == 4
    assert kwargs["text"] == "see attached"
    assert kwargs["format"] == ParseMode.HTML
    media = kwargs["attachments"]
    assert isinstance(media, list) and len(media) == 1
    assert isinstance(media[0], InputMedia)
    assert media[0].type == UploadType.FILE
    # os.path.join keeps the forward slash from the relative filename on Windows;
    # compare by identity rather than string form.
    assert os.path.samefile(media[0].path, pdf)
