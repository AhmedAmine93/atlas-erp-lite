# ATLAS ONE V5 — P131/P132 contract: housekeeping is not sellability

Issue: #72. This initial backwards-compatible increment does not introduce an authoritative booking availability endpoint.

## Current advisory API

`GET /api/hotels/housekeeping/readiness?room_id={id}&business_date=YYYY-MM-DD` remains authenticated, tenant-scoped and admin-only.

- `housekeeping_ready`: true only if the task for the requested room/day reached `inspected`; it does not require the room to be physically available.
- `ready_for_checkin`: true only when `housekeeping_ready` and the room's physical status is `available`. This is a **physical/check-in preparation signal only**; occupancy, pricing and inventory allocation are not checked.
- `ready_for_sale`: deprecated compatibility alias, equal to `ready_for_checkin` for now, never a commercial sellability guarantee. Removal requires a versioned endpoint and coordinated client migration.
- `advisory_only: true` remains mandatory.

A room can be inspected yet already confirmed/occupied for the same date. Neither advisory field may authorize sale or reservation confirmation.

## Next independent increment (#72)

1. Agree a separate tenant-scoped availability API contract for a requested hotel, room/category and half-open stay interval `[arrival_date, departure_date)` in hotel business dates.
2. Check room active/physical maintenance status, confirmed and checked-in bookings, holds/restrictions and assignment conflicts with explicit reason codes; define missing-data fail-closed behavior.
3. Keep PostgreSQL exclusion constraint `hotel_bookings_no_overlap` as the final concurrency defense for confirmed bookings; validate parallel confirmations on PostgreSQL, not just SQLite.
4. Verify same-day checkout/check-in, multi-tenant A/B, room locks, booking cancellation, maintenance blocks, timezone boundaries, role authorization and audit.
5. Only then consume the authoritative endpoint from Next.js; keep physical housekeeping state visibly separate.

## Verification

Feature assertions in `HotelHousekeepingTest.php` check uninspected, inspected and out-of-service cases and preservation of the legacy field. A green CI confirms this compatibility change only, not full issue #72 closure.
