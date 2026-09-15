"""Bot entry point: starts the update listener (long-polling or webhook) + all
background workers in one process. Mode is chosen by BOT_MODE (see config.py)."""
import asyncio
import signal
import sys
from pathlib import Path

from dotenv import load_dotenv

# Load the bot environment first, then the Laravel root as a fallback.
ENV_FILE = Path(__file__).resolve().parent
for _env in (ENV_FILE / ".env", ENV_FILE.parent.parent / ".env"):
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


def build_listener(dp: Dispatcher, bot: Bot, cfg: Config):
    """Return the update-listener coroutine for the configured BOT_MODE.

    polling → ``dp.start_polling(bot)``.
    webhook → register the public HTTPS endpoint with MAX, then run the local aiohttp
    receiver. ``handle_webhook`` blocks until the task is cancelled, so it slots into
    the same task/cancel graceful-shutdown flow as polling.
    """
    if cfg.bot_mode != "webhook":
        return dp.start_polling(bot)

    wh = cfg.webhook
    assert wh is not None  # Config.from_env guarantees this when mode=webhook

    async def _serve() -> None:
        await bot.subscribe_webhook(url=wh.url, secret=wh.secret)
        log.info("webhook subscribed url=%s serving on %s:%d%s",
                 wh.url, wh.host, wh.port, wh.path)
        await dp.handle_webhook(
            bot,
            host=wh.host,
            port=wh.port,
            path=wh.path,
            secret=wh.secret,
        )

    return _serve()


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

    stop_event = asyncio.Event()

    # Add new workers like scheduler, etc.
    workers = []

    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        try:
            loop.add_signal_handler(sig, stop_event.set)
        except NotImplementedError:
            pass
    
    listener_task = asyncio.create_task(build_listener(dp, bot, cfg), name=cfg.bot_mode)
    stop_task = asyncio.create_task(stop_event.wait(), name="stop-signal")
    log.info("bot started in %s mode; %d workers running", cfg.bot_mode, len(workers))

    fatal: BaseException | None = None
    handled: set[asyncio.Task] = set()
    try:
        done, _pending = await asyncio.wait(
            [listener_task, stop_task],
            return_when=asyncio.FIRST_COMPLETED,
        )

        if listener_task in done and not listener_task.cancelled():
            handled.add(listener_task)
            exc = listener_task.exception()
            if exc is not None:
                fatal = exc
                log.error("%s listener terminated with error: %r", cfg.bot_mode, exc)
            else:
                log.warning("%s listener exited unexpectedly without raising an exception", cfg.bot_mode)
    finally:
        log.info("shutting down")
        for t in workers + [listener_task, stop_task]:
            t.cancel()
        for t in workers + [listener_task, stop_task]:
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
