<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE minibar_products (
                id bigserial PRIMARY KEY, company_id bigint NOT NULL, hotel_id bigint NOT NULL,
                sku varchar(40) NOT NULL, name varchar(120) NOT NULL, active boolean NOT NULL DEFAULT true,
                UNIQUE (company_id,hotel_id,id), UNIQUE (company_id,hotel_id,sku),
                FOREIGN KEY (company_id,hotel_id) REFERENCES hotel_properties(company_id,id),
                CHECK (sku ~ '^[A-Z0-9][A-Z0-9._-]{0,39}$' AND length(trim(name)) > 0)
            );
            CREATE TABLE minibar_prices (
                id bigserial PRIMARY KEY, company_id bigint NOT NULL, hotel_id bigint NOT NULL,
                product_id bigint NOT NULL, effective_on date NOT NULL,
                unit_cents integer NOT NULL CHECK (unit_cents BETWEEN 0 AND 1000000),
                UNIQUE (company_id,hotel_id,product_id,effective_on),
                UNIQUE (company_id,hotel_id,product_id,id,unit_cents),
                FOREIGN KEY (company_id,hotel_id,product_id) REFERENCES minibar_products(company_id,hotel_id,id)
            );
            SQL);
        $grant = require database_path('minibar_runtime.php');
        $grant(['minibar_products' => 'SELECT, INSERT, UPDATE (name,active)', 'minibar_prices' => 'SELECT, INSERT']);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE minibar_prices, minibar_products');
    }
};
