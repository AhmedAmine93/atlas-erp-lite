# ATLAS ONE V5 — Data ingestion quality observability (isolated increment)

## Purpose

Add **read-only quality evidence** to the existing `GET /api/data/ingestion-batches` page without changing the ingestion manifest, source validation, row parser, persistent schema, or any hotel/minibar surface.

Each paginated batch includes a `quality_summary` derived from **persisted** `data_ingested_csv_rows` of the verified tenant. No raw file, external IDs, guest data or credentials appear in the summary.

| Field | Semantics |
|---|---|
| `state` | `PENDING` before `processed`; otherwise `CONSISTENT` or `ROW_COUNT_MISMATCH` |
| `actual_rows` | `COUNT(*)` over persisted CSV rows of this batch, including zero |
| `expected_rows` | Immutable batch manifest expected row count |
| `row_count_matches_manifest` | `null` before processing; boolean afterwards |
| `amount_total_cents` | SQL `SUM(amount_cents)`, in cents; no currency conversion |
| `positive_rows`, `negative_rows`, `zero_rows` | Signed row counts from committed data |

## Design and limits

- **No migrations:** change only `DataIngestionBatchController::index`, a targeted feature test, and this documentation.
- **Tenant isolation:** existing authenticated tenant middleware plus `Gate::authorize('admin')`, Eloquent tenant scope for batches, and explicit `company_id` filtering of aggregation rows. An admin of another tenant sees only their company's page.
- **Bounded queries:** pagination limits at most 20 batch IDs. One aggregate SQL query groups persisted records for that page; no per-batch N+1 queries.
- **Non-destructive:** the service never updates batch statuses or rows. A processed batch is not considered `CONSISTENT` unless its actual persisted row count equals its manifest.
- **Quality is intentionally limited:** row-count match and signed totals are not a substitute for source completeness, semantic validation, duplicate matching across batches, or reconciliation with financial ledgers.
- **Data classified sensitive:** administrators may see aggregation values; no unrestricted tenant-wide cross-company dashboard.

## Acceptance

`backend/tests/Feature/DataIngestionBatchQualityTest.php` tests actual persisted positive/negative/zero amounts, pending-versus-processed state, deliberately missing rows, anonymous/reader denial and isolation between tenant A and tenant B. Run existing data ingestion tests and the full GitHub CI before any merge into `dev`.

## Non-overlap with Work

Work is implementing **Wave 02 hotel / housekeeping / minibar** on `feature/v5-hotel-wave-02` via PR #78. This data quality improvement exclusively touches the Data ingestion controller and a new test/document; it must remain a separate PR until CI green and should never alter Work's branch, `main`, or production.
