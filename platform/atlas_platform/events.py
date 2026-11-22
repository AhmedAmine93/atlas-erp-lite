"""Strict, versioned, non-sensitive event envelope for ATLAS audit dispatch."""
from __future__ import annotations

import hashlib
import json

TOPIC = "atlas.audit.v1"
ALLOWED_PAYLOAD_KEYS = frozenset(("entity_id", "entity_type"))
ALLOWED_ENVELOPE_KEYS = frozenset(("version", "event_id", "company_id", "event_type", "payload", "occurred_at"))


def make_event(row: dict) -> dict:
    event = {
        "version": 1,
        "event_id": row["id"],
        "company_id": row["company_id"],
        "event_type": row["event_type"],
        "payload": row["payload"],
        "occurred_at": row["occurred_at"].isoformat() if hasattr(row["occurred_at"], "isoformat") else row["occurred_at"],
    }
    validate_event(event)
    return event


def validate_event(value: object) -> dict:
    if not isinstance(value, dict) or set(value) != ALLOWED_ENVELOPE_KEYS:
        raise ValueError("Unexpected event fields")
    if value["version"] != 1 or isinstance(value["version"], bool):
        raise ValueError("Unsupported event version")
    for key in ("company_id", "event_id"):
        if type(value[key]) is not int or not (0 < value[key] < 2**63):
            raise ValueError("Invalid event identifier: " + key)
    if not isinstance(value["event_type"], str) or not (1 <= len(value["event_type"]) <= 255):
        raise ValueError("Invalid event type")
    payload = value["payload"]
    if not isinstance(payload, dict) or set(payload) != ALLOWED_PAYLOAD_KEYS:
        raise ValueError("Unexpected payload fields")
    if not isinstance(payload["entity_type"], str) or len(payload["entity_type"]) > 255:
        raise ValueError("Invalid entity type")
    entity_id = payload["entity_id"]
    if entity_id is not None and (type(entity_id) is not int or entity_id < 1):
        raise ValueError("Invalid entity ID")
    if not isinstance(value["occurred_at"], str) or not (10 <= len(value["occurred_at"]) <= 64):
        raise ValueError("Invalid occurrence time")
    return value


def canonical_bytes(value: dict) -> bytes:
    validate_event(value)
    data = json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    if len(data) > 4096:
        raise ValueError("Event exceeds 4KB")
    return data


def sha256(value: dict) -> str:
    return hashlib.sha256(canonical_bytes(value)).hexdigest()
