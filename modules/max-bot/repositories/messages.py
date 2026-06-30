"""Bot message texts: code -> DB override, else default from the JSON registry.

Message types (codes) and their defaults live in ``messages.json`` (the single
source shared with the Laravel admin). The DB table ``bot_messages`` holds only
admin overrides. ``MessageRepository.get`` resolves DB override -> registry default.
"""

import json
from dataclasses import dataclass
from pathlib import Path

import aiomysql

from log.log import get_logger
from repositories.db import read

log = get_logger("repo.messages")

_REGISTRY_PATH = Path(__file__).resolve().parent.parent / "messages.json"

@dataclass(frozen=True)
class MessageDef:
    code: str
    label: str
    description: str
    default: str

def _load_registry() -> dict[str, MessageDef]:
    raw = json.loads(_REGISTRY_PATH.read_text(encoding="utf-8"))
    return {
        item["code"]: MessageDef(
            code=item["code"],
            label=item["label"],
            description=item["description"],
            default=item["default"],
        )
        for item in raw
    }

REGISTRY: dict[str, MessageDef] = _load_registry()

# Typed code constants for typo-safe references at call sites.
WELCOME = "welcome"
BOT_ADDED = "bot_added"
BOT_REMOVED = "bot_removed"
BOT_STOPPED = "bot_stopped"
BOT_STARTED = "bot_started"
MESSAGE_CREATED = "message_created"
MESSAGE_CALLBACK = "message_callback"
MESSAGE_EDITED = "message_edited"
MESSAGE_REMOVED = "message_removed"
USER_ADDED = "user_added"
USER_REMOVED = "user_removed"
CHAT_TITLE_CHANGED = "chat_title_changed"
DIALOG_CLEARED = "dialog_cleared"
DIALOG_MUTED = "dialog_muted"
DIALOG_UNMUTED = "dialog_unmuted"
DIALOG_REMOVED = "dialog_removed"

# Fail fast if a declared constant has no registry entry.
for _code in (WELCOME, BOT_ADDED):
    if _code not in REGISTRY:
        raise RuntimeError(f"messages.json is missing required code: {_code!r}")


class MessageRepository:
    """Resolves a message code to its text: admin override (DB) or default (registry)."""

    def __init__(self, pool: aiomysql.Pool):
        self._pool = pool

    async def get(self, code: str) -> str:
        async with read(self._pool) as cur:
            await cur.execute(
                "SELECT text FROM bot_messages WHERE code=%s AND is_active=1 LIMIT 1",
                (code,),
            )
            row = await cur.fetchone()

        if row and row["text"]:
            return row["text"]

        message = REGISTRY.get(code)
        if message is None:
            log.error("unknown message code requested: %r", code)
            return ""
        return message.default