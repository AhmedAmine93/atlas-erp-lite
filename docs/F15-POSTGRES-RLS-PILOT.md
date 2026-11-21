# ATLAS ONE — F15 PostgreSQL RLS pilot (N3 incident aggregate)

## Rationale

F14 introduced composite tenant foreign keys, which prevent a record in
Company A from referring to a record in Company B. They do **not** prevent
reading rows in another company with a raw SQL query. F15 tests an additional
database-level barrier with PostgreSQL Row-Level Security (RLS).

## Current architecture limitation

The application's main database connection uses the same login as its
migrations/table ownership. PostgreSQL table owners typically bypass RLS.
Turning on FORCE RLS for all ERP tables under this account would interrupt
migrations, multi-company administration, and cross-table operations.

**F15 is a restricted read-path pilot, not an end-to-end ERP RLS rollout.**
The next phase must separate migration owner, application runtime, and
background-worker credentials before wider enforcement.

## Implemented pilot

- Dedicated PostgreSQL role \`atlas_f15_incident_reader\`, NOLOGIN,
  NOINHERIT, NOBYPASSRLS, no DB creation, no role creation or superuser.
- Grants: \`USAGE\` on \`public\` and \`SELECT\` only on \`support_incidents\`.
  No table writes and no access to \`users\`, \`invoices\`, \`customers\`,
  or unrelated application tables.
- The \`support_incidents\` table has RLS enabled, with a SELECT policy
  scoped to this reader role. A missing tenant context returns **zero rows**.
- \`IncidentRlsReadProbe::metrics()\` uses Laravel's transaction wrapper.
  It enters the restricted role via \`SET LOCAL ROLE\`, sets
  \`atlas.tenant_id\` using a parameterized \`set_config(..., true)\`,
  performs a **fixed aggregate SELECT**, then explicitly restores the
  PostgreSQL role and previous context in \`finally\`.
- The existing N3 diagnostic endpoint \`tenant_incidents\` delegates to
  this scoped SQL reader. Endpoint role checks, per-user rate limiting,
  incident escalation restrictions, audit and tenant middleware remain.
- SQLite tests use existing Eloquent-scoped fallback and are explicitly
  **not** evidence of RLS enforcement.

## Threat model and limitations

- A SQL login with ownership/superuser privileges can still bypass RLS.
  This current deployment uses such a login for its normal ERP operations.
- An attacker with ability to execute arbitrary SQL as this owner can
  change PostgreSQL session settings or bypass the restricted reader. RLS
  cannot compensate for excessive connection privileges.
- The specialized reader has no direct login credentials, but the owner
  can \`SET ROLE\` into it. Only an allowlisted code path should do so.
- Query values and \`company_id\` must come from authenticated, validated
  tenant context—not from JSON inputs or a free-form SQL editor.
- Cached connections are sensitive: transaction-local role/context plus
  explicit restoration is necessary. Tests cover role/context restoration.
- RLS does not replace application authorization, composite FKs, audits,
  encryption, backup and restoration, or independent penetration testing.
- General RLS policies for all ERP tables are deliberately **not** enabled
  in this phase. A migration/runtime role split is required first.

## Acceptance tests

\`IncidentRlsPilotTest\` verifies on PostgreSQL:

1. Reader role sees **0 incidents** with no tenant context.
2. Reader cannot read \`users\`, or delete incidents (SQLSTATE 42501).
3. Two fictional companies have different incident counts.
4. Context is restored after each diagnostic transaction.
5. Existing API N3 authorization and incident/tenant restrictions still apply.
6. Full F01–F14 CI remains green, including synthetic F14 migration/restore.

## Next steps

F16: introduce a distinct runtime DB role, make roles and GUC boundaries
explicit, and evaluate broader RLS integration in staging. Only after
security/DBA review, performance tests and migration rehearsal should
RLS be considered for sensitive ERP writes.

F15 remains a draft PR stacked on F14, with no merge to \`main\` or
deployment to production.
