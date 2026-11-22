"""Contract tests have no external dependencies."""
import json
import sys
import unittest
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from atlas_platform.events import canonical_bytes, make_event, sha256, validate_event


def sample():
    return {
        "version": 1,
        "event_id": 42,
        "company_id": 2,
        "event_type": "stock.adjusted",
        "payload": {"entity_type": "Product", "entity_id": 7},
        "occurred_at": "2026-11-22T18:00:00+00:00",
    }


class EventContractTest(unittest.TestCase):
    def test_minimized_canonical_message(self):
        event = sample()
        data = canonical_bytes(event)
        self.assertEqual(json.loads(data), event)
        self.assertEqual(len(sha256(event)), 64)
        self.assertNotIn("customer_email", data.decode("utf-8"))
        self.assertEqual(data, canonical_bytes(dict(reversed(list(event.items())))))

    def test_build_from_database_row_drops_untrusted_fields(self):
        event = make_event({
            "id": 42, "company_id": 2, "event_type": "stock.adjusted",
            "payload": {"entity_type": "Product", "entity_id": 7},
            "occurred_at": datetime(2026, 11, 22, 18, tzinfo=timezone.utc),
            "sensitive_metadata": {"password": "never publish"},
        })
        self.assertEqual(set(event), {"version", "event_id", "company_id", "event_type", "payload", "occurred_at"})
        self.assertNotIn("password", canonical_bytes(event).decode("utf-8"))

    def test_wrong_tenant_and_unexpected_personal_data_rejected(self):
        for key, value in [
            ("company_id", 0), ("company_id", "2"), ("company_id", True),
            ("event_id", -1), ("version", 2), ("occurred_at", 4),
        ]:
            event = sample()
            event[key] = value
            with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                validate_event(event)
        event = sample()
        event["payload"]["email"] = "not-allowed@example.test"
        with self.assertRaises(ValueError):
            validate_event(event)
        event = sample()
        event["password"] = "secret"
        with self.assertRaises(ValueError):
            validate_event(event)

    def test_large_event_rejected(self):
        event = sample()
        event["event_type"] = "a" * 300
        with self.assertRaises(ValueError):
            canonical_bytes(event)


if __name__ == "__main__":
    unittest.main()
