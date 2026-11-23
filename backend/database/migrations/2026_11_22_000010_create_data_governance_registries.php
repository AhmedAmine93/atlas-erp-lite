<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M14 V5.2: additive tenant-owned governance records.
 * External orchestration, Kafka, and object-store writes are deliberately NOT simulated.
 */
return new class extends Migration {
    private array $tables = [
        'data_pipelines', 'data_quality_rules', 'data_lineage_edges',
        'data_reconciliation_runs', 'data_lakehouse_datasets'
    ];

    public function up(): void {
        foreach ($this->tables as $table) {
            Schema::create($table, function(Blueprint $t) use ($table) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
                $t->string('code', 100);
                $t->string('name', 180);
                $t->string('status', 24)->default('draft');
                $t->unsignedInteger('version')->default(1);
                $t->json('configuration');
                $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $t->timestamps();
                $t->unique(['company_id','id'], $table.'_tenant_id_unique');
                $t->unique(['company_id','code','version'], $table.'_code_version_unique');
                $t->index(['company_id','status','created_at'], $table.'_status_idx');
            });
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_status_check CHECK (status IN ('draft','active','disabled'))");
                // FORCE RLS also applies to table owners. The runtime role must not have BYPASSRLS.
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY {$table}_tenant_policy ON {$table} USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            }
        }
    }

    public function down(): void {
        foreach (array_reverse($this->tables) as $table) Schema::dropIfExists($table);
    }
};
