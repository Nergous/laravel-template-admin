"""Bot added handler: greets the chat with the BOT_ADDED message."""
from maxapi import Dispatcher
from maxapi.enums import ParseMode

from handlers import HandlerDeps
from log.log import get_logger
from repositories.messages import BOT_ADDED

log = get_logger("handler.bot_added")


def register(dp: Dispatcher, deps: HandlerDeps) -> None:
    @dp.bot_added()
    async def _on_bot_added(event):
        await handle_bot_added(event, deps)


async def handle_bot_added(event, deps: HandlerDeps) -> None:
    # Тексты редактируются в админке через NRichText и хранятся как HTML
    # (инлайн-разметка), поэтому отправляем с format=HTML.
    text = await deps.messages.get(BOT_ADDED)
    if text:
        await deps.bot.send_message(
            chat_id=event.chat_id, text=text, format=ParseMode.HTML
        )