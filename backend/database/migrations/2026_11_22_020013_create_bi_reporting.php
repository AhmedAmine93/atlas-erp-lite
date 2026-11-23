<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M13 V5.2: immutable, tenant-owned BI definitions, executions and CSV exports.
 * No destructive backfill. Reports never store SQL supplied by a client.
 */
return new class extends Migration {
    private const TABLES = ['bi_report_definitions', 'bi_report_runs', 'bi_report_exports'];

    public function up(): void
    {
        Schema::create('bi_report_definitions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->uuid('report_uid');
            $t->unsignedInteger('version');
            $t->string('title', 150);
            $t->string('dataset', 30);
            $t->json('columns');
            $t->json('filters');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 128);
            $t->char('request_hash', 64);
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'report_uid', 'version'], 'bi_definition_version_uq');
            $t->unique(['company_id', 'idempotency_key'], 'bi_definition_idempotency_uq');
            $t->index(['company_id', 'dataset', 'created_at']);
        });
        Schema::create('bi_report_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('definition_id');
            $t->foreignId('executed_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('row_count');
            $t->timestamp('cutoff_at');
            $t->timestamps();
            $t->index(['company_id', 'definition_id', 'created_at'], 'bi_runs_by_definition_idx');
            $t->foreign(['company_id', 'definition_id'], 'bi_runs_definition_tenant_fk')
                ->references(['company_id', 'id'])->on('bi_report_definitions')->restrictOnDelete();
        });
        Schema::create('bi_report_exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('dataset', 30);
            $t->string('classification', 30)->default('INTERNAL');
            $t->date('period_from');
            $t->date('period_to');
            $t->unsignedBigInteger('definition_id')->nullable();
            $t->json('columns');
            $t->json('snapshot');
            $t->unsignedInteger('row_count');
            $t->char('sha256', 64);
            $t->timestamp('cutoff_at');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['company_id', 'created_at']);
            $t->foreign(['company_id', 'definition_id'], 'bi_exports_definition_tenant_fk')
                ->references(['company_id', 'id'])->on('bi_report_definitions')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') return;
        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
        if (!$role || (int)$role->elevated !== 0) {
            throw new RuntimeException('M13 requires restricted atlas_app_runtime DB role');
        }
        foreach (self::TABLES as $table) {
            DB::statement("GRANT SELECT, INSERT ON TABLE public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE UPDATE, DELETE ON TABLE public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_f20_runtime_tenant_policy ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            $seq = DB::selectOne("SELECT pg_get_serial_sequence('public.{$table}', 'id') AS seq");
            if ($seq?->seq) DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$seq->seq} TO atlas_app_runtime");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::statement("DROP POLICY IF EXISTS atlas_f20_runtime_tenant_policy ON public.\"{$table}\"");
                DB::statement("ALTER TABLE public.\"{$table}\" DISABLE ROW LEVEL SECURITY");
            }
        }
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
    }
};
