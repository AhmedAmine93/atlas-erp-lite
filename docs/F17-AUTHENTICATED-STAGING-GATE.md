# ATLAS ONE — F17: authenticated PostgreSQL runtime gate

**State:** draft/staging-only; no merge or deployment. **Base:** F16 PR #25. **Target:** V4 S03/S06/S07, L03–L05 and L07–L12.

## What this verifies

F16's positive/negative role tests used `SET ROLE` from an owner session. F17 additionally authenticates as `atlas_app_runtime` using a fresh password created during a throwaway CI job. The job is explicitly limited to `COMPOSE_PROJECT_NAME=atlas-ci*` and an ephemeral Docker volume. It does not run against a deployed staging host or production.

`scripts/verify-f17.sh` does the following, failing immediately upon any mismatch:

1. Generates a fresh random password in memory, then enables login **only in the disposable CI PostgreSQL instance**. The secret is not committed or placed in the evidence report.
2. Authenticates over TCP with PostgreSQL as `atlas_app_runtime`; asserts both `session_user` and `current_user`.
3. Verifies the authenticated role cannot SELECT from `migrations`, CREATE a table, ALTER `companies`, or DROP `companies`; the rejection must be SQLSTATE `42501`.
4. Runs a fixed F15 incident-reader check: with `SET LOCAL ROLE atlas_f15_incident_reader` and no tenant context it sees zero incidents; access to `users` is rejected.
5. Recreates only the backend/API/frontend containers with the restricted database identity and calls `php artisan atlas:verify-runtime-db`.
6. Returns control to the normal CI pipeline: the existing HTTP sales smoke and Playwright sales, tenant-navigation and incident/N3 scenarios then run with the restricted connection.
7. Preserves a minimal `reports/f17-runtime.txt` result without passwords.

**Verification in this PR:** review GitHub Actions logs and the uploaded test report. Do not mark F17 complete if the workflow is failing, absent or canceled.

## Safety and limitations

- The existing `main`, `compose.production.yaml`, deployment scripts and real infrastructure are untouched.
- The owner remains the migrator; the application authenticates with the runtime role only for the selected CI stage.
- A CI success is **not** a public staging release, production approval, independent penetration test, or full tenant-wide PostgreSQL RLS. The F16 runtime policy on incidents is intentionally permissive.
- F17 changes a role's password and login setting **inside its isolated ephemeral cluster only**; CI removes the Docker volume on exit.
- Actual hosted staging, network restrictions, secret management/rotation, audit alerting, restoration and DBA/security review remain release blockers.
- PostgreSQL roles are cluster-wide: the `atlas-ci` name guard is a guardrail, not a substitute for isolated infrastructure. A runner must never attach this script to a persistent database.

## Gate

- [ ] GitHub Actions workflow passed for this PR's exact head SHA
- [ ] Runtime password authentication and negative SQL tests passed
- [ ] Laravel connected-user verifier passed
- [ ] HTTP sale/invoice/PDF smoke passed under runtime account
- [ ] Browser incident, N3 and tenant-switch tests passed under runtime account
- [ ] Reviewer confirmed no real secrets, user data or deployments
- [ ] Separate hosted staging, backup/restore and adversarial cross-tenant tests planned

**Decision until these are signed:** NO-GO for deployment, leave PR in draft.
