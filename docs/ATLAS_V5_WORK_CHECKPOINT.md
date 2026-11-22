# ATLAS ONE V5 — WORK CHECKPOINT / WAVE 02

## Canonical recovery point

- **Repository:** `AhmedAmine93/atlas-erp-lite`
- **Working branch:** `feature/v5-hotel-wave-02`
- **PR:** #78 — https://github.com/AhmedAmine93/atlas-erp-lite/pull/78 (draft)
- **PR base:** `dev` (never `main`)
- **Base dev commit:** `9a5eb58454a34bce1b13803ec3643ffc37e3c681`
- **Previous wave:** PR #77 (merged into dev, CI successful)
- **Wave 02 state:** CREATED / NOT STARTED. **0/20 steps implemented.**
- **Next executable step:** 02 — Implement checkout Laravel. Step 01 (verify/merge PR #77 and create branch) is finished, but the other 19 development/validation steps remain.
- **Checkpoint authoring note:** this is an initialization checkpoint, NOT proof that any Wave 02 module or test is complete. Work must update this document with real evidence after each lot.

## Scope and guardrails

Single temporary branch; single **draft** PR into `dev`; no production access; do not write to `main`. Backend Laravel/PHP, PostgreSQL with tenant RLS and restricted runtime account, Next.js/TypeScript, Docker Compose, GitHub Actions, PHPUnit, Playwright. New migrations must be additive, tenant-scoped and least-privilege. No real guest data, keys or secrets. Do not duplicate existing API/JS modules. Preserve compatibility and run targeted tests after each feature, entire CI before merge.

Read this file plus the affected source files; do not re-read the entire repository or the 861-step V5 plan unless a specific unresolved requirement forces a lookup. Changes must be functionally complete and independently tested; do not mark steps done based only on commits.

## Execution queue (exactly 20 steps)

| Step | Work | Status |
|---|---|---|
| 01 | Validate and merge PR #77; create Wave 02 branch and PR/checkpoint | **Done** (PR #78 draft created) |
| 02 | Laravel checkout: `checked_in -> checked_out`, authorized, audited, business-date aware; early departure reason | TODO |
| 03 | Booking state/security hardening, date checks, GiST, RLS, concurrency, tenant scopes | TODO |
| 04 | Next.js reception actions: confirm/check-in/check-out/cancel, server errors | TODO |
| 05 | Focused Laravel/PostgreSQL tests; checkpoint A | TODO |
| 06 | Hotel/day per-room summary read API: physical state, housekeeping, occupancy and local arrivals/departures | TODO |
| 07 | Existing housekeeping room cards: verified status badges; no sellability inference | TODO |
| 08 | Tenant-safe PostgreSQL minibar product/catalog/price history tables | TODO |
| 09 | Minibar RLS, restricted runtime grants, indexes, composite FKs and security tests | TODO |
| 10 | Minibar catalog REST API and validation; checkpoint B | TODO |
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

- **Completed steps:** 01/20 (setup and PR #77 merge only)
- **Pending steps:** 02–20
- **Commit:** recorded by GitHub as the commit introducing this file; query branch head once when resuming
- **Files changed in Wave 02 so far:** only this checkpoint (PR draft metadata is not code)
- **Tests executed for Wave 02:** none; previous PR #77 CI succeeded
- **Known blockers:** none identified yet; checkout date semantics require validation from existing model and API
- **Next action:** implement step 02, run targeted tests, commit, then step 03
