# ATLAS ONE V5 — P052/P068 Technician scheduling and technical reports

## Functional scope

- Three tenant-owned PostgreSQL tables: `maintenance_teams`, `maintenance_technicians`, `maintenance_schedules`.
- One active technician assignment per BT; a scheduled slot is 15 minutes–24 hours and requires a BT still in `open` state.
- PostgreSQL `btree_gist` exclusion constraint prevents **overlapping reserved intervals for the same technician within the same company**, including concurrent requests; a separate unique partial index protects the BT from double-booking.
- Team, technician, and schedule references are enforced with **composite (company_id, id) foreign keys**; role `atlas_app_runtime` has SELECT/INSERT/UPDATE, never DELETE, with a company-specific RLS policy.
- A cancellation must include a human reason (>=10 characters), is audited, and releases the technician so a different BT can be scheduled. Started/completed BT cannot be unassigned.
- A repeated identical scheduling request is replay-safe; conflicting plans receive HTTP 409.
- All new routes are protected by `auth:sanctum`, `throttle:api`, `tenant`, and `Gate::authorize('admin')`. The current milestone does **not** grant technicians direct write access.
- Read-only maintenance reporting: work orders by status, DI priority, overdue work orders, schedule cancellations, planning load per technician; CSV export is browser-side and escaped against spreadsheet formulas.

## Endpoints

| Method | Route | Scope |
|---|---|---|
| GET / POST | `/api/maintenance/teams` | Directory and team creation |
| GET / POST | `/api/maintenance/technicians` | Directory and technician creation |
| GET | `/api/maintenance/planning` | Tenant-scoped slots; optional from/to/status |
| POST | `/api/maintenance/work-orders/{id}/schedule` | Plan one eligible BT |
| POST | `/api/maintenance/planning/{id}/cancel` | Cancel and release slot with reason |
| GET | `/api/maintenance/reports/summary` | Tenant-isolated operational KPIs, optional from/to |

Time fields use explicit offset ISO format `2026-11-24T09:00:00+01:00`. PostgreSQL stores `timestamptz`. The UI uses the browser's timezone and converts local times to UTC before submission.

## Demo and test evidence

The optional `MassiveDemoSeeder` now inserts **4 teams + 18 technicians per synthetic tenant** (12 teams, 54 technicians across 3 companies), plus a limited number of BT schedules with no overlaps. Seeding remains opt-in, local/testing only; never run on production or a real client dataset.

Run:
```bash
docker compose up --build -d --wait
docker compose exec -T -e ATLAS_DEMO_DATA_CONFIRM=YES_LOCAL_ONLY backend php artisan db:seed --class=MassiveDemoSeeder --force
```

The new `MaintenancePlanningTest` checks role/tenant isolation, duplicated code validation, one active BT assignment, half-open intervals, overlap rejection, cancellation and replay, report KPI and restricted-role SQL privileges. The existing `scripts/ci.sh` runs PHPUnit plus production images/Playwright; the Oracle JET dedicated workflow compiles the official JET VDOM workspace.

## Limitations / next acceptance gates

This is P068 **first scheduling and reporting slice**, not full APS or industrial MES. Not delivered: technician certification matching, holidays/working hours, multiple technician crews on a BT, resource capacity, travel time, recurring preventive jobs, service parts/costs, detailed PDF reports, equipment downtime analytics and cross-site scheduling optimizations. A production OIDC gateway, multi-browser E2E and NPM advisory remediation remain separate gates (issue #65).

Do not merge into `main`; merge into `dev` only after PostgreSQL CI + Oracle JET build pass.
