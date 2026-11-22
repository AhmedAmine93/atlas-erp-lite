"""Publisher and consumer unit tests avoid requiring a running broker."""
import sys
import unittest
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from atlas_platform.events import canonical_bytes, make_event


class FakeCursor:
    def __init__(self, row):
        self.row = row
        self.executed = []
        self.rowcount = 1

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False

    def execute(self, sql, args=None):
        self.executed.append((sql, args))

    def fetchone(self):
        return self.row


class FakeConnection:
    def __init__(self, row):
        self.cursor_instance = FakeCursor(row)

    def cursor(self):
        return self.cursor_instance

    @contextmanager
    def transaction(self):
        yield


class FakeFuture:
    def get(self, timeout):
        self.timeout = timeout
        return object()


class FakeProducer:
    def __init__(self):
        self.sent = []
    def send(self, topic, key, value):
        self.sent.append((topic, key, value))
        return FakeFuture()


class PublisherTest(unittest.TestCase):
    def test_publish_one_waits_for_ack_and_marks_row_published(self):
        from atlas_platform.publisher import publish_one
        row = {
            "id": 33, "company_id": 9, "event_type": "order.validated",
            "payload": {"entity_type": "Order", "entity_id": 27},
            "occurred_at": datetime(2026, 11, 22, 18, tzinfo=timezone.utc),
        }
        conn, producer = FakeConnection(row), FakeProducer()
        self.assertTrue(publish_one(conn, producer, "atlas.audit.v1"))
        self.assertEqual(len(producer.sent), 1)
        self.assertEqual(producer.sent[0][1], b"9")
        self.assertEqual(producer.sent[0][2], canonical_bytes(make_event(row)))
        self.assertIn("UPDATE platform_outbox_events", conn.cursor_instance.executed[-1][0])

    def test_empty_outbox_does_not_produce(self):
        from atlas_platform.publisher import publish_one
        conn, producer = FakeConnection(None), FakeProducer()
        self.assertFalse(publish_one(conn, producer, "atlas.audit.v1"))
        self.assertEqual(producer.sent, [])


if __name__ == "__main__":
    unittest.main()
