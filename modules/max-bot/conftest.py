"""Shared pytest fixtures for the bot test suite."""
import os
from pathlib import Path
from typing import AsyncIterator

import aiomysql
import pytest
from dotenv import load_dotenv

ENV_FILE = Path(__file__).resolve().parent
for _env in (
        ENV_FILE / ".env", 
        ENV_FILE.parent / ".env", 
        ENV_FILE / ".env.example", 
        ENV_FILE.parent / ".env.example"
    ):
    if _env.exists():
        load_dotenv(_env, override=False)
    
_CREATE_BOT_MESSAGES = """
CREATE TABLE IF NOT EXISTS bot_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    text TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY bot_messages_code_unique (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""

# Minimal mirrors of the Laravel `media` and `bot_message_media` tables — enough
# for repository tests of message attachments (get_attachments joins the two).
_CREATE_MEDIA = """
CREATE TABLE IF NOT EXISTS media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NULL,
    mime_type VARCHAR(255) NULL,
    type VARCHAR(32) NULL,
    size BIGINT NULL,
    has_thumb TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""

_CREATE_BOT_MESSAGE_MEDIA = """
CREATE TABLE IF NOT EXISTS bot_message_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(128) NOT NULL,
    media_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (id),
    UNIQUE KEY bot_message_media_code_media_unique (code, media_id),
    KEY bot_message_media_code_idx (code),
    CONSTRAINT bot_message_media_media_fk
        FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
"""

# Truncated child-first; foreign-key checks are disabled during truncation anyway.
_TABLES = ["bot_messages", "bot_message_media", "media"]

@pytest.fixture
async def db_pool() -> AsyncIterator[aiomysql.Pool]:
    """Connection pool to the test database. Each test cleans the working tables."""
    pool = await aiomysql.create_pool(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", 3306)),
        user=os.getenv("DB_USERNAME"),
        password=os.getenv("DB_PASSWORD"),
        db=os.getenv("DB_DATABASE", "max_bot"),
        autocommit=False,
        minsize=1,
        maxsize=4,
    )
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(_CREATE_BOT_MESSAGES)
            await cur.execute(_CREATE_MEDIA)
            await cur.execute(_CREATE_BOT_MESSAGE_MEDIA)
            await cur.execute("SET FOREIGN_KEY_CHECKS=0")
            for tbl in _TABLES:
                await cur.execute(f"TRUNCATE TABLE {tbl}")
            await cur.execute("SET FOREIGN_KEY_CHECKS=1")
        await conn.commit()
    yield pool
    pool.close()
    await pool.wait_closed()

@pytest.fixture
def mock_bot():
    """A maxapi.Bot stand-in with AsyncMock for outgoing API calls."""        
    from unittest.mock import AsyncMock, MagicMock
    bot = MagicMock(name="MaxBot")
    for method in (
        "send_message", "edit_message", "delete_message", "send_action",
        "get_chat_by_id", "get_chats", "get_message", "get_upload_url",
    ):
        setattr(bot, method, AsyncMock(name=f"bot.{method}"))
    return bot

