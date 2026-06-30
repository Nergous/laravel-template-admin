"""Validates messages.json against messages.schema.json.

The registry is a shared Python ↔ Laravel contract, so we keep its shape under test.
Validation without a jsonschema dependency: we interpret the subset of the schema that
is actually used (type/required/additionalProperties/pattern/minItems, etc.).
This way the schema stays the machine truth, and the test catches a registry that diverges from it.
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

    assert isinstance(registry, list), "messages.json must be an array"
    assert len(registry) >= schema["minItems"]

    item_schema = schema["items"]
    required = item_schema["required"]
    props = item_schema["properties"]
    code_pattern = re.compile(props["code"]["pattern"])

    for i, item in enumerate(registry):
        assert isinstance(item, dict), f"entry {i} is not an object"

        # additionalProperties: false — no extra keys.
        extra = set(item) - set(props)
        assert not extra, f"entry {i}: extra keys {extra}"

        for key in required:
            assert key in item, f"entry {i}: missing required key {key!r}"
            assert isinstance(item[key], str), f"entry {i}: {key!r} must be a string"
            assert item[key].strip(), f"entry {i}: {key!r} must not be empty"

        assert code_pattern.fullmatch(item["code"]), \
            f"entry {i}: code {item['code']!r} does not match {props['code']['pattern']}"


def test_registry_codes_unique():
    codes = [item["code"] for item in _load(_REGISTRY)]
    duplicates = {c for c in codes if codes.count(c) > 1}
    assert not duplicates, f"duplicate codes in messages.json: {duplicates}"
