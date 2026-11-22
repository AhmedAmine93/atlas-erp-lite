<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX hotel_bookings_one_in_house ON hotel_bookings
            (company_id, hotel_id, room_id) WHERE status = 'checked_in'");
        DB::statement("ALTER TABLE hotel_bookings ADD CONSTRAINT hotel_actual_dates CHECK (
            (checked_in_on IS NULL OR (checked_in_on >= arrival_date AND checked_in_on < departure_date
                AND status IN ('checked_in','checked_out')))
            AND (checked_out_on IS NULL OR (checked_out_on >= COALESCE(checked_in_on,arrival_date)
                AND status = 'checked_out')))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE hotel_bookings DROP CONSTRAINT hotel_actual_dates');
        DB::statement('DROP INDEX hotel_bookings_one_in_house');
    }
};
