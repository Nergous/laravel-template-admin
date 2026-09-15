"""BOT_MODE / webhook parsing in Config.from_env.

Base variables are supplied by the isolated fixture in conftest; each test only
sets the webhook-related variables. No application .env files are read.
"""
import pytest
from pathlib import PurePosixPath

from config.config import Config
from config import config as config_module


def test_media_root_handles_short_docker_path(monkeypatch):
    monkeypatch.setattr(config_module, "Path", lambda _: type(
        "DockerPath", (), {"resolve": lambda self: PurePosixPath("/app/config/config.py")}
    )())

    assert config_module._default_media_root() == "/storage/app/public"


def test_defaults_to_polling(monkeypatch):
    monkeypatch.delenv("BOT_MODE", raising=False)
    cfg = Config.from_env()
    assert cfg.bot_mode == "polling"
    assert cfg.webhook is None


def test_invalid_mode_raises(monkeypatch):
    monkeypatch.setenv("BOT_MODE", "carrier-pigeon")
    with pytest.raises(ValueError, match="BOT_MODE"):
        Config.from_env()


def test_webhook_mode_parses_url_and_defaults(monkeypatch):
    monkeypatch.setenv("BOT_MODE", "webhook")
    monkeypatch.setenv("WEBHOOK_URL", "https://bot.example/webhook")
    monkeypatch.setenv("WEBHOOK_SECRET", "supersecret")
    monkeypatch.delenv("WEBHOOK_HOST", raising=False)
    monkeypatch.delenv("WEBHOOK_PORT", raising=False)
    monkeypatch.delenv("WEBHOOK_PATH", raising=False)

    cfg = Config.from_env()

    assert cfg.bot_mode == "webhook"
    assert cfg.webhook is not None
    assert cfg.webhook.url == "https://bot.example/webhook"
    assert cfg.webhook.host == "0.0.0.0"
    assert cfg.webhook.port == 8080
    assert cfg.webhook.path == "/webhook"
    assert cfg.webhook.secret == "supersecret"


def test_webhook_mode_honours_overrides(monkeypatch):
    monkeypatch.setenv("BOT_MODE", "webhook")
    monkeypatch.setenv("WEBHOOK_URL", "https://bot.example/hook")
    monkeypatch.setenv("WEBHOOK_HOST", "127.0.0.1")
    monkeypatch.setenv("WEBHOOK_PORT", "9000")
    monkeypatch.setenv("WEBHOOK_PATH", "/hook")
    monkeypatch.delenv("WEBHOOK_SECRET", raising=False)

    cfg = Config.from_env()

    assert cfg.webhook.host == "127.0.0.1"
    assert cfg.webhook.port == 9000
    assert cfg.webhook.path == "/hook"
    # Secret is optional — absent means no header verification.
    assert cfg.webhook.secret is None


def test_webhook_mode_requires_url(monkeypatch):
    monkeypatch.setenv("BOT_MODE", "webhook")
    monkeypatch.delenv("WEBHOOK_URL", raising=False)
    with pytest.raises(ValueError, match="WEBHOOK_URL"):
        Config.from_env()


def test_webhook_url_must_be_https(monkeypatch):
    monkeypatch.setenv("BOT_MODE", "webhook")
    monkeypatch.setenv("WEBHOOK_URL", "http://insecure.example/webhook")
    with pytest.raises(ValueError, match="HTTPS"):
        Config.from_env()


def test_webhook_secret_length_validated(monkeypatch):
    monkeypatch.setenv("BOT_MODE", "webhook")
    monkeypatch.setenv("WEBHOOK_URL", "https://bot.example/webhook")
    monkeypatch.setenv("WEBHOOK_SECRET", "abc")  # too short (<5)
    with pytest.raises(ValueError, match="5-256"):
        Config.from_env()
