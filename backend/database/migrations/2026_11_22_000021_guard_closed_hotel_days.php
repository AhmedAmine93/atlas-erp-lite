<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('M08 SQL closure guards require PostgreSQL.');
        }

        // Prevent late direct SQL inserts into financial and minibar ledgers.
        // Correction entries belong to a later open day and must reference
        // their originating day; never mutate original rows.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION hotel_reject_closed_day_write() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM hotel_night_audits n
                    WHERE n.company_id = NEW.company_id
                      AND n.hotel_id = NEW.hotel_id
                      AND n.business_date = NEW.business_date
                ) THEN
                    RAISE EXCEPTION 'Business day closed for hotel % date %',
                        NEW.hotel_id, NEW.business_date
                        USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER hotel_folio_closed_day_guard
            BEFORE INSERT ON hotel_folio_entries
            FOR EACH ROW EXECUTE FUNCTION hotel_reject_closed_day_write();

            CREATE TRIGGER hotel_minibar_closed_day_guard
            BEFORE INSERT ON minibar_consumptions
            FOR EACH ROW EXECUTE FUNCTION hotel_reject_closed_day_write();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS hotel_minibar_closed_day_guard ON minibar_consumptions;
                DROP TRIGGER IF EXISTS hotel_folio_closed_day_guard ON hotel_folio_entries;
                DROP FUNCTION IF EXISTS hotel_reject_closed_day_write();
            SQL);
        }
    }
};
