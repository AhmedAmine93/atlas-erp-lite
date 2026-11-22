"""PostgreSQL transactional-outbox publisher; at-least-once delivery.

If Kafka acknowledges but PostgreSQL update fails, the row is resent.
Consumer receipts make this safe. No HTTP calls or business mutations here.
"""
from __future__ import annotations

import logging
import os
import time

from kafka import KafkaProducer

from atlas_platform.db import connect
from atlas_platform.events import canonical_bytes, make_event

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
LOG = logging.getLogger(__name__)
SQL_SELECT = """
SELECT id, company_id, event_type, payload, occurred_at
FROM platform_outbox_events
WHERE published_at IS NULL
ORDER BY id
LIMIT 1
FOR UPDATE SKIP LOCKED
"""


def publish_one(connection, producer, topic: str) -> bool:
    with connection.transaction():
        with connection.cursor() as cursor:
            cursor.execute(SQL_SELECT)
            row = cursor.fetchone()
            if row is None:
                return False
            event = make_event(row)
            data = canonical_bytes(event)
            # Key by tenant and event; duplicates retain the same event_id.
            producer.send(topic, key=str(row["company_id"]).encode("ascii"), value=data).get(timeout=20)
            cursor.execute(
                "UPDATE platform_outbox_events SET published_at = CURRENT_TIMESTAMP, "
                "attempts = attempts + 1, last_error = NULL WHERE id = %s AND published_at IS NULL",
                (row["id"],),
            )
            if cursor.rowcount != 1:
                raise RuntimeError("Outbox row changed while locked")
            return True


def run() -> None:
    producer = KafkaProducer(
        bootstrap_servers=os.environ.get("KAFKA_BOOTSTRAP_SERVERS", "kafka:9092").split(","),
        acks="all", retries=5, max_block_ms=15000,
        request_timeout_ms=25000,
    )
    topic = os.environ.get("KAFKA_TOPIC", "atlas.audit.v1")
    while True:
        try:
            with connect() as connection:
                while True:
                    if not publish_one(connection, producer, topic):
                        time.sleep(1)
        except KeyboardInterrupt:
            break
        except Exception:
            LOG.exception("Publish failed; outbox remains pending for retry")
            time.sleep(5)
    producer.flush(timeout=10)
    producer.close()


if __name__ == "__main__":
    run()
