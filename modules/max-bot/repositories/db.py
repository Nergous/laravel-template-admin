"""aiomysql pool creation and transaction context manager"""
from contextlib import asynccontextmanager
from typing import AsyncIterator
import aiomysql

async def create_pool(
    *,
    host: str,
    port: int,
    user: str,
    password: str,
    database: str,
    minsize: int = 2,
    maxsize: int = 10,
) -> aiomysql.Pool:
    return await aiomysql.create_pool(
        host=host,
        port=port,
        user=user,
        password=password,
        db=database,
        minsize=minsize,
        maxsize=maxsize,
        autocommit=False,
        charset="utf8mb4",
    )

@asynccontextmanager
async def in_transaction(pool: aiomysql.Pool) -> AsyncIterator[aiomysql.Cursor]:
    """Yield a cursor inside a transaction. Commits on clean exit, rolls back on exception."""
    async with pool.acquire() as conn:
        try:
            await conn.begin()
            async with conn.cursor(aiomysql.DictCursor) as cur:
                yield cur
            await conn.commit()
        except Exception:
            await conn.rollback()
            raise

@asynccontextmanager
async def read(pool: aiomysql.Pool, *, dict_cursor: bool = True) -> AsyncIterator[aiomysql.Cursor]:
    """Yield a cursor for a read-only query, then COMMIT to release the implicit transaction.
    
    The pool runs with ``autocommit=False``, so even a bare SELECT opens an InnoDB transaction. Returning the connection to the pool without ending that transaction pins a stale REPEATABLE-READ snapshot on it: the next caller that reuses the connection sees out-of-date rows. Always commit after a read.
    """
    async with pool.acquire() as conn:
        try:
            cur_factory = aiomysql.DictCursor if dict_cursor else aiomysql.Cursor
            async with conn.cursor(cur_factory) as cur:
                yield cur
            await conn.commit()
        except Exception:
            await conn.rollback()
            raise

async def execute(pool_or_cur, sql: str, params=()) -> int:
    """Run a single write statement and return ``lastrowid``.

    Accepts either a pool (acquires its own connection and commits — a standalone
    unit of work) or an existing cursor (the statement joins the caller's open
    transaction and is *not* committed here). This lets a repository call be used
    both standalone and as one step of a larger atomic block.
    """
    if isinstance(pool_or_cur, aiomysql.Pool):
        async with pool_or_cur.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(sql, params)
                rowid = cur.lastrowid
            await conn.commit()
        return int(rowid or 0)
    await pool_or_cur.execute(sql, params)
    return int(pool_or_cur.lastrowid or 0)
