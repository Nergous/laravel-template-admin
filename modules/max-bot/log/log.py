"""Logging helpers for the bot. Single-line format for grep-friendliness."""
import logging
import os
import sys

_CONFIGURED = False

def _configure_root() -> None:
    global _CONFIGURED
    if _CONFIGURED:
        return
    # Windows console is often cp1252; Cyrillic in log messages would crash on emit.
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass

    level = os.getenv("LOG_LEVEL_BOT", "INFO").upper()
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(logging.Formatter(
        fmt="%(asctime)s.%(msecs)03d | %(levelname)-7s | %(name)-20s | %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    ))
    root = logging.getLogger()
    root.handlers.clear()
    root.addHandler(handler)
    root.setLevel(level)
    _CONFIGURED = True

def get_logger(name: str) -> logging.Logger:
    _configure_root()
    return logging.getLogger(name)