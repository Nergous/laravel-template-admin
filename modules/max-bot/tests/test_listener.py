"""Listener wiring with mocked transport; importing main never reads an env file."""
import importlib
from unittest.mock import create_autospec

import pytest
from maxapi import Dispatcher

from config.config import Config


@pytest.fixture
def listener(monkeypatch):
    monkeypatch.setattr("dotenv.load_dotenv", lambda *args, **kwargs: None)
    return importlib.import_module("main").build_listener


async def test_polling_listener_does_not_subscribe_webhook(listener, mock_bot):
    dispatcher = create_autospec(Dispatcher, instance=True)

    await listener(dispatcher, mock_bot, Config.from_env())

    dispatcher.start_polling.assert_awaited_once_with(mock_bot)
    dispatcher.handle_webhook.assert_not_awaited()
    mock_bot.subscribe_webhook.assert_not_awaited()


async def test_webhook_listener_registers_and_starts_receiver(listener, mock_bot, monkeypatch):
    monkeypatch.setenv("BOT_MODE", "webhook")
    monkeypatch.setenv("WEBHOOK_URL", "https://bot.example/webhook")
    monkeypatch.setenv("WEBHOOK_SECRET", "test-secret")
    dispatcher = create_autospec(Dispatcher, instance=True)

    await listener(dispatcher, mock_bot, Config.from_env())

    dispatcher.start_polling.assert_not_awaited()
    mock_bot.subscribe_webhook.assert_awaited_once_with(
        url="https://bot.example/webhook", secret="test-secret"
    )
    dispatcher.handle_webhook.assert_awaited_once_with(
        mock_bot, host="0.0.0.0", port=8080, path="/webhook", secret="test-secret"
    )
