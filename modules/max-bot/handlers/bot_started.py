"""Bot started handler: greets the user with the WELCOME message."""
from maxapi import Dispatcher
from maxapi.enums import ParseMode

from handlers import HandlerDeps
from log.log import get_logger
from repositories.messages import WELCOME

log = get_logger("handler.bot_started")


def register(dp: Dispatcher, deps: HandlerDeps) -> None:
    @dp.bot_started()
    async def _on_bot_started(event):
        await handle_bot_started(event, deps)


async def handle_bot_started(event, deps: HandlerDeps) -> None:
    # Тексты редактируются в админке через NRichText и хранятся как HTML
    # (инлайн-разметка), поэтому отправляем с format=HTML.
    text = await deps.messages.get(WELCOME)
    if text:
        await deps.bot.send_message(
            chat_id=event.chat_id, text=text, format=ParseMode.HTML
        )