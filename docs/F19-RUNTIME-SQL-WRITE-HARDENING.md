# ATLAS ONE — F19: reduce runtime SQL write permissions

**Status:** draft. Stacked on F18 PR #27, F17 PR #26, F16 PR #25, RC1 PR #24. **Not a deployment**.

## Security objective

F16 limited the PostgreSQL runtime identity to DML (no DDL). F17 proved the actual authenticated runtime can execute the ERP; F18 proved tenant isolation only for the narrow F15 N3 reader. F19 applies **incremental write least privilege** to the authenticated application's table grants. Runtime retains the same positive business workflows: sales, support incident transitions, accounting-like document state transitions, audit/event creation.

### Privilege delta

| Database tables | Runtime SELECT | INSERT | UPDATE | DELETE |
|---|---|---|---|---|
| `roles` | Allowed | **Revoked** | **Revoked** | **Revoked** |
| `audit_logs`, `stock_movements`, `support_incident_events`, `support_diagnostic_runs` | Allowed | Allowed | **Revoked** | **Revoked** |
| `companies`, `support_incidents` | Allowed | Allowed | Allowed | **Revoked** |
| Other previously granted F16 application tables | Unchanged | Unchanged | Unchanged | Unchanged |
| `migrations` | Denied since F16 | Denied | Denied | Denied |

The schema-owner migration role retains full privileges; the application role is prohibited from modifying historic audit/event/stock records, deleting companies or incidents, or changing system role definitions.

## Implementation

- `backend/database/migrations/2026_11_21_000009_harden_runtime_write_privileges.php` revokes explicit grants and checks effective privileges to catch unexpected inheritance/PUBLIC access. The down migration restores **only** the baseline F16 grants and never drops a shared PostgreSQL role.
- `scripts/verify-f19.sh` opens real password-authenticated PostgreSQL connections (from F17's ephemeral CI secret) to test positive SELECT/INSERT, negative UPDATE/DELETE and immutable role definitions, checking exact SQLSTATE `42501`.
- `scripts/verify-f17.sh` runs F19 while the ephemeral runtime password is still in scope. Existing HTTP/Playwright tests follow against the restricted application identity.
- `scripts/rehearse-f14.sh` excludes the F19 migration from the pre-F14 historical fixture, while running it during the actual upgrade.
- Workflow lint validates the new script.

## Security limitations and release blockers

1. This is **not full row-level tenant isolation**. `atlas_app_runtime` still has raw SELECT/DML access on several tenant tables, and F16's transitional incident runtime RLS policy remains permissive. The dedicated reader protection tested in F18 does not extend automatically to all runtime queries.
2. Revoked history modifications are defense in depth, not a tamper-proof append-only ledger. A compromised owner or system admin could modify history; business correction should use compensating entries and independent audit storage.
3. Verify every business endpoint and worker under the actual runtime identity; specifically consider disabled/disabled-by-role operations and future job queues.
4. CI uses synthetic local Docker data. Production Compose, deployment scripts, network access, real accounts and `main` are not changed.
5. Hosted staging, password rotation, restore testing, threat modeling, independent DBA/security reviews and explicit release approval remain required.

## Go/No-Go checklist

- [ ] PostgreSQL migration runs on clean and synthetic upgraded DB
- [ ] Authenticated F19 SQL permission checks pass with expected SQLSTATE
- [ ] SQL roles read-only and journals append-only as specified
- [ ] Positive audit INSERT and retained incident UPDATE pass
- [ ] F17/F18 and complete PHP/HTTP/Playwright suites pass on exact PR head SHA
- [ ] Security review confirms the remaining full-tenant RLS blocker
- [ ] CI evidence archived; **no real-client staging/production deployment**

Until the items are verified, keep the PR in draft with **NO-GO** for production.
