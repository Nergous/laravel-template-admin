"""DB-backed tests for MessageRepository.get_attachments (joins bot_message_media → media)."""
from repositories.messages import WELCOME, MessageRepository


async def _insert_media(pool, filename, mime=None, name=None):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "INSERT INTO media (filename, mime_type, original_name) VALUES (%s, %s, %s)",
                (filename, mime, name),
            )
            media_id = cur.lastrowid
        await conn.commit()
    return media_id


async def _attach(pool, code, media_id, position):
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                "INSERT INTO bot_message_media (code, media_id, position) VALUES (%s, %s, %s)",
                (code, media_id, position),
            )
        await conn.commit()


async def test_get_attachments_empty_when_none(db_pool):
    repo = MessageRepository(db_pool)
    assert await repo.get_attachments(WELCOME) == []


async def test_get_attachments_returns_rows_in_position_order(db_pool):
    a = await _insert_media(db_pool, "media/a.webp", "image/webp", "a.webp")
    b = await _insert_media(db_pool, "media/b.pdf", "application/pdf", "b.pdf")
    # Attach b first (position 0), a second (position 1) — output must follow position.
    await _attach(db_pool, WELCOME, b, 0)
    await _attach(db_pool, WELCOME, a, 1)

    repo = MessageRepository(db_pool)
    atts = await repo.get_attachments(WELCOME)

    assert [x.filename for x in atts] == ["media/b.pdf", "media/a.webp"]
    assert atts[0].mime_type == "application/pdf"
    assert atts[0].original_name == "b.pdf"


async def test_get_attachments_scoped_to_code(db_pool):
    a = await _insert_media(db_pool, "media/a.webp")
    await _attach(db_pool, "bot_added", a, 0)

    repo = MessageRepository(db_pool)
    assert await repo.get_attachments(WELCOME) == []
    assert len(await repo.get_attachments("bot_added")) == 1


async def test_attachment_removed_when_media_deleted(db_pool):
    a = await _insert_media(db_pool, "media/a.webp")
    await _attach(db_pool, WELCOME, a, 0)

    async with db_pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute("DELETE FROM media WHERE id=%s", (a,))
        await conn.commit()

    repo = MessageRepository(db_pool)
    assert await repo.get_attachments(WELCOME) == []
