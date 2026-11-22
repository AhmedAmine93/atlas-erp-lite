"""Idempotent audit analytics consumer.

The Kafka message is NOT trusted: it must match the persisted outbox event,
including tenant, event type and canonical payload. The receipt and counter
are written in the SAME PostgreSQL transaction before the Kafka offset commit.
"""
from __future__ import annotations

import json
import logging
import os
import time

from kafka import KafkaConsumer

from atlas_platform.db import connect
from atlas_platform.events import canonical_bytes, make_event, sha256, validate_event

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
LOG = logging.getLogger(__name__)

LOOKUP = """
SELECT id, company_id, event_type, payload, occurred_at
FROM platform_outbox_events WHERE id = %s AND company_id = %s
"""


def process_event(connection, event: dict) -> bool:
    validate_event(event)
    with connection.transaction():
        with connection.cursor() as cursor:
            cursor.execute(LOOKUP, (event["event_id"], event["company_id"]))
            row = cursor.fetchone()
            if row is None or canonical_bytes(make_event(row)) != canonical_bytes(event):
                raise ValueError("Event not present in authoritative outbox or contents differ")
            cursor.execute(
                """
                INSERT INTO platform_event_receipts
                  (company_id, event_id, payload_sha256, event_type, processed_at)
                VALUES (%s, %s, %s, %s, CURRENT_TIMESTAMP)
                ON CONFLICT (company_id, event_id) DO NOTHING
                RETURNING id
                """,
                (event["company_id"], event["event_id"], sha256(event), event["event_type"]),
            )
            if cursor.fetchone() is None:
                return False
            cursor.execute(
                """
                INSERT INTO platform_event_counters
                    (company_id, event_type, processed_count, last_processed_at)
                VALUES (%s, %s, 1, CURRENT_TIMESTAMP)
                ON CONFLICT (company_id, event_type) DO UPDATE SET
                    processed_count = platform_event_counters.processed_count + 1,
                    last_processed_at = EXCLUDED.last_processed_at
                """,
                (event["company_id"], event["event_type"]),
            )
            return True


def run() -> None:
    consumer = KafkaConsumer(
        os.environ.get("KAFKA_TOPIC", "atlas.audit.v1"),
        bootstrap_servers=os.environ.get("KAFKA_BOOTSTRAP_SERVERS", "kafka:9092").split(","),
        group_id=os.environ.get("KAFKA_GROUP_ID", "atlas-platform-analytics-v1"),
        enable_auto_commit=False,
        auto_offset_reset="earliest",
        consumer_timeout_ms=1000,
        max_poll_records=50,
    )
    while True:
        try:
            with connect() as connection:
                for record in consumer:
                    try:
                        if len(record.value) > 4096:
                            raise ValueError("Event too large")
                        event = validate_event(json.loads(record.value.decode("utf-8")))
                        # Reject another tenant's partition key as a contract violation.
                        if record.key != str(event["company_id"]).encode("ascii"):
                            raise ValueError("Kafka key does not match company")
                    except (UnicodeError, ValueError, TypeError, KeyError):
                        LOG.exception("Poison message skipped (no business state modified)")
                        consumer.commit()
                        continue
                    try:
                        process_event(connection, event)
                        consumer.commit()
                    except Exception:
                        LOG.exception("Database unavailable or forged event; will retry")
                        connection.rollback()
                        time.sleep(5)
                        break
        except KeyboardInterrupt:
            break
        except Exception:
            LOG.exception("Consumer connection failed; retrying")
            time.sleep(5)
    consumer.close()


if __name__ == "__main__":
    run()
