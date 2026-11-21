# ATLAS ONE — F20: transaction-local RLS for fourteen ERP tables

**Status:** draft/CI validation candidate, no deployment. Based on F19 PR #28, stacked on F18 #27, F17 #26, F16 #25 and RC1 #24. V4 traceability: M02.07, P018.03/P024.01–.03/P024.06.

## Scope of implementation

- `2026_11_21_000010_runtime_business_tenant_rls.php` enables PostgreSQL row-level security with an **explicit** policy for role `atlas_app_runtime` on:
  `customers`, `suppliers`, `products`, `quotes`, `quote_items`,
  `orders`, `order_items`, `invoices`, `stock_movements`,
  `audit_logs`, `establishments`, `support_incidents`,
  `support_incident_events`, `support_diagnostic_runs`.
  Each policy checks `company_id = NULLIF(current_setting('atlas.tenant_id', true),'')::bigint` in **USING** and **WITH CHECK**.
- Replaces F16's transitional permissive runtime policy **only** on `support_incidents`; keeps F15's more restrictive N3-reader policy.
- `TenantDatabaseContext::set` requires an active PostgreSQL transaction before setting the tenant GUC with `set_config(...,true)`.
- The `ResolveTenant` middleware validates membership, then wraps authenticated API handling in a transaction. Tenant context is reset before the transaction completes. The reset also accommodates nested test transactions.
- Authorized `tenant/switch` immediately rebinds the RLS context before recording its audit event. The public login endpoint opens its own small RLS-scoped transaction to write `auth.login` to `audit_logs` (it runs outside the tenant middleware).
- `verify-f20.sh` authenticates as the real runtime role within the disposable F17 CI PostgreSQL stack, checks the 14 policies and tests row isolation on `customers` and `support_incidents` for synthetic tenants A/B, including empty context, same-session re-use, allowed INSERT and rejected cross-tenant INSERT/UPDATE. The existing HTTP and Playwright smoke suite follows using the restricted role.
- F14 historical migration fixture excludes F20 and applies it only during the upgrade.

## Important security boundary

**RLS with a custom PostgreSQL GUC is not protection against arbitrary SQL execution by the application runtime.** A role that can execute arbitrary SQL can call `set_config('atlas.tenant_id', other_tenant, ...)` itself, then read rows of that tenant. Consequently F20 provides defense in depth against missing application query filters or accidental tenant-context mixing, **not** complete tenant security against SQL injection or full runtime compromise. Do not claim otherwise.

Further work must review a trustworthy tenant-claim channel (e.g. dedicated DB tenant roles/credentials or integrity-verified claims in a DB-owned SECURITY DEFINER function, with secrets not exposed to the runtime SQL account), threat-model SQL injection, and test privilege escalation. No proposed design may trust a client-supplied company ID alone.

The following shared/unscoped tables are **not** tenant RLS protected: `users`, `roles`, `companies`, `company_user`, `sessions`, job/cache tables, `document_sequences`. Authentication, membership, and tenant-specific numbering require separate review. PostgreSQL owner/migrator intentionally bypasses non-FORCE policies. Additional concerns: all background workers, long-lived connections, streaming responses, cron, exports, cross-service APIs, tenant revocation races, transaction lock contention, and cross-tenant admin functionality.

## Runtime invariants

1. Tenant ID comes only from verified server-side session membership, not a request payload.
2. Every RLS-protected API operation must use a database transaction and tenant context.
3. An unauthorized/switching user must not read the previous tenant's rows.
4. Missing context yields zero reads and denies writes for the runtime role.
5. Switching companies within one request changes RLS context before follow-up business writes.
6. Tenant GUC does not survive a completed transaction and must be reset for nested transactions.
7. Login audit must be inserted inside an explicit tenant-scoped transaction.
8. Owner-only schema migrations and maintenance remain segregated; never enable this as an unreviewed production migration.

## Acceptance and NO-GO

- [ ] Exact-head CI on the F20 PR passes PHP, F14 migration/restore, F17 login/permissions, F18 N3 reader, F19 history ACL tests, F20 SQL A/B RLS tests, HTTP and Playwright.
- [ ] Independent DBA verifies table list, RLS policy composition, roles/memberships and GUC spoofing limitations.
- [ ] Manual review checks all routes, batch workers, PDF exports, trusted identity boundary, query timing and tenant-switch/revocation behavior.
- [ ] Hosted isolated staging and backup/restore validation completed and evidence retained.
- [ ] An approved architecture blocks client/SQL tampering with database tenant context before treating the runtime as hostile.

Until these criteria are satisfied, the system is **NO-GO for live multi-tenant use**. Do not merge or deploy F20 by automation.

## Rollback

The migration's `down` removes only F20 policies, disables RLS on the newly protected tables, and restores F16's explicit transitional runtime incident policy. F15's limited incident-reader RLS remains. Any rollback requires DBA/security review: it intentionally weakens protection and is not approved for production.
