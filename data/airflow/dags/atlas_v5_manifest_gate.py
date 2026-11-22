"""P090 Airflow 3 Task SDK: real scheduled data-quality DAG, local pilot only.

Input is a read-only synthetic directory. Task returns tiny manifests through
Airflow XCom; no DB password, tenant token or raw personal data in XCom.
"""
from datetime import datetime, timedelta, timezone
import os

from airflow.sdk import dag, task

from atlas_batch import validate_batch, save_report

INPUT_DIR = os.environ.get("ATLAS_DATA_INPUT_DIR", "/opt/airflow/fixtures")
REPORT_DIR = os.environ.get("ATLAS_DATA_REPORT_DIR", "/opt/airflow/reports")
MANIFEST_NAME = os.environ.get("ATLAS_DATA_MANIFEST", "demo_manifest.json")


@dag(
    dag_id="atlas_v5_manifest_quality_pilot",
    schedule="0 4 * * *",
    start_date=datetime(2026, 11, 1, tzinfo=timezone.utc),
    catchup=False,
    max_active_runs=1,
    default_args={"retries": 2, "retry_delay": timedelta(minutes=5)},
    tags=["atlas", "v5", "data", "pilot"],
)
def manifest_quality():
    @task
    def validate() -> dict:
        return validate_batch(INPUT_DIR, MANIFEST_NAME)

    @task
    def publish(result: dict) -> str:
        return save_report(REPORT_DIR, result)

    publish(validate())


manifest_quality()
