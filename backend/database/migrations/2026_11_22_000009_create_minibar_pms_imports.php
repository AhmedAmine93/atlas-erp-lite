<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE minibar_pms_imports (
                id bigserial PRIMARY KEY, company_id bigint NOT NULL, hotel_id bigint NOT NULL,
                business_date date NOT NULL, digest char(64) NOT NULL, mapping jsonb NOT NULL,
                data_rows jsonb NOT NULL CHECK (jsonb_typeof(data_rows)='array'),
                created_by bigint NOT NULL REFERENCES users(id), created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(company_id,hotel_id) REFERENCES hotel_properties(company_id,id),
                UNIQUE(company_id,hotel_id,business_date,digest)
            );
            CREATE INDEX minibar_pms_latest ON minibar_pms_imports(company_id,hotel_id,business_date,id DESC);
            SQL);
        $grant = require database_path('minibar_runtime.php');
        $grant(['minibar_pms_imports'=>'SELECT, INSERT']);
    }
    public function down(): void
    {
        DB::statement('DROP TABLE minibar_pms_imports');
    }
};
