"""Bot entry point: starts long-polling + all background workers in one process."""
import asyncio
import signal
import sys
from pathlib import Path

from dotenv import load_dotenv

# Load .env from bot/ firstm then fallback to admin root (one level up).
ENV_FILE = Path(__file__).resolve().parent
for _env in (ENV_FILE / ".env", ENV_FILE.parent / ".env"):
    if _env.exists():
        load_dotenv(_env, override=False)

from maxapi import Bot, Dispatcher

from log.log import get_logger
from config.config import Config
from repositories.db import create_pool
from repositories.messages import MessageRepository
from handlers import HandlerDeps, register_handlers
from utils.rate_limiter import RateLimiter

log = get_logger("main")

async def main() -> None:
    cfg = Config.from_env()
    log.info("starting bot id=%s", cfg.bot_id)

    db = await create_pool(
        host=cfg.db.host,
        port=cfg.db.port,
        user=cfg.db.user,
        password=cfg.db.password,
        database=cfg.db.database,
        minsize=2,
        maxsize=20,
    )
    bot = Bot(cfg.api_token)
    bot.set_api_url(cfg.api_base_url)
    dp = Dispatcher()
    deps = HandlerDeps(db=db, bot=bot, cfg=cfg, messages=MessageRepository(db))
    register_handlers(dp, deps)

    limiter = RateLimiter(cfg.max_api_rate_limit_hz)

    # Add new workers like scheduler, etc.
    workers = []

    stop_event = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            loop.add_signal_handler(sig, stop_event.set)
        except NotImplementedError:
            pass
    
    polling_task = asyncio.create_task(dp.start_polling(bot), name="long-polling")
    stop_task = asyncio.create_task(stop_event.wait(), name="stop-signal")
    log.info("bot started; %d workers running", len(workers))

    fatal: BaseException | None = None
    handled: set[asyncio.Task] = set()
    try:
        done, _pending = await asyncio.wait(
            [polling_task, stop_task],
            return_when=asyncio.FIRST_COMPLETED,
        )

        if polling_task in done and not polling_task.cancelled():
            handled.add(polling_task)
            exc = polling_task.exception()
            if exc is not None:
                fatal = exc
                log.error("long-polling terminated with error: %r", exc)
            else:
                log.warning("long-polling exited unexpectedly without raising an exception")
    finally:
        log.info("shutting down")
        for t in workers + [polling_task, stop_task]:
            t.cancel()
        for t in workers + [polling_task, stop_task]:
            if t in handled:
                continue
            try:
                await t
            except asyncio.CancelledError:
                pass
            except Exception:
                log.exception("task %s raised during shutdown", t.get_name())

        try:
            await bot.close_session()
        except Exception:  
            log.exception("error closing bot session")

        db.close()
        await db.wait_closed()
    
    if fatal is not None:
        raise SystemExit(1)

if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        sys.exit(0)