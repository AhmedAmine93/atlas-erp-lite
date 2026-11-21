# ATLAS ONE V5 — P089 : Catalogue de sources Data

**Scope of this independent foundation PR:** P089.01. The canonical V5 plan calls for a larger six-step phase; this is **not** completion of the entire phase.

## Implemented

Admin-only system-of-record catalog with per-company source code, type allowlist, classification and draft state. Deliberately excludes credentials, URL, connection strings, executable pipelines and ingestion. Tests: tenant isolation, secret field rejection and duplicate code. Follow-up P089.02–.06: canonical schemas, API/SQL/CDC/CSV ingestion, idempotency, failures and volume tests. Secrets must be handled by a dedicated vault in later phases.

## PostgreSQL / security

- New tables: `data_source_registry` in an additive Laravel migration. No existing table dropped or renamed; rollback only removes new tables.
- Eloquent `BelongsToTenant` global scope and create-time tenant stamping from verified server session.
- Every exposed endpoint uses existing authenticated, throttled `tenant` middleware and `Gate::authorize('admin')`. Non-admin business roles need an approved role matrix before authorization is expanded.
- No UI menu added before complete business workflow.
- Tests use synthetic data and deliberately try cross-company access.

## Acceptance gate

- [ ] PHPUnit on exact PR SHA passes including synthetic tenant tests.
- [ ] PostgreSQL migration and rollback verified without data loss in isolated CI.
- [ ] Laravel role and company constraints reviewed; no untrusted company_id accepted.
- [ ] Verify primary/foreign key, nullability, indexing, uniqueness and safe migrations.
- [ ] Consolidate independent migrations in sequence; re-run full CI on integrated SHA.

**Release NO-GO:** No automatic merge to main, no production deployment. Formal security review and staging evidence required.
