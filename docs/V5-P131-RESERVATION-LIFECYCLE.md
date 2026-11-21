# ATLAS ONE V5 — P131.01/.02 pilot : reservation lifecycle

Parent PR #45 provides hotel/room registry. This increment adds a **room-assigned direct reservation** lifecycle, not a complete PMS.

- `POST /api/hotel-reservations`: assign only an active room in an active hotel, validate arrival < departure, create a single confirmed booking with generated ULID reference.
- `PATCH /api/hotel-reservations/{id}/dates`: adjust arrival/departure only if still confirmed and non-overlapping.
- `POST /api/hotel-reservations/{id}/cancel`: cancel confirmed reservation; cannot cancel twice.
- `GET /api/hotel-reservations`: tenant-filtered results with bounded pagination.
- Concurrency: lock assigned room before availability check. PostgreSQL `btree_gist` exclusion constraint prevents overlapping *confirmed* bookings for the same room even if writes bypass the service.
- Departure day is exclusive, permitting check-out/check-in on the same date. Cancellation frees availability. No charges, payments, real customer data, no-show or check-in implemented.
- Composite foreign key ensures the room belongs to the hotel AND the active company.

**Scope constraint:** Admin-only endpoints, and `channel=direct` only. No third-party sync, CRS import, dynamic pricing or channel manager booking without contractual integration and dedupe rules.

**To do next:** P131.02 allocation by category/inventory, P131.03 channels, P131.04–.05 webhooks, P131.06 no-show, overbooking tests under real concurrency; P134 folios and payments; P129 business date. Security acceptance: tenant A/B denial, room 101 duplication across hotels, overlapping ranges, adjacent stays, date changes, cancellation release. No production merge or deployment.
