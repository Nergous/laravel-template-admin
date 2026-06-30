"""Валидирует messages.json против messages.schema.json.

Реестр — общий контракт Python ↔ Laravel, поэтому его форму держим под тестом.
Проверка без зависимости jsonschema: интерпретируем подмножество схемы, которое
реально используется (type/required/additionalProperties/pattern/minItems и т.п.).
Так схема остаётся машинной правдой, а тест ловит расхождение реестра с ней.
"""
import json
import re
from pathlib import Path

import pytest

_MODULE_ROOT = Path(__file__).resolve().parent.parent
_REGISTRY = _MODULE_ROOT / "messages.json"
_SCHEMA = _MODULE_ROOT / "messages.schema.json"


def _load(path):
    return json.loads(path.read_text(encoding="utf-8"))


def test_registry_matches_schema():
    registry = _load(_REGISTRY)
    schema = _load(_SCHEMA)

    assert isinstance(registry, list), "messages.json должен быть массивом"
    assert len(registry) >= schema["minItems"]

    item_schema = schema["items"]
    required = item_schema["required"]
    props = item_schema["properties"]
    code_pattern = re.compile(props["code"]["pattern"])

    for i, item in enumerate(registry):
        assert isinstance(item, dict), f"запись {i} не объект"

        # additionalProperties: false — никаких лишних ключей.
        extra = set(item) - set(props)
        assert not extra, f"запись {i}: лишние ключи {extra}"

        for key in required:
            assert key in item, f"запись {i}: нет обязательного ключа {key!r}"
            assert isinstance(item[key], str), f"запись {i}: {key!r} должен быть строкой"
            assert item[key].strip(), f"запись {i}: {key!r} не должен быть пустым"

        assert code_pattern.fullmatch(item["code"]), \
            f"запись {i}: code {item['code']!r} не подходит под {props['code']['pattern']}"


def test_registry_codes_unique():
    codes = [item["code"] for item in _load(_REGISTRY)]
    duplicates = {c for c in codes if codes.count(c) > 1}
    assert not duplicates, f"дублирующиеся коды в messages.json: {duplicates}"
