<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M01 V5.1 finalisation: only append-only management targets and alert history.
 * Accounting postings and hotel revenue remain owned by their source domains.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pilotage_cost_centers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name', 160);
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'code']);
        });
        Schema::create('pilotage_target_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('metric', 50);
            $t->string('unit', 12);
            $t->date('period_from');
            $t->date('period_to');
            $t->unsignedBigInteger('target_value');
            $t->unsignedInteger('version');
            $t->string('reason', 300);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'metric', 'period_from', 'period_to', 'version'], 'pilotage_target_version_unique');
            $t->index(['company_id', 'period_from', 'period_to']);
        });
        Schema::create('pilotage_alert_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('rule_id');
            $t->string('entity_type', 20);
            $t->unsignedBigInteger('entity_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->string('action', 20);
            $t->string('reason', 500);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id', 'rule_id'], 'pilotage_alert_event_rule_fk')
                ->references(['company_id', 'id'])->on('pilotage_alert_rules')->restrictOnDelete();
            $t->unique(['company_id', 'rule_id', 'entity_type', 'entity_id', 'action'], 'pilotage_alert_event_once');
            $t->index(['company_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') return;
        DB::statement("ALTER TABLE pilotage_target_versions ADD CONSTRAINT pilotage_target_dates_check CHECK (period_to >= period_from AND period_to <= period_from + INTERVAL '366 days')");
        DB::statement("ALTER TABLE pilotage_target_versions ADD CONSTRAINT pilotage_target_unit_check CHECK ((metric = 'issued_ht_cents' AND unit = 'EUR_CENTS') OR (metric IN ('issued_invoice_count', 'validated_order_count', 'low_stock_count') AND unit = 'COUNT'))");
        DB::statement("ALTER TABLE pilotage_target_versions ADD CONSTRAINT pilotage_target_version_check CHECK (version > 0)");
        DB::statement("ALTER TABLE pilotage_alert_events ADD CONSTRAINT pilotage_alert_events_action_check CHECK (action IN ('escalated', 'resolved'))");
        DB::statement("ALTER TABLE pilotage_alert_events ADD CONSTRAINT pilotage_alert_events_entity_check CHECK ((entity_type = 'product' AND product_id = entity_id AND invoice_id IS NULL) OR (entity_type = 'invoice' AND invoice_id = entity_id AND product_id IS NULL))");
        DB::statement("ALTER TABLE pilotage_alert_events ADD CONSTRAINT pilotage_alert_events_product_fk FOREIGN KEY (company_id, product_id) REFERENCES public.products (company_id, id)");
        DB::statement("ALTER TABLE pilotage_alert_events ADD CONSTRAINT pilotage_alert_events_invoice_fk FOREIGN KEY (company_id, invoice_id) REFERENCES public.invoices (company_id, id)");
        $tables = [
            'pilotage_cost_centers' => 'SELECT, INSERT',
            'pilotage_target_versions' => 'SELECT, INSERT',
            'pilotage_alert_events' => 'SELECT, INSERT',
        ];
        foreach ($tables as $table => $permissions) {
            DB::statement("GRANT {$permissions} ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE UPDATE, DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.{$table}_id_seq TO atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_pilotage_tenant ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
        }
    }

    public function down(): void
    {
        foreach (['pilotage_alert_events', 'pilotage_target_versions', 'pilotage_cost_centers'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing rollback: {$table} contains business history.");
            }
        }
        Schema::dropIfExists('pilotage_alert_events');
        Schema::dropIfExists('pilotage_target_versions');
        Schema::dropIfExists('pilotage_cost_centers');
    }
};
