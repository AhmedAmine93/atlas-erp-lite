# ATLAS ONE — F10–F12 : diagnostics de support N3

## Scope

F10: stack integration review gate and tenant-safe history.  
F11: allowlisted read-only diagnostic service and hardened API.  
F12: operational UI, permission matrix, quota and regression tests.

### Safety contract

The API executes **exactly three built-in checks**:

| Name | Scope | Returned fields |
|---|---|---|
| \`database\` | Constant \`SELECT 1\` query | \`reachable\` (boolean) |
| \`cache\` | Fixed cache key, GET only | \`readable\` (boolean) |
| \`tenant_incidents\` | Aggregates of current tenant's incidents | \`active_count\`, \`resolution_sla_breached_count\` |

No arbitrary SQL, shell, HTTP URL, file reads, user-supplied command, data export or raw logs. The cache check is a read-path probe, not a full write/replication health assessment. These checks do not attempt to prove end-to-end operational availability or a pentest-level security guarantee.

### Authorization and context

Only \`admin\` or \`support_n3\` in the **active company** may run or inspect diagnostics. \`support_n2\`, \`reader\`, \`stock\`, \`commercial\` are forbidden, even if their legacy global role was admin. The incident must belong to the active company. Running checks additionally requires \`escalation_level = 3\` and a non-closed incident. No data crosses tenants.

POST endpoint has a dedicated per-user/per-tenant quota, before service execution. Additional JSON fields are rejected with HTTP 422; only a named, predefined \`check\` is accepted.

### Audit, privacy and lifecycle

Each run is recorded in \`support_diagnostic_runs\` with tenant ID (set server-side), incident ID, actor, predefined check name, status, runtime and timestamp. An audit event tracks the action but **no query text, token, cache value, exception content, connection detail, incident description or raw customer data** is saved in the diagnostic record.

Failures return \`status: degraded\` with no internal exception message; server-side exception reporting still requires secure logging and secret scrubbing.

### Endpoints

- \`GET /api/support/incidents/{id}/diagnostics\`: recent 20 sanitized executions.
- \`POST /api/support/incidents/{id}/diagnostics\`: \`{"check":"database"}\` or \`cache\` or \`tenant_incidents\`.

### Validation

Tests include N3 access in A/read-only in B, N2 denial, unknown check/payload denial, cross-tenant 404, not-escalated/closed 409, tenant-isolated counters, and sanitized audit history. Run the complete Docker/PostgreSQL, HTTP smoke and Playwright suite.

## Deliberately not included

External monitoring adapters, direct database diagnostic tables, arbitrary query execution, Redis Cluster health, SQL explain-plan parsing, slow query text, log ingestion, scheduled probes, and automatic remediation. Those require separate read-only connectors and approval of each specific data source, entitlement and redaction strategy.
