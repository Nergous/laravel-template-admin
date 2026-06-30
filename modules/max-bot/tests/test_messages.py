from repositories.messages import REGISTRY, WELCOME, MessageRepository


_INSERT_QUERY = """
    INSERT INTO bot_messages (code, text, is_active) VALUES (%s, %s, %s);
"""

async def _insert(pool, code, text, is_active=1):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(_INSERT_QUERY, (code, text, is_active))
        await conn.commit()

    
def test_registry_loads_welcome():
    assert WELCOME in REGISTRY
    assert REGISTRY[WELCOME].default

async def test_get_returns_default_when_no_rows(db_pool):
    repo = MessageRepository(db_pool)
    assert await repo.get(WELCOME) == REGISTRY[WELCOME].default

async def test_get_returns_override_when_active(db_pool):
    await _insert(db_pool, WELCOME, "Кастомный привет")
    repo = MessageRepository(db_pool)
    assert await repo.get(WELCOME) == "Кастомный привет"

async def test_get_returns_default_when_inactive(db_pool):
    await _insert(db_pool, WELCOME, "Выключено", is_active=0)
    repo = MessageRepository(db_pool)
    assert await repo.get(WELCOME) == REGISTRY[WELCOME].default

async def test_get_returns_default_when_text_empty(db_pool):
    await _insert(db_pool, WELCOME, "")
    repo = MessageRepository(db_pool)
    assert await repo.get(WELCOME) == REGISTRY[WELCOME].default

async def test_get_unknown_code_returns_empty(db_pool):
    repo = MessageRepository(db_pool)
    assert await repo.get("nope_not_real") == ""