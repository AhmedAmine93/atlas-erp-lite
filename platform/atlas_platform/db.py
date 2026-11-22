"""Local-only database connection. No production passwords committed."""
import os

import psycopg


def connect():
    return psycopg.connect(
        host=os.environ.get("DB_HOST", "db"),
        port=int(os.environ.get("DB_PORT", "5432")),
        dbname=os.environ.get("DB_NAME", "atlas"),
        user=os.environ["DB_USER"],
        password=os.environ["DB_PASSWORD"],
        connect_timeout=8,
        application_name="atlas_platform_local",
        row_factory=psycopg.rows.dict_row,
    )
