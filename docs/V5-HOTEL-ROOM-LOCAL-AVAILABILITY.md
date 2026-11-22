# ATLAS ONE V5 — P131.02 local room inventory and interval holds

Issue: #72. Contracts: B13/B14, P131.02/P131.06, P132.04/P132.06, S01/S03.

## Purpose and non-goals

This increment provides **local ATLAS room allocation**, not a complete distribution engine. It separates the housekeeping readiness endpoint from the local booking inventory. It does **not** inspect external PMS/CRS/channel allotments, unassigned category inventory, room rates, minimum stays, restrictions defined outside ATLAS, or external payment guarantee. Clients MUST NOT equate `locally_available: true` with an externally bookable room or sell it without the confirmed booking transaction.

## API: all routes are authenticated, admin-only and server-verified tenant-scoped

- `GET /api/hotels/rooms/availability?hotel_id=...&arrival_date=YYYY-MM-DD&departure_date=YYYY-MM-DD[&room_id=...&category=...]`
  - Returns paginated physical rooms and `locally_available`, with reason codes: `HOTEL_INACTIVE`, `ROOM_PHYSICALLY_BLOCKED`, `OCCUPIED_OR_CONFIRMED`, `ACTIVE_HOLD`, `MAINTENANCE_WINDOW`, `RESTRICTION`.
  - Response always labels `inventory_scope=atlas_local_assigned_rooms_v1`, `external_allotments_checked=false`, `pricing_checked=false`, `subject_to_confirmation=true`.
  - The hotel's configured timezone is returned; stay dates are local business **dates**, not timestamps; intervals are half-open `[arrival_date, departure_date)`, so checkout on the arrival date of the next guest is allowed.
  - Clean/inspected is not checked here. The separate `/api/hotels/housekeeping/readiness` API is advisory and must not grant inventory rights.
- `GET /api/hotels/rooms/blocks?hotel_id=...&room_id=...`: paginated local room blocks.
- `POST /api/hotels/rooms/blocks`: body `room_id, kind, starts_on, ends_on, reason`; for `kind=hold`, a future `expires_at` is mandatory. Kinds: `hold`, `maintenance`, `restriction`. Dates also use `[)`.
- `POST /api/hotels/rooms/blocks/{id}/release`: releases an active block with actor and timestamp. Expired holds are ignored by availability without mutating the historical record; they can be explicitly released later.

## Integrity and concurrency

- A PostgreSQL migration adds tenant composite foreign key `(company_id, hotel_id, room_id)`, constrained dates/kind/status, a tenant/date lookup index, RLS tenant policy and dedicated `atlas_app_runtime` minimum DML grants. No DELETE grant. New block creation and reservation confirmation both use the **same room-row `SELECT ... FOR UPDATE` lock** before rechecking all known blocks and confirmed/checked-in reservations.
- The existing `hotel_bookings_no_overlap` GiST exclusion constraint remains the final DB guard for concurrent confirmed bookings. Block-vs-booking overlaps are serialized by application room locks; **there is no cross-table PostgreSQL exclusion constraint**. Only the authorized transactional APIs are safe to mutate these records; privileged direct writes bypassing them remain a distinct DBA threat to mitigate.
- All create/release/confirm actions audit the mutation. A foreign tenant's room and block IDs return 404; reader role is forbidden.

## Test and release criteria

`backend/tests/Feature/HotelLocalInventoryTest.php` covers vacant/occupied rooms, checkout/check-in adjacency, dirty rooms remaining locally allocatable, holds and release, maintenance windows, expired holds, physical blocks, invalid dates and A/B tenant/reader denial. Existing `HotelBookingAvailabilityTest.php` checks the PostgreSQL booking GiST constraint. CI must pass against PostgreSQL and runtime least-privilege role before merging into `dev`.

## Deferred gates for complete #72 closure

1. Authoritative external channel/PMS integration, synchronized allotments and restrictions, rate/guarantee validation, and a defined stale-feed fail-closed policy.
2. Separate true guest check-in operation that requires housekeeping inspection on the correct hotel business date; current implementation only manages booking confirmation.
3. Dedicated PostgreSQL multi-connection concurrency stress tests for simultaneous block creation/confirmation, DB direct-write security tests and migration downgrade rehearsals.
4. Next.js integration after API contract review and client-side discrimination between `housekeeping_ready`, `ready_for_checkin` and `locally_available`.

No activation in main or production; do not close #72 after this increment alone.
