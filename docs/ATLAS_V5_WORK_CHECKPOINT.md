# ATLAS ONE V5 — WORK CHECKPOINT / WAVE 02

## Canonical recovery point

- **Repository:** `AhmedAmine93/atlas-erp-lite`
- **Working branch:** `feature/v5-hotel-wave-02`
- **PR:** #78 — https://github.com/AhmedAmine93/atlas-erp-lite/pull/78 (draft)
- **PR base:** `dev` (never `main`)
- **Base dev commit:** `9a5eb58454a34bce1b13803ec3643ffc37e3c681`
- **Previous wave:** PR #77 (merged into dev, CI successful)
- **Wave 02 state:** 10/20 steps validated on the feature branch.
- **Next executable step:** 11 — minibar consumption ledger.

## Scope and guardrails

Single temporary branch; single **draft** PR into `dev`; no production access; do not write to `main`. Backend Laravel/PHP, PostgreSQL with tenant RLS and restricted runtime account, Next.js/TypeScript, Docker Compose, GitHub Actions, PHPUnit, Playwright. New migrations must be additive, tenant-scoped and least-privilege. No real guest data, keys or secrets. Do not duplicate existing API/JS modules. Preserve compatibility and run targeted tests after each feature, entire CI before merge.

Read this file plus the affected source files; do not re-read the entire repository or the 861-step V5 plan unless a specific unresolved requirement forces a lookup. Changes must be functionally complete and independently tested; do not mark steps done based only on commits.

## Execution queue (exactly 20 steps)

| Step | Work | Status |
|---|---|---|
| 01 | Validate and merge PR #77; create Wave 02 branch and PR/checkpoint | **Done** (PR #78 draft created) |
| 02 | Laravel checkout: `checked_in -> checked_out`, authorized, audited, business-date aware; early departure reason | **Done (A)** |
| 03 | Booking state/security hardening, date checks, GiST, RLS, concurrency, tenant scopes | **Done (A)** |
| 04 | Next.js reception actions: confirm/check-in/check-out/cancel, server errors | **Done (A)** |
| 05 | Focused Laravel/PostgreSQL tests; checkpoint A | **Done (A)** |
| 06 | Hotel/day per-room summary read API: physical state, housekeeping, occupancy and local arrivals/departures | **Done (B)** |
| 07 | Existing housekeeping room cards: verified status badges; no sellability inference | **Done (B)** |
| 08 | Tenant-safe PostgreSQL minibar product/catalog/price history tables | **Done (B)** |
| 09 | Minibar RLS, restricted runtime grants, indexes, composite FKs and security tests | **Done (B)** |
| 10 | Minibar catalog REST API and validation; checkpoint B | **Done (B)** |
| 11 | Minibar consumption ledger with immutable unit price, server-side totals, idempotency and audit | TODO |
| 12 | Next.js hotel/date/room/article minibar entry grid | TODO |
| 13 | Day-level reporting by room/article/hotel with historical price integrity | TODO |
| 14 | Tenant-scoped CSV export filtered by hotel/date | TODO |
| 15 | Validated fictitious PMS CSV import and matching configuration; checkpoint C | TODO |
| 16 | ATLAS-vs-PMS minibar reconciliation: matching/duplicates/amount deltas/export | TODO |
| 17 | Monthly minibar dashboard, daily trend and anomaly counts | TODO |
| 18 | Integration/security/unit tests: RLS, amounts, idempotency, imports, state machines | TODO |
| 19 | Full CI: Laravel, PostgreSQL, browser, Docker, existing-module regressions | TODO |
| 20 | Review changes and merge ONE PR into `dev` only if full CI green; checkpoint D | TODO |

## Working method / minimal context

- Use commits with focused names: `feat(hotel): ...`, `feat(minibar): ...`, `test(...): ...`.
- Existing relevant files: `backend/app/Http/Controllers/HotelBookingController.php`, `backend/app/Http/Controllers/HotelHousekeepingController.php`, `backend/app/Services/HotelRoomInventory.php`, `backend/routes/web.php`, `frontend/src/components/DomainWorkspace.tsx`, `frontend/src/components/HotelHousekeepingWorkspace.tsx`, `frontend/src/components/HotelAvailabilityWorkspace.tsx`, `frontend/src/components/Workspace.tsx`, `frontend/src/lib/navigation.ts`, `frontend/src/lib/workspaceRoutes.ts`.
- Check actual paths before modifications. Never rely on the last-turn narrative as proof of code or test results.
- After steps 05/10/15/20, update this document with actual `git rev-parse HEAD`, changed files, targeted commands and pass/fail evidence, blocking errors and exact next step.
- If CI or dependencies fail, leave PR draft and checkpoint status honest. Never silently skip tests or claim success.
- Full V5 user instruction details are in the user's Work conversation, but this checkpoint is sufficient to identify the first unfinished step and the minimal source scope.

## Most recent checkpoint

### A · 05

- Verified head: 6be5639dd2646be5c63ab68eb2582290c7f466af; steps 01–05 validated.
- Changed: HotelBookingController, two stay migrations, routes, DomainWorkspace, Workspace/navigation/workspaceRoutes, HotelCheckinTest, hotel-reception.spec.ts, scripts/ci.sh.
- Isolated PostgreSQL: vendor/bin/phpunit --filter 'Hotel|Minibar|V5RuntimeTenantRls' — 10 tests, 206 assertions.
- Full PHPUnit: 97 tests, 1096 assertions; Playwright: 13 passed; CI: https://github.com/AhmedAmine93/atlas-erp-lite/actions/runs/45035854974.
- No blocking errors. Next: 06 — per-room hotel/day summary API.

### B · 10

- Verified head: dcd9c6c2a95cd1ca67a38f6b4b6c87c2a863d137; steps 06–10 validated.
- Changed: HotelHousekeepingController/Workspace, routes, minibar_runtime.php, catalog migration, MinibarProduct, HotelMinibarCatalogController, HotelMinibarTest.
- PostgreSQL PHPUnit hotel/minibar/RLS filter: 12 tests, 229 assertions; full PHPUnit: 99 tests, 1119 assertions.
- Restore rehearsal fixed by resolving grants from Laravel’s database path. Playwright: 13 passed; CI: https://github.com/AhmedAmine93/atlas-erp-lite/actions/runs/45036808524.
- No blocking errors. Next: 11 — immutable consumption ledger and idempotent writes.
