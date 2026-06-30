"""Handler registration. Called once during bot startup."""
from dataclasses import dataclass

import aiomysql
from maxapi import Bot, Dispatcher

from config.config import Config

from repositories.messages import MessageRepository

@dataclass
class HandlerDeps:
    db: aiomysql.Pool
    bot: Bot
    cfg: Config
    messages: MessageRepository

def register_handlers(dp: Dispatcher, deps: HandlerDeps) -> None:
    from handlers import bot_added, bot_started #, bot_removed, bot_stopped, message_created, message_callback, message_edited, message_removed, user_added, user_removed, chat_title_changed, dialog_cleared, dialog_muted, dialog_unmuted, dialog_removed
    bot_started.register(dp, deps)
    bot_added.register(dp, deps)
    # bot_removed.register(dp, deps)
    # bot_stopped.register(dp, deps)
    # message_created.register(dp, deps)
    # message_callback.register(dp, deps)
    # message_edited.register(dp, deps)
    # message_removed.register(dp, deps)
    # user_added.register(dp, deps)
    # user_removed.register(dp, deps)
    # chat_title_changed.register(dp, deps)
    # dialog_cleared.register(dp, deps)
    # dialog_muted.register(dp, deps)
    # dialog_unmuted.register(dp, deps)
    # dialog_removed.register(dp, deps)