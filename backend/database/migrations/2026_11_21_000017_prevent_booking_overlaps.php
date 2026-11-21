<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        if (DB::getDriverName() !== 'pgsql') {
            return; // PostgreSQL is the production invariant; SQLite feature tests exercise the service.
        }
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE hotel_bookings ADD CONSTRAINT hotel_bookings_no_overlap
            EXCLUDE USING gist (
                company_id WITH =,
                room_id WITH =,
                daterange(arrival_date, departure_date, '[)') WITH &&
            ) WHERE (status IN ('confirmed', 'checked_in'))");
    }
    public function down(): void {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE hotel_bookings DROP CONSTRAINT IF EXISTS hotel_bookings_no_overlap');
        }
    }
};
