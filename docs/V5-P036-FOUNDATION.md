# ATLAS ONE V5 — P036 : Demandes d'achat

**Scope of this independent foundation PR:** P036.01. The canonical V5 plan calls for a larger six-step phase; this is **not** completion of the entire phase.

## Implemented

Admin-only purchase request draft registry: tenant-specific reference, nonnegative estimated amount in cents, status draft, requested_by authenticated user, unique company/reference. No approval, supplier consultation, purchase commitment, workflow or stock mutation. Tests: reader denied, tenant A/B isolation, duplicate and tampered company rejected. Follow-up P036.02–.06 must add quotation, delegation, approval, commitment, POs and concurrency.

## PostgreSQL / security

- New tables: `purchase_requests` in an additive Laravel migration. No existing table dropped or renamed; rollback only removes new tables.
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
