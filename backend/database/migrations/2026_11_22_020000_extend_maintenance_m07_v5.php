<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M07 add-on: additive schema; the existing DI/BT and inventory remain authoritative.
 * All tenant-owned references use composite keys where an existing composite key exists.
 */
return new class extends Migration {
    private const TABLES = [
        'maintenance_locations', 'maintenance_asset_location_events',
        'maintenance_asset_downtimes', 'maintenance_work_order_time_entries',
        'maintenance_spare_reservations', 'maintenance_spare_consumptions',
        'maintenance_contracts', 'maintenance_sla_events',
        'maintenance_field_events', 'maintenance_inspections',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('M07 V5.1 requires PostgreSQL.');
        }

        Schema::table('maintenance_work_orders', function (Blueprint $t) {
            $t->unique(['company_id', 'id'], 'm07_workorder_company_id_unique');
            $t->unsignedInteger('field_version')->default(1);
        });
        Schema::create('maintenance_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('code', 60);
            $t->string('name', 180);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id','id']);
            $t->unique(['company_id','site_id','code']);
            $t->foreign(['company_id','site_id'])->references(['company_id','id'])
                ->on('maintenance_sites')->restrictOnDelete();
            $t->foreign(['company_id','parent_id'])->references(['company_id','id'])
                ->on('maintenance_locations')->restrictOnDelete();
        });
        Schema::table('maintenance_assets', function (Blueprint $t) {
            $t->unsignedBigInteger('location_id')->nullable();
            $t->string('criticality', 12)->default('medium');
            $t->unsignedBigInteger('parent_asset_id')->nullable();
            $t->foreign(['company_id','location_id'])->references(['company_id','id'])
                ->on('maintenance_locations')->restrictOnDelete();
            $t->foreign(['company_id','parent_asset_id'])->references(['company_id','id'])
                ->on('maintenance_assets')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_assets ADD CONSTRAINT m07_asset_criticality CHECK (criticality IN ('low','medium','high','critical'))");
        DB::statement('ALTER TABLE maintenance_assets ADD CONSTRAINT m07_asset_not_self_parent CHECK (parent_asset_id IS NULL OR parent_asset_id <> id)');

        Schema::create('maintenance_asset_location_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('asset_id');
            $t->unsignedBigInteger('from_location_id')->nullable();
            $t->unsignedBigInteger('to_location_id');
            $t->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $t->string('reason', 500);
            $t->timestampTz('moved_at');
            $t->timestamps();
            $t->foreign(['company_id','asset_id'])->references(['company_id','id'])->on('maintenance_assets')->restrictOnDelete();
            $t->foreign(['company_id','to_location_id'])->references(['company_id','id'])->on('maintenance_locations')->restrictOnDelete();
        });
        Schema::create('maintenance_asset_downtimes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('asset_id');
            $t->timestampTz('started_at');
            $t->timestampTz('ended_at')->nullable();
            $t->string('reason', 500);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['company_id','asset_id'])->references(['company_id','id'])->on('maintenance_assets')->restrictOnDelete();
            $t->index(['company_id','asset_id','started_at']);
        });
        DB::statement('CREATE UNIQUE INDEX m07_one_open_downtime ON maintenance_asset_downtimes(company_id,asset_id) WHERE ended_at IS NULL');
        DB::statement('ALTER TABLE maintenance_asset_downtimes ADD CONSTRAINT m07_positive_downtime CHECK (ended_at IS NULL OR ended_at >= started_at)');

        Schema::table('maintenance_requests', function (Blueprint $t) {
            $t->string('impact', 20)->nullable();
            $t->text('rejection_reason')->nullable();
            $t->timestampTz('rejected_at')->nullable();
            $t->foreignId('qualified_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('maintenance_work_order_time_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedInteger('minutes');
            $t->unsignedInteger('hourly_rate_cents');
            $t->unsignedBigInteger('cost_cents');
            $t->string('operation_key', 100);
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['company_id','work_order_id'])->references(['company_id','id'])->on('maintenance_work_orders')->restrictOnDelete();
            $t->unique(['company_id','operation_key']);
        });
        DB::statement('ALTER TABLE maintenance_work_order_time_entries ADD CONSTRAINT m07_labor_positive CHECK (minutes BETWEEN 1 AND 1440 AND hourly_rate_cents <= 10000000 AND cost_cents <= 240000000)');

        Schema::create('maintenance_spare_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('product_id');
            $t->foreign(['company_id','product_id'])->references(['company_id','id'])
                ->on('products')->restrictOnDelete();
            $t->unsignedInteger('reserved_quantity');
            $t->unsignedInteger('consumed_quantity')->default(0);
            $t->unsignedInteger('released_quantity')->default(0);
            $t->string('operation_key',100);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','operation_key']);
            $t->unique(['company_id','id']);
            $t->foreign(['company_id','work_order_id'])->references(['company_id','id'])->on('maintenance_work_orders')->restrictOnDelete();
            $t->index(['company_id','product_id']);
        });
        DB::statement('ALTER TABLE maintenance_spare_reservations ADD CONSTRAINT m07_spare_quantities CHECK (reserved_quantity > 0 AND consumed_quantity + released_quantity <= reserved_quantity)');
        Schema::create('maintenance_spare_consumptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('reservation_id');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('unit_price_cents');
            $t->unsignedBigInteger('total_cents');
            $t->string('operation_key',100);
            $t->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','id']);
            $t->unique(['company_id','operation_key']);
            $t->foreign(['company_id','reservation_id'])->references(['company_id','id'])->on('maintenance_spare_reservations')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE maintenance_spare_consumptions ADD CONSTRAINT m07_spare_consumption_positive CHECK (quantity > 0 AND total_cents >= 0)');

        Schema::create('maintenance_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code',60);
            $t->string('name',180);
            $t->unsignedBigInteger('site_id');
            $t->unsignedInteger('response_hours');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('calendar_timezone', 64)->default('Europe/Paris');
            $t->string('status', 20)->default('active');
            $t->text('exclusions')->nullable();
            $t->timestamps();
            $t->unique(['company_id','code']);
            $t->unique(['company_id','id']);
            $t->foreign(['company_id','site_id'])->references(['company_id','id'])->on('maintenance_sites')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_contracts ADD CONSTRAINT m07_contract_valid CHECK (ends_on >= starts_on AND response_hours BETWEEN 1 AND 8760 AND status IN ('active','suspended','expired'))");
        Schema::create('maintenance_sla_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('work_order_id');
            $t->unsignedBigInteger('contract_id');
            $t->string('event',30);
            $t->string('reason',500);
            $t->timestampTz('occurred_at');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['company_id','work_order_id'])->references(['company_id','id'])->on('maintenance_work_orders')->restrictOnDelete();
            $t->foreign(['company_id','contract_id'])->references(['company_id','id'])->on('maintenance_contracts')->restrictOnDelete();
        });
        Schema::create('maintenance_field_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('work_order_id');
            $t->string('operation_key',100);
            $t->string('kind',24);
            $t->text('note')->nullable();
            $t->unsignedInteger('base_version');
            $t->unsignedInteger('result_version');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','operation_key']);
            $t->foreign(['company_id','work_order_id'])->references(['company_id','id'])->on('maintenance_work_orders')->restrictOnDelete();
        });
        Schema::create('maintenance_inspections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('work_order_id');
            $t->boolean('passed');
            $t->string('note',1000);
            $t->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['company_id','work_order_id'])->references(['company_id','id'])->on('maintenance_work_orders')->restrictOnDelete();
            $t->index(['company_id','work_order_id','created_at']);
        });

        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname='atlas_app_runtime'");
        if (! $role || (int) $role->elevated !== 0) {
            throw new RuntimeException('Restricted runtime DB role required for M07.');
        }
        foreach (self::TABLES as $table) {
            DB::statement("GRANT SELECT, INSERT, UPDATE ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
CREATE POLICY atlas_m07_tenant_policy ON public."{$table}" FOR ALL TO atlas_app_runtime
USING (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint)
WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint)
SQL);
            $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", ["public.{$table}"]);
            if (! $seq?->name) throw new RuntimeException("Missing sequence for {$table}");
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$seq->name} TO atlas_app_runtime");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", ["public.{$table}"]);
                if ($seq?->name) DB::statement("REVOKE USAGE, SELECT ON SEQUENCE {$seq->name} FROM atlas_app_runtime");
            }
        }
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
        Schema::table('maintenance_requests', function (Blueprint $t) {
            $t->dropColumn(['impact','rejection_reason','rejected_at','qualified_by']);
        });
        Schema::table('maintenance_assets', function (Blueprint $t) {
            $t->dropForeign(['company_id','location_id']);
            $t->dropForeign(['company_id','parent_asset_id']);
            $t->dropColumn(['location_id','criticality','parent_asset_id']);
        });
        Schema::table('maintenance_work_orders', function (Blueprint $t) {
            $t->dropUnique('m07_workorder_company_id_unique');
            $t->dropColumn('field_version');
        });
    }
};