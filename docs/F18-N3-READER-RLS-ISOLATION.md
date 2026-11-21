# ATLAS ONE — F18: same-session PostgreSQL N3 reader A/B isolation

**Status:** CI validation candidate; stacked on F17 PR #26 and F16 PR #25. **No merge, no hosted staging, no production.**

## Goal
Close the outstanding *scoped diagnostic reader* validation of V4 M02.07 and P024.03/P024.06. F17 authenticated the actual runtime database role and tested denied DDL. F18 verifies that the **dedicated F15 N3 incident reader** limits rows to tenant A or B and that its SQL role and tenant context do not survive a transaction boundary in the same PostgreSQL session.

## Procedure
- Run only within the disposable `atlas-ci*` project while F17's fresh runtime password is available.
- Create fictitious companies `F18_SQL_A`/`F18_SQL_B`, each with a fictitious incident, under the privileged CI setup identity.
- Open one TCP connection as `atlas_app_runtime`; use `BEGIN; SET LOCAL ROLE atlas_f15_incident_reader; set_config('atlas.tenant_id', id, true)` for each tenant.
- Assert A sees exactly its synthetic incident and not B; COMMIT; confirm `current_user` returns to the authenticated runtime and tenant context is empty.
- Reuse the exact connection for B; assert analogous B-only visibility and reset.
- Verify missing tenant context returns zero rows; delete the fixtures.
- Record minimal non-secret evidence in `reports/f18-rls.txt`; the existing F17 and HTTP/browser checks run as before.

## What this does **not** prove
The normal `atlas_app_runtime` principal still has broad DML grants and the transitional F16 incident RLS policy uses `USING (true)` — **raw runtime SQL is not tenant-isolated**. F18 tests only the narrowed F15 reader role. Likewise it cannot certify a PDO connection pool in another execution model, all tables, documents, jobs, exports, or an externally hosted staging environment. Moving to an enterprise deployment requires tenant-aware RLS policies, independently reviewed enforcement and wider adversarial tests.

## Gate
- [ ] CI on exact PR head is successful
- [ ] Authenticated same-session A/B RLS test is green
- [ ] Post-COMMIT role and tenant context are reset
- [ ] No-context reader returns zero
- [ ] F17 and the full HTTP/browser tests remain green
- [ ] DBA/security review before any expansion to production RLS

**Decision pending evidence:** NO-GO for any deployment with real tenants.
