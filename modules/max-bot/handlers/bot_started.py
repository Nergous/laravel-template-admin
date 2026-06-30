"""Bot started handler: greets the user with the WELCOME message."""
from maxapi import Dispatcher

from handlers import HandlerDeps
from log.log import get_logger
from repositories.messages import WELCOME
from utils.messaging import send_bot_message

log = get_logger("handler.bot_started")


def register(dp: Dispatcher, deps: HandlerDeps) -> None:
    @dp.bot_started()
    async def _on_bot_started(event):
        await handle_bot_started(event, deps)


async def handle_bot_started(event, deps: HandlerDeps) -> None:
    # Text (admin override or default) plus any media attached to this code.
    await send_bot_message(deps, chat_id=event.chat_id, code=WELCOME)
