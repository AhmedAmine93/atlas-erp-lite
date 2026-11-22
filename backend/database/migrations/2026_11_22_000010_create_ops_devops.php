<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M18: metadata and observations only. No shell execution, database dump,
 * restore, or deployment is triggered by these tables.
 * Additive migration; isolated tenant-aware runtime privileges.
 */
return new class extends Migration {
    private const TABLES = [
        'ops_services', 'ops_observations', 'ops_logs', 'ops_jobs',
        'ops_backups', 'ops_deployments', 'ops_alerts', 'ops_events',
    ];

    public function up(): void
    {
        foreach (['ops_viewer', 'ops_operator'] as $role) {
            DB::table('roles')->insertOrIgnore(['name' => $role]);
        }

        Schema::create('ops_services', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 80);
            $t->string('name', 160);
            $t->string('owner', 160);
            $t->string('runbook_url', 500)->nullable();
            $t->string('criticality', 16)->default('normal');
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id', 'id']);
        });
        Schema::create('ops_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('service_id');
            $t->string('layer', 20);
            $t->string('state', 16);
            $t->unsignedInteger('latency_ms')->nullable();
            $t->string('source', 160);
            $t->timestampTz('observed_at');
            $t->timestamps();
            $t->foreign(['company_id', 'service_id'])->references(['company_id', 'id'])->on('ops_services')->restrictOnDelete();
            $t->index(['company_id', 'observed_at']);
        });
        Schema::create('ops_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('component', 100);
            $t->string('level', 12);
            $t->uuid('correlation_id');
            $t->text('message');
            $t->timestampTz('occurred_at');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'occurred_at']);
            $t->index(['company_id', 'correlation_id']);
        });
        Schema::create('ops_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('name', 120);
            $t->uuid('correlation_id');
            $t->string('status', 20)->default('queued');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('max_attempts');
            $t->timestampTz('window_start');
            $t->timestampTz('window_end');
            $t->string('runbook_url', 500);
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->unsignedInteger('version')->default(1);
            $t->text('failure_reason')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
            $t->index(['company_id', 'status', 'created_at']);
        });
        Schema::create('ops_backups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('artifact_reference', 160);
            $t->string('environment', 20);
            $t->boolean('encrypted');
            $t->timestampTz('completed_at');
            $t->timestampTz('last_restored_at')->nullable();
            $t->string('isolated_restore_reference', 160)->nullable();
            $t->unsignedInteger('observed_rpo_seconds')->nullable();
            $t->unsignedInteger('observed_rto_seconds')->nullable();
            $t->unsignedBigInteger('expected_rows')->nullable();
            $t->unsignedBigInteger('restored_rows')->nullable();
            $t->string('status', 20)->default('recorded');
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
            $t->index(['company_id', 'status']);
        });
        Schema::create('ops_deployments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('application', 120);
            $t->string('environment', 20);
            $t->string('image_digest', 100);
            $t->string('sbom_reference', 500);
            $t->string('provenance_reference', 500);
            $t->string('staging_evidence', 500)->nullable();
            $t->string('backup_restore_reference', 160)->nullable();
            $t->string('migration_strategy', 20);
            $t->text('rollback_plan');
            $t->string('status', 20)->default('planned');
            $t->string('decision', 20)->default('pending');
            $t->uuid('idempotency_key');
            $t->char('payload_hash', 64);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'idempotency_key']);
            $t->index(['company_id', 'environment', 'status']);
        });
        Schema::create('ops_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('service_id');
            $t->string('severity', 12);
            $t->string('title', 180);
            $t->string('owner', 160);
            $t->string('runbook_url', 500);
            $t->string('status', 16)->default('open');
            $t->timestampTz('started_at');
            $t->timestampTz('resolved_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->foreign(['company_id', 'service_id'])->references(['company_id', 'id'])->on('ops_services')->restrictOnDelete();
            $t->index(['company_id', 'status', 'severity']);
        });
        Schema::create('ops_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('resource', 24);
            $t->unsignedBigInteger('resource_id');
            $t->string('action', 32);
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20)->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['company_id', 'resource', 'resource_id', 'created_at'], 'ops_events_lookup');
        });

        if (DB::getDriverName() !== 'pgsql') return;
        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname='atlas_app_runtime'");
        if (! $role || (int) $role->elevated !== 0) throw new RuntimeException('Restricted atlas_app_runtime role required.');
        foreach (self::TABLES as $name) {
            $rights = in_array($name, ['ops_observations', 'ops_logs', 'ops_events'], true)
                ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
            DB::statement("GRANT {$rights} ON TABLE public.\"{$name}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON TABLE public.\"{$name}\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$name}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY ops_runtime_tenant ON public.\"{$name}\" TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.\"{$name}_id_seq\" TO atlas_app_runtime");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $name) {
                DB::statement("DROP POLICY IF EXISTS ops_runtime_tenant ON public.\"{$name}\"");
                DB::statement("REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE public.\"{$name}\" FROM atlas_app_runtime");
                DB::statement("REVOKE USAGE, SELECT ON SEQUENCE public.\"{$name}_id_seq\" FROM atlas_app_runtime");
            }
        }
        foreach (array_reverse(self::TABLES) as $name) Schema::dropIfExists($name);
    }
};
