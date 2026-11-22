<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M05.03 — Additive multi-tenant warehouse/location registry.
 * This migration does not relocate legacy products.stock or claim that stock is
 * warehouse-allocated: that transition requires an audited backfill.
 */
return new class extends Migration {
    private const TABLES = ['inventory_warehouses', 'inventory_locations'];

    public function up(): void
    {
        Schema::create('inventory_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 180);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name', 180);
            $table->string('condition_code', 16)->default('normal');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'warehouse_id', 'code'], 'inv_location_code_per_warehouse');
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'warehouse_id', 'active'], 'inv_location_active_idx');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE inventory_locations ADD CONSTRAINT inv_location_tenant_warehouse_fk
            FOREIGN KEY (company_id, warehouse_id) REFERENCES inventory_warehouses (company_id, id)");
        DB::statement("ALTER TABLE inventory_locations ADD CONSTRAINT inv_location_condition_check
            CHECK (condition_code IN ('normal', 'quarantine', 'regulated'))");

        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR
            rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles
            WHERE rolname = 'atlas_app_runtime'");
        if (!$role || (int) $role->elevated !== 0) {
            throw new RuntimeException('M05 requires the restricted PostgreSQL runtime role.');
        }

        foreach (self::TABLES as $table) {
            DB::statement("GRANT SELECT, INSERT, UPDATE ON TABLE public.{$table} TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON TABLE public.{$table} FROM atlas_app_runtime");
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.{$table}_id_seq TO atlas_app_runtime");
            DB::statement("ALTER TABLE public.{$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_m05_tenant_policy ON public.{$table}
                FOR ALL TO atlas_app_runtime
                USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
                WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_reverse(self::TABLES) as $table) {
                DB::statement("DROP POLICY IF EXISTS atlas_m05_tenant_policy ON public.{$table}");
                DB::statement("ALTER TABLE public.{$table} DISABLE ROW LEVEL SECURITY");
                DB::statement("REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE public.{$table} FROM atlas_app_runtime");
                DB::statement("REVOKE USAGE, SELECT ON SEQUENCE public.{$table}_id_seq FROM atlas_app_runtime");
            }
        }
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('inventory_warehouses');
    }
};
