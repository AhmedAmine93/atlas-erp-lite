# ATLAS ONE V5 — P049 : Patrimoine technique GMAO

**Scope of this independent foundation PR:** P049.01–.02. The canonical V5 plan calls for a larger six-step phase; this is **not** completion of the entire phase.

## Implemented

Admin-only site and asset registry: codes unique per company, composite foreign key company/site prevents cross-tenant reference, enumerated equipment statuses, site isolation. Tests include cross-tenant site reference 404 and tenant-specific unique codes. Follow-up P049.03–.06: hierarchies, locations, change history, fuller site isolation and permissions.

## PostgreSQL / security

- New tables: `maintenance_sites, maintenance_assets` in an additive Laravel migration. No existing table dropped or renamed; rollback only removes new tables.
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
