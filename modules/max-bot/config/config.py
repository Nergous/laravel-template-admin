"""Application settings loaded from env variables"""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _required(name: str) -> str:
    val = os.getenv(name)
    if not val:
        raise ValueError(f"Environment variable {name} is required")
    return val


# Root of the Laravel `public` disk where media files live (filename column is
# relative to it, e.g. "media/abc.webp"). In Docker the storage volume is mounted
# read-only and MEDIA_ROOT points at it; for a local run it defaults to the repo's
# storage/app/public (config.py is at modules/max-bot/config/config.py).
# In the Docker image the module sits at /app/config/config.py — parents[3] does
# not exist there, so guard the index: MEDIA_ROOT env always wins in Docker anyway.
def _default_media_root() -> str:
    parents = Path(__file__).resolve().parents
    root = parents[3] if len(parents) > 3 else parents[-1]
    return str(root / "storage" / "app" / "public")


_DEFAULT_MEDIA_ROOT = _default_media_root()

@dataclass(frozen=True)
class DbConfig:
    host: str
    port: int
    database: str
    user: str
    password: str


@dataclass(frozen=True)
class WebhookConfig:
    """Webhook-mode settings. Present only when BOT_MODE=webhook.

    `url` is the public HTTPS endpoint MAX pushes updates to (registered via
    Bot.subscribe_webhook). `host`/`port`/`path` bind the local aiohttp server that
    receives those pushes — typically behind a TLS-terminating reverse proxy that maps
    `url` → this host:port. `secret` (if set) is echoed back by MAX in the
    `X-Max-Bot-Api-Secret` header and verified on every request.
    """
    url: str
    host: str
    port: int
    path: str
    secret: str | None

@dataclass(frozen=True)
class Config:
    api_base_url: str
    api_token: str
    bot_id: int
    db: DbConfig
    media_root: str
    max_api_rate_limit_hz: int = 20
    # "polling" (default, long-polling) or "webhook". Chooses how updates are received.
    bot_mode: str = "polling"
    webhook: WebhookConfig | None = None

    @classmethod
    def from_env(cls) -> Config:
        return cls(
            api_base_url=os.getenv("API_BASE_URL", "https://platform-api2.max.ru"),
            api_token=_required("API_TOKEN"),
            bot_id=int(_required("BOT_ID")),
            db=DbConfig(
                host=os.getenv("DB_HOST", "127.0.0.1"),
                port=int(os.getenv("DB_PORT", "3306")),
                database=_required("DB_DATABASE"),
                user=_required("DB_USERNAME"),
                password=os.getenv("DB_PASSWORD", ""),
            ),
            media_root=os.getenv("MEDIA_ROOT", _DEFAULT_MEDIA_ROOT),
            max_api_rate_limit_hz=int(os.getenv("MAX_API_RATE_LIMIT_HZ", 20)),
            bot_mode=_bot_mode(),
            webhook=_webhook_from_env(),
        )


def _bot_mode() -> str:
    mode = os.getenv("BOT_MODE", "polling").strip().lower()
    if mode not in ("polling", "webhook"):
        raise ValueError(
            f"BOT_MODE must be 'polling' or 'webhook', got {mode!r}"
        )
    return mode


def _webhook_from_env() -> WebhookConfig | None:
    """Parse webhook settings; returns None unless BOT_MODE=webhook."""
    if _bot_mode() != "webhook":
        return None

    url = _required("WEBHOOK_URL")
    if not url.startswith("https://"):
        # MAX only pushes updates to an HTTPS endpoint.
        raise ValueError("WEBHOOK_URL must be an HTTPS URL")

    secret = os.getenv("WEBHOOK_SECRET") or None
    if secret is not None and not (5 <= len(secret) <= 256):
        raise ValueError("WEBHOOK_SECRET must be 5-256 characters")

    return WebhookConfig(
        url=url,
        host=os.getenv("WEBHOOK_HOST", "0.0.0.0"),
        port=int(os.getenv("WEBHOOK_PORT", "8080")),
        path=os.getenv("WEBHOOK_PATH", "/webhook"),
        secret=secret,
    )
