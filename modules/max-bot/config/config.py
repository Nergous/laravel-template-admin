"""Application settings loaded from env variables"""

import os 
from dataclasses import dataclass


def _required(name: str) -> str:
    val = os.getenv(name)
    if not val:
        raise ValueError(f"Environment variable {name} is required")
    return val

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
            max_api_rate_limit_hz=int(os.getenv("MAX_API_RATE_LIMIT_HZ", 20)),
        )