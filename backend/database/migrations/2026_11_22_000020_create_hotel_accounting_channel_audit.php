<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('M08 finance, CRS/PMS et clôture nécessitent PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE hotel_bookings ADD CONSTRAINT hotel_bookings_company_hotel_id_unique
                UNIQUE(company_id, hotel_id, id);

            CREATE TABLE hotel_business_clock_versions (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL,
                hotel_id bigint NOT NULL,
                effective_on date NOT NULL,
                timezone varchar(80) NOT NULL,
                cutoff_time time NOT NULL,
                created_by bigint NOT NULL REFERENCES users(id),
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(company_id,hotel_id,id),
                UNIQUE(company_id,hotel_id,effective_on),
                FOREIGN KEY(company_id,hotel_id) REFERENCES hotel_properties(company_id,id)
            );

            CREATE TABLE hotel_folios (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL,
                hotel_id bigint NOT NULL,
                booking_id bigint NOT NULL,
                folio_number varchar(60) NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'open' CHECK(status IN ('open','closed')),
                created_by bigint NOT NULL REFERENCES users(id),
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(company_id,hotel_id,id),
                UNIQUE(company_id,hotel_id,folio_number),
                FOREIGN KEY(company_id,hotel_id,booking_id)
                    REFERENCES hotel_bookings(company_id,hotel_id,id)
            );
            CREATE INDEX hotel_folios_booking_idx ON hotel_folios(company_id,hotel_id,booking_id);

            CREATE TABLE hotel_folio_entries (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL,
                hotel_id bigint NOT NULL,
                folio_id bigint NOT NULL,
                business_date date NOT NULL,
                kind varchar(20) NOT NULL
                    CHECK(kind IN ('charge','credit','payment','transfer_in','transfer_out')),
                source_type varchar(20) NOT NULL
                    CHECK(source_type IN ('room','minibar','manual','adjustment','payment','transfer')),
                source_reference varchar(100),
                amount_cents bigint NOT NULL CHECK(amount_cents BETWEEN 1 AND 100000000000),
                note varchar(300) NOT NULL,
                related_entry_id bigint,
                request_id uuid NOT NULL,
                created_by bigint NOT NULL REFERENCES users(id),
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(company_id,hotel_id,request_id),
                FOREIGN KEY(company_id,hotel_id,folio_id)
                    REFERENCES hotel_folios(company_id,hotel_id,id),
                FOREIGN KEY(related_entry_id) REFERENCES hotel_folio_entries(id),
                CHECK(length(trim(note)) >= 3)
            );
            CREATE INDEX hotel_folio_entries_date_idx
                ON hotel_folio_entries(company_id,hotel_id,business_date,folio_id);

            CREATE TABLE hotel_reference_rules (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL,
                hotel_id bigint NOT NULL,
                effective_on date NOT NULL,
                prefix_digits smallint NOT NULL DEFAULT 0 CHECK(prefix_digits BETWEEN 0 AND 12),
                separator varchar(1) NOT NULL DEFAULT '_' CHECK(separator IN ('_','-','/')),
                created_by bigint NOT NULL REFERENCES users(id),
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(company_id,hotel_id,id),
                UNIQUE(company_id,hotel_id,effective_on),
                FOREIGN KEY(company_id,hotel_id) REFERENCES hotel_properties(company_id,id)
            );

            CREATE TABLE hotel_channel_imports (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL,
                hotel_id bigint NOT NULL,
                source varchar(12) NOT NULL CHECK(source IN ('pms','crs')),
                business_date date NOT NULL,
                reference_rule_id bigint NOT NULL,
                request_id uuid NOT NULL,
                payload_digest char(64) NOT NULL,
                created_by bigint NOT NULL REFERENCES users(id),
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(company_id,hotel_id,id),
                UNIQUE(company_id,hotel_id,request_id),
                UNIQUE(company_id,hotel_id,source,business_date,payload_digest),
                FOREIGN KEY(company_id,hotel_id) REFERENCES hotel_properties(company_id,id),
                FOREIGN KEY(company_id,hotel_id,reference_rule_id)
                    REFERENCES hotel_reference_rules(company_id,hotel_id,id)
            );

            CREATE TABLE hotel_channel_records (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL,
                hotel_id bigint NOT NULL,
                import_id bigint NOT NULL,
                row_number integer NOT NULL CHECK(row_number > 0),
                raw_reference varchar(180) NOT NULL,
                normalized_reference varchar(180) NOT NULL,
                booking_status varchar(24) NOT NULL
                    CHECK(booking_status IN ('active','cancelled','no_show')),
                booking_origin varchar(16) NOT NULL
                    CHECK(booking_origin IN ('direct','channel','unknown')),
                amount_cents bigint CHECK(amount_cents BETWEEN 0 AND 100000000000),
                UNIQUE(import_id,row_number),
                FOREIGN KEY(company_id,hotel_id,import_id)
                    REFERENCES hotel_channel_imports(company_id,hotel_id,id)
            );
            CREATE INDEX hotel_channel_lookup_idx
                ON hotel_channel_records(company_id,hotel_id,normalized_reference);

            CREATE TABLE hotel_night_audits (
                id bigserial PRIMARY KEY,
                company_id bigint NOT NULL,
                hotel_id bigint NOT NULL,
                business_date date NOT NULL,
                clock_version_id bigint NOT NULL,
                request_id uuid NOT NULL,
                snapshot jsonb NOT NULL,
                anomalies jsonb NOT NULL,
                created_by bigint NOT NULL REFERENCES users(id),
                created_at timestamptz NOT NULL DEFAULT now(),
                UNIQUE(company_id,hotel_id,id),
                UNIQUE(company_id,hotel_id,business_date),
                UNIQUE(company_id,hotel_id,request_id),
                FOREIGN KEY(company_id,hotel_id,clock_version_id)
                    REFERENCES hotel_business_clock_versions(company_id,hotel_id,id)
            );
            SQL);

        // Deny DELETE, restrict INSERT/UPDATE, and enforce runtime tenant isolation
        // under the same limited PostgreSQL role used by the existing minibar.
        $grant = require database_path('minibar_runtime.php');
        $grant([
            'hotel_business_clock_versions' => 'SELECT, INSERT',
            'hotel_folios' => 'SELECT, INSERT, UPDATE (status,updated_at)',
            'hotel_folio_entries' => 'SELECT, INSERT',
            'hotel_reference_rules' => 'SELECT, INSERT',
            'hotel_channel_imports' => 'SELECT, INSERT',
            'hotel_channel_records' => 'SELECT, INSERT',
            'hotel_night_audits' => 'SELECT, INSERT',
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS hotel_night_audits, hotel_channel_records, hotel_channel_imports, hotel_reference_rules, hotel_folio_entries, hotel_folios, hotel_business_clock_versions');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE hotel_bookings DROP CONSTRAINT IF EXISTS hotel_bookings_company_hotel_id_unique');
        }
    }
};
