"""Sending a registry message — text plus its media attachments — to a chat.

Resolves a message ``code`` to its current text (admin override or default) and the
media attached to it in the admin panel, then sends both. Files are uploaded to MAX
by the library: ``bot.send_message`` accepts ``InputMedia`` items and handles the
upload + token dance internally (see maxapi ``process_input_media``).
"""
from __future__ import annotations

import os
from typing import TYPE_CHECKING

from maxapi.enums import ParseMode
from maxapi.enums.upload_type import UploadType
from maxapi.types.input_media import InputMedia

from log.log import get_logger
from repositories.messages import MessageAttachment

if TYPE_CHECKING:
    from handlers import HandlerDeps

log = get_logger("messaging")


def _upload_type(mime: str | None) -> UploadType:
    """Map a stored MIME type to the MAX upload category (mirrors Media::categorize)."""
    mime = mime or ""
    if mime.startswith("image/"):
        return UploadType.IMAGE
    if mime.startswith("video/"):
        return UploadType.VIDEO
    if mime.startswith("audio/"):
        return UploadType.AUDIO
    return UploadType.FILE


def _build_input_media(
    media_root: str, att: MessageAttachment
) -> InputMedia | None:
    # filename is relative to the public disk (e.g. "media/abc.webp"); media_root is
    # where that disk is mounted for the bot. A vanished file is skipped, not fatal.
    path = os.path.join(media_root, att.filename)
    if not os.path.isfile(path):
        log.warning("attachment file missing, skipping: %s", path)
        return None
    return InputMedia(path, type=_upload_type(att.mime_type))


async def send_bot_message(
    deps: "HandlerDeps", *, chat_id: int, code: str
) -> None:
    """Send the message registered under ``code`` (text + attachments) to ``chat_id``.

    When neither text nor a usable attachment is present, nothing is sent (mirrors the
    previous "skip empty text" behavior).
    """
    text = await deps.messages.get(code)
    attachments = await deps.messages.get_attachments(code)

    media = [
        item
        for item in (
            _build_input_media(deps.cfg.media_root, att) for att in attachments
        )
        if item is not None
    ]

    if not text and not media:
        return

    # Texts are authored in the admin via NRichText and stored as inline HTML,
    # so we send with format=HTML.
    await deps.bot.send_message(
        chat_id=chat_id,
        text=text or None,
        attachments=media or None,
        format=ParseMode.HTML,
    )
