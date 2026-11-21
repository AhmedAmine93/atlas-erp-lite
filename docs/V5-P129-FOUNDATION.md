# ATLAS ONE V5 — P129 : Référentiel hôtelier

**Scope of this independent foundation PR:** P129.01. The canonical V5 plan calls for a larger six-step phase; this is **not** completion of the entire phase.

## Implemented

Admin-only hotel and room registries: tenant-owned hotels; room code unique per hotel, including room 101 in multiple hotels, safe composite company/hotel foreign key, timezone IANA validation, limited availability states. No actual PMS/housekeeping workflow, room rate or booking. Tests: tenant A/B, room 101 and foreign hotel denial. Follow-up P129.02–.06 needs hotel-local business date, occupancy, full room states, roles and multi-timezone scenarios.

## PostgreSQL / security

- New tables: `hotel_properties, hotel_rooms` in an additive Laravel migration. No existing table dropped or renamed; rollback only removes new tables.
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
