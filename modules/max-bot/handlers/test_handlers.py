from types import SimpleNamespace
from unittest.mock import AsyncMock

from handlers.bot_added import handle_bot_added
from handlers.bot_started import handle_bot_started


async def test_bot_started_sends_welcome(mock_bot):
    deps = SimpleNamespace(
        bot=mock_bot,
        messages=SimpleNamespace(get=AsyncMock(return_value="Привет!")),
    )
    event = SimpleNamespace(chat_id=123)

    await handle_bot_started(event, deps)

    mock_bot.send_message.assert_awaited_once_with(chat_id=123, text="Привет!")


async def test_bot_added_sends_message(mock_bot):
    deps = SimpleNamespace(
        bot=mock_bot,
        messages=SimpleNamespace(get=AsyncMock(return_value="Спасибо!")),
    )
    event = SimpleNamespace(chat_id=456)

    await handle_bot_added(event, deps)

    mock_bot.send_message.assert_awaited_once_with(chat_id=456, text="Спасибо!")
