# ATLAS ONE — F16: PostgreSQL runtime identity separation (staging pilot)

**Status:** development candidate. No production deployment or merge.  
**Base:** PR #24, RC1, F01–F15.

## Verified risk in RC1

The current `compose.production.yaml` uses the same PostgreSQL table-owner
login `atlas` in `migrate` and `backend`. This means that a compromised
application DB connection could modify the schema and bypass owner-exempt RLS.
F15 is **only a restricted N3 read probe**, not general ERP RLS.

## Change introduced

The migration `2026_11_21_000008_create_runtime_db_privilege_boundary.php`
creates `atlas_app_runtime` as **NOLOGIN, NOINHERIT, NOBYPASSRLS**,
without superuser/createdb/createrole, schema CREATE or DDL rights.
It grants only SELECT/INSERT/UPDATE/DELETE on a fixed allowlist of
application tables, and USAGE/SELECT on their associated sequences.
The migration table is explicitly excluded.

For continuity with the F15 pilot, there is a **transitional, permissive
RLS policy for the runtime role on support_incidents**. It intentionally
does not enforce tenant isolation. Business authorization, Eloquent scopes,
and F14 composite FKs remain vital. Runtime role membership in the dedicated
F15 reader permits the restricted diagnostic probe.

These grants **do not implement least-privilege per application module**
and do not guarantee row-by-row tenant security. An arbitrary SQL injection
using the runtime role could still read and modify data from other companies
on tables without RLS. Real tenant-level DB enforcement requires another
phase with dedicated policy tests and an independent DBA review.

### Critical limitation: migration needs elevated credentials

Only the migration service with the owner account can create or modify tables.
An application started as `atlas_app_runtime` should fail on attempts to
read the migration repository or alter the schema.

The runtime role is **NOLOGIN** until separately provisioned. This blocks
an accidental direct connection from an environment not yet configured.
The migration does **not** set or generate a runtime password.

## Local/disposable staging activation (no Internet exposure)

Do not use a live database. Start from a new Docker volume and environment,
not the main production DB.

1. Start PostgreSQL with the owner credentials in the private local Docker
   stack and execute migrations using `init`. The stack must finish
   F15 and F16 migrations before provisioning the runtime login.
2. Enter trusted interactive psql as the DB owner:

   ```sh
   docker compose exec db psql -U atlas -d atlas
   ```

   In that interactive psql session type:

   ```sql
   \password atlas_app_runtime
   ALTER ROLE atlas_app_runtime LOGIN;
   ```

   `\password` prompts for the password without entering it into the
   shell history. Generate a long unique staging password. Do not record
   it in the repository, CI logs or tickets.

3. Create `.env.staging` **outside Git**, permissions `0600`, from
   `.env.staging.example`. Enter the password separately. Keep this file
   on a trusted local/staging host only; rotate it on leak.
4. Start the backend with the Compose overlay:

   ```sh
   docker compose --env-file .env --env-file .env.staging \
     -f compose.yaml -f compose.staging.yaml \
     up -d --wait --wait-timeout 240
   ```

   The `backend` service now gets the restricted role. The `init`
   migration service retains the privileged owner and should execute only
   during controlled upgrades; isolate it from public networks.

5. Run the full smoke and N3 tests. Check that the active identity is the
   runtime role, and that DML works while ALTER/DROP are denied.
6. Stop the staging stack when finished. To disable the test login, use
   `ALTER ROLE atlas_app_runtime NOLOGIN;` and rotate/revoke the password.

**Never use `docker compose up` with this override against production**
or assume it exposes HTTPS or configures a reverse proxy. The base
development stack binds the frontend and API to localhost.

## Automated F16 tests (CI)

`backend/tests/Feature/RuntimeDbPrivilegeTest.php` verifies on PostgreSQL:

- Runtime role is NOLOGIN, non-superuser, non-BYPASSRLS and cannot CREATE
  in public schema; it cannot read `migrations`.
- Role can insert a synthetic company using its permitted DML and sequence.
- Actual SQL attempts to read `migrations`, CREATE/ALTER/DROP tables
  are rejected with SQLSTATE `42501`.
- Restricted F15 diagnostic role is reachable without inheriting user access,
  observes zero incidents without tenant context and cannot delete incidents.
- Role/context are restored after testing.
- The previous F14 migration/recovery script excludes F16 when constructing
  the historical pre-F14 schema.

The CI uses a privileged **test owner** to simulate `SET ROLE`; it does
not yet verify the complete flow with a separately authenticated runtime
password. That login test is mandatory on staging before release.

## Staging promotion checklist

- [ ] Create new isolated database and network; no real customer data
- [ ] Provision runtime secret outside Git, restrict file access, test rotation
- [ ] Verify separate login succeeds and migration login remains distinct
- [ ] Test every ERP and N2/N3 endpoint with the restricted runtime DB role
- [ ] Confirm DDL denied, cross-tenant data inaccessible through authorized API
- [ ] Verify incident F15 reader behaves under the authenticated runtime role
- [ ] Rehearse realistic anonymized backup/restore and reversible deployment
- [ ] Instrument audit, metrics and external alerts
- [ ] DBA and security reviews of GRANT, role memberships and all RLS policies

**The existing production Compose file stays unchanged.**
