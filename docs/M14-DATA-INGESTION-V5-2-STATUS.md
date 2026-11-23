# M14 — Data & Ingestion (branche de travail)

Base: `dev` at `4a9f5e27d46a156465c523c069dd85a76005b94b`.
Specifications: M14 V5.1, RM-M14 V5.2; 7 submenus.

## Implementation inventory

- **M14.01 sources**: existing catalogue, schema registry and API; missing published owner/consent lifecycle.
- **M14.02 CSV batches**: existing bounded SHA-256 ingestion, dedup and row-count summary; missing rejected row provenance, signed amount manifest and selectable replay.
- **M14.03 pipelines**: additive versioned declarative registry; NOT a running ETL scheduler, Kafka consumer or DAG designer.
- **M14.04 quality**: declarative versioned quality-rule registry; NOT an automatic quality evaluation engine.
- **M14.05 lineage**: declarative provenance registry; NOT automatically derived from transforms.
- **M14.06 reconciliation**: declarative comparison records; NOT server-computed trusted reconciliation.
- **M14.07 lakehouse**: declarative dataset catalogue; NOT MinIO/S3 Bronze/Silver/Gold storage.

The five new registries intentionally remain **draft**. They must not be marked as
complete or made navigable as operational modules until execution, validation,
API tests and business acceptance are implemented.

## Security model

All new routes use the existing `auth:sanctum`, `tenant` middleware
and server-side admin gate. PostgreSQL migration creates `company_id` FKs,
unique tenant/code/version keys, indexes and FORCE RLS tenant policies. Runtime
tenant context comes from the validated user membership, not request JSON.
Only JSON config keys from explicit per-domain allowlists are accepted; no
code, arbitrary SQL, connectors, passwords or tokens are executed.

## Required checks before merge

```sh
cd backend
php artisan migrate --pretend
php artisan migrate
php artisan test --filter Data
cd ../frontend
npm ci
npm run build
```

Run with real PostgreSQL under separate migrator/runtime roles and verify RLS
on both tenant A and B. Validate migration down on a throwaway copy only.
Do not deploy live connectors without sandbox approvals.
