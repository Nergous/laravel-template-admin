"""Application settings loaded from env variables"""

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
_DEFAULT_MEDIA_ROOT = str(
    Path(__file__).resolve().parents[3] / "storage" / "app" / "public"
)

@dataclass(frozen=True)
class DbConfig:
    host: str
    port: int
    database: str
    user: str
    password: str

@dataclass(frozen=True)
class Config:
    api_base_url: str
    api_token: str
    bot_id: int
    db: DbConfig
    media_root: str
    max_api_rate_limit_hz: int = 20

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
                password=_required("DB_PASSWORD"),
            ),
            media_root=os.getenv("MEDIA_ROOT", _DEFAULT_MEDIA_ROOT),
            max_api_rate_limit_hz=int(os.getenv("MAX_API_RATE_LIMIT_HZ", 20)),
        )