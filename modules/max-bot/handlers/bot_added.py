"""Bot added handler: greets the chat with the BOT_ADDED message."""
from maxapi import Dispatcher

from handlers import HandlerDeps
from log.log import get_logger
from repositories.messages import BOT_ADDED
from utils.messaging import send_bot_message

log = get_logger("handler.bot_added")


def register(dp: Dispatcher, deps: HandlerDeps) -> None:
    @dp.bot_added()
    async def _on_bot_added(event):
        await handle_bot_added(event, deps)


async def handle_bot_added(event, deps: HandlerDeps) -> None:
    # Text (admin override or default) plus any media attached to this code.
    await send_bot_message(deps, chat_id=event.chat_id, code=BOT_ADDED)
