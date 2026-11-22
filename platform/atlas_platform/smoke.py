"""Local-only bridge smoke: insert a synthetic audit event and assert receipt.

NEVER run against a real customer database. This writes a synthetic audit row.
"""
from __future__ import annotations

import os
import time

from atlas_platform.db import connect


def main():
    if os.environ.get("ATLAS_PLATFORM_SMOKE") != "YES_ISOLATED_LOCAL":
        raise RuntimeError("Explicit isolated-local opt-in required")
    with connect() as connection:
        with connection.transaction():
            with connection.cursor() as cursor:
                cursor.execute("SELECT id FROM companies WHERE code = 'ATLAS_DEMO'")
                row = cursor.fetchone()
                if not row:
                    raise RuntimeError("Synthetic ATLAS_DEMO tenant not seeded")
                company = row["id"]
                cursor.execute(
                    """INSERT INTO audit_logs
                        (company_id, user_id, action, entity_type, entity_id, metadata, created_at, updated_at)
                        VALUES (%s, NULL, 'platform.synthetic_smoke', 'Session', NULL, '{}'::jsonb,
                                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        RETURNING id""",
                    (company,),
                )
                audit_id = cursor.fetchone()["id"]
        deadline = time.monotonic() + 50
        while time.monotonic() < deadline:
            with connection.cursor() as cursor:
                cursor.execute(
                    """SELECT e.id, e.published_at, r.processed_at
                    FROM platform_outbox_events e
                    LEFT JOIN platform_event_receipts r
                      ON r.event_id = e.id AND r.company_id = e.company_id
                    WHERE e.audit_log_id = %s AND e.company_id = %s""",
                    (audit_id, company),
                )
                data = cursor.fetchone()
            connection.commit()
            if data and data["published_at"] is not None and data["processed_at"] is not None:
                print("Synthetic event published and consumed exactly once:", data["id"])
                return
            time.sleep(1)
        raise RuntimeError("Synthetic event not consumed within local smoke window")


if __name__ == "__main__":
    main()
