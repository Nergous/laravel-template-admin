"""Process-global async rate limiter for outbound MAX API calls.

Every worker that talks to the MAX API (executor, chats_checker, …) shares one
`Bot` instance and therefore one upstream rate budget. Without coordination each
worker only spaced *its own* calls (`sleep(1/hz)`), so N workers together could
issue up to N×hz requests/sec and trip the server-side limit.

A single shared `RateLimiter` fixes that: `acquire()` serialises on a lock and
hands out permits no closer together than ``1/hz`` seconds, regardless of how
many workers are calling. It behaves like a leaky bucket of capacity 1 — after
an idle gap one call goes through immediately, then calls are paced.
"""
import asyncio

class RateLimiter:

    _min_interval: float
    """Minimum time between calls, in seconds."""

    _lock: asyncio.Lock
    """Protects `_next_allowed`."""

    _next_allowed: float
    """When the next call is allowed, in seconds."""

    def __init__(self, hz: float):
        self._min_interval = 1.0 / max(1.0, float(hz))
        self._lock = asyncio.Lock()
        self._next_allowed = 0.0

    async def acquire(self) -> None:
        """Block until the next call is allowed by the global pace."""
        async with self._lock:
            loop = asyncio.get_running_loop()
            now = loop.time()
            wait = self._next_allowed - now
            if wait > 0.0:
                await asyncio.sleep(wait)
                now = self._next_allowed
            self._next_allowed = now + self._min_interval
