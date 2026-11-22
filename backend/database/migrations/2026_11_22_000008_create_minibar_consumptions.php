<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE minibar_consumptions (
                id bigserial PRIMARY KEY, company_id bigint NOT NULL, hotel_id bigint NOT NULL,
                business_date date NOT NULL, room_id bigint NOT NULL, product_id bigint NOT NULL,
                price_id bigint NOT NULL, unit_cents integer NOT NULL,
                quantity integer NOT NULL CHECK (quantity BETWEEN 1 AND 999),
                total_cents bigint GENERATED ALWAYS AS (quantity::bigint * unit_cents) STORED,
                request_id uuid NOT NULL, created_by bigint NOT NULL REFERENCES users(id),
                created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (company_id,hotel_id,request_id),
                FOREIGN KEY (company_id,hotel_id,room_id) REFERENCES hotel_rooms(company_id,hotel_id,id),
                FOREIGN KEY (company_id,hotel_id,product_id,price_id,unit_cents)
                    REFERENCES minibar_prices(company_id,hotel_id,product_id,id,unit_cents)
            );
            CREATE INDEX minibar_day_room ON minibar_consumptions(company_id,hotel_id,business_date,room_id,product_id);
            SQL);
        $grant = require database_path('minibar_runtime.php');
        $grant(['minibar_consumptions'=>'SELECT, INSERT']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE minibar_consumptions');
    }
};
