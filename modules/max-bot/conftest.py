"""Offline pytest fixtures: fresh in-memory SQLite per test, no application env."""
import socket
import sqlite3
from contextlib import asynccontextmanager
from unittest.mock import AsyncMock, MagicMock

import aiomysql
import pytest


@pytest.fixture(autouse=True)
def isolated_environment(monkeypatch):
    """Never load .env or allow a test to open an external socket."""
    for name, value in {
        "API_TOKEN": "fake_test_token", "BOT_ID": "1",
        "API_BASE_URL": "https://api.invalid",
        "DB_HOST": "127.0.0.1", "DB_PORT": "1",
        "DB_DATABASE": ":memory:", "DB_USERNAME": "test", "DB_PASSWORD": "",
        "BOT_MODE": "polling", "LOG_LEVEL_BOT": "WARNING",
    }.items():
        monkeypatch.setenv(name, value)
    for name in ("WEBHOOK_URL", "WEBHOOK_HOST", "WEBHOOK_PORT",
                 "WEBHOOK_PATH", "WEBHOOK_SECRET", "MEDIA_ROOT"):
        monkeypatch.delenv(name, raising=False)

    def deny_network(*args, **kwargs):
        raise AssertionError("External network access is forbidden in bot tests")

    # Windows asyncio builds its internal wake-up pipe with socketpair(), whose
    # standard-library implementation briefly connects two local sockets.
    original_pair = socket.socketpair
    original_connect = socket.socket.connect
    original_connect_ex = socket.socket.connect_ex

    def local_socketpair(*args, **kwargs):
        with monkeypatch.context() as pair_patch:
            pair_patch.setattr(socket.socket, "connect", original_connect)
            pair_patch.setattr(socket.socket, "connect_ex", original_connect_ex)
            return original_pair(*args, **kwargs)

    monkeypatch.setattr(socket, "socketpair", local_socketpair)
    monkeypatch.setattr(socket.socket, "connect", deny_network)
    monkeypatch.setattr(socket.socket, "connect_ex", deny_network)
    monkeypatch.setattr(socket, "create_connection", deny_network)


_SCHEMA = """
PRAGMA foreign_keys = ON;
CREATE TABLE bot_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL UNIQUE, text TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1, updated_by INTEGER,
    created_at TEXT, updated_at TEXT
);
CREATE TABLE media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL, original_name TEXT, mime_type TEXT,
    type TEXT, size INTEGER, has_thumb INTEGER NOT NULL DEFAULT 0,
    created_at TEXT, updated_at TEXT
);
CREATE TABLE bot_message_media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL, media_id INTEGER NOT NULL,
    position INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT,
    UNIQUE (code, media_id),
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);
"""


class SQLiteCursor:
    """Test-only adapter for the small DB-API subset used by the repositories."""

    def __init__(self, connection, dict_rows):
        self.cursor = connection.cursor()
        self.dict_rows = dict_rows

    @property
    def lastrowid(self):
        return self.cursor.lastrowid

    async def execute(self, sql, params=()):
        # Repository statements use MySQL's %s placeholders; SQLite uses ?.
        self.cursor.execute(sql.replace("%s", "?"), params)
        return self.cursor.rowcount

    def _row(self, row):
        if row is None or not self.dict_rows:
            return row
        return dict(zip((column[0] for column in self.cursor.description), row))

    async def fetchone(self):
        return self._row(self.cursor.fetchone())

    async def fetchall(self):
        return [self._row(row) for row in self.cursor.fetchall()]


class SQLiteConnection:
    def __init__(self, connection):
        self.connection = connection

    @asynccontextmanager
    async def cursor(self, factory=None):
        cursor = SQLiteCursor(self.connection, factory is aiomysql.DictCursor)
        try:
            yield cursor
        finally:
            cursor.cursor.close()

    async def begin(self):
        self.connection.execute("BEGIN")

    async def commit(self):
        self.connection.commit()

    async def rollback(self):
        self.connection.rollback()


@pytest.fixture
def db_pool():
    """Fresh SQLite memory database; no credentials or server are used."""
    database = sqlite3.connect(":memory:")
    database.executescript(_SCHEMA)
    connection = SQLiteConnection(database)

    @asynccontextmanager
    async def acquire():
        yield connection

    # spec preserves the Pool isinstance contract in repositories.db.execute().
    pool = MagicMock(spec=aiomysql.Pool)
    pool.acquire.side_effect = acquire
    pool.wait_closed = AsyncMock()
    try:
        yield pool
    finally:
        database.close()


@pytest.fixture
def mock_bot():
    """A maxapi.Bot stand-in with AsyncMock for outgoing API calls."""
    bot = MagicMock(name="MaxBot")
    for method in (
        "send_message", "edit_message", "delete_message", "send_action",
        "get_chat_by_id", "get_chats", "get_message", "get_upload_url",
        "subscribe_webhook", "close_session",
    ):
        setattr(bot, method, AsyncMock(name=f"bot.{method}"))
    return bot

