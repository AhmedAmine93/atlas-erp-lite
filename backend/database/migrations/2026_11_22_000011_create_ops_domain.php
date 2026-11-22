<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M18: tenant-bound operational evidence; never stores backup contents, secrets
 * or arbitrary shell commands. No production data is removed by this migration.
 */
return new class extends Migration {
    private const TABLES = [
        'ops_service_checks', 'ops_log_entries', 'ops_jobs',
        'ops_backups', 'ops_deployments',
    ];

    public function up(): void
    {
        Schema::create('ops_service_checks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('service_key', 40);
            $t->string('technical_status', 16);
            $t->string('business_status', 16);
            $t->string('source', 40);
            $t->unsignedInteger('latency_ms')->nullable();
            $t->timestampTz('observed_at');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->index(['company_id', 'service_key', 'observed_at']);
        });

        Schema::create('ops_log_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('component', 48);
            $t->string('level', 12);
            $t->string('event', 80);
            $t->uuid('correlation_id');
            $t->string('message', 180);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestampTz('observed_at');
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->index(['company_id', 'observed_at']);
            $t->index(['company_id', 'correlation_id']);
        });

        Schema::create('ops_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('job_type', 48);
            $t->string('status', 16)->default('pending');
            $t->string('idempotency_key', 100);
            $t->char('payload_digest', 64);
            $t->uuid('correlation_id');
            $t->timestampTz('window_start')->nullable();
            $t->timestampTz('window_end')->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->unsignedSmallInteger('max_attempts')->default(3);
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('last_attempt_at')->nullable();
            $t->string('result', 120)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'idempotency_key']);
            $t->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('ops_backups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('backup_ref', 90);
            $t->string('environment', 16);
            $t->char('checksum_sha256', 64);
            $t->boolean('encrypted');
            $t->boolean('isolated_restore_verified')->default(false);
            $t->timestampTz('captured_at');
            $t->timestampTz('restored_at')->nullable();
            $t->unsignedInteger('observed_rpo_seconds')->nullable();
            $t->unsignedInteger('observed_rto_seconds')->nullable();
            $t->string('proof_ref', 120)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'backup_ref']);
            $t->index(['company_id', 'captured_at']);
        });

        Schema::create('ops_deployments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('environment', 16);
            $t->string('image_digest', 71);
            $t->string('status', 16)->default('draft');
            $t->boolean('sbom_verified')->default(false);
            $t->boolean('provenance_verified')->default(false);
            $t->boolean('staging_smoke_passed')->default(false);
            $t->boolean('migration_compatible')->default(false);
            $t->unsignedBigInteger('backup_id')->nullable();
            $t->string('decision_ref', 120)->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'environment', 'image_digest'], 'ops_release_digest_unique');
            $t->foreign(['company_id', 'backup_id'])
                ->references(['company_id', 'id'])->on('ops_backups')->restrictOnDelete();
            $t->index(['company_id', 'environment', 'status']);
        });

        if (DB::getDriverName() !== 'pgsql') return;
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY ops_tenant ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            $rights = in_array($table, ['ops_log_entries', 'ops_service_checks'], true)
                ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
            DB::statement("GRANT {$rights} ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            if ($table === 'ops_log_entries' || $table === 'ops_service_checks') {
                DB::statement("REVOKE UPDATE ON public.\"{$table}\" FROM atlas_app_runtime");
            }
            $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS seq", ['public.'.$table]);
            if ($seq?->seq) DB::statement('GRANT USAGE, SELECT ON SEQUENCE '.$seq->seq.' TO atlas_app_runtime');
        }
        DB::statement("ALTER TABLE ops_service_checks ADD CONSTRAINT ops_check_states CHECK (technical_status IN ('ok','degraded','down') AND business_status IN ('operational','degraded','blocked'))");
        DB::statement("ALTER TABLE ops_log_entries ADD CONSTRAINT ops_log_levels CHECK (level IN ('info','warning','error','critical'))");
        DB::statement("ALTER TABLE ops_jobs ADD CONSTRAINT ops_job_states CHECK (status IN ('pending','running','completed','failed','dead_letter') AND attempts <= max_attempts AND max_attempts BETWEEN 1 AND 5)");
        DB::statement("ALTER TABLE ops_backups ADD CONSTRAINT ops_restore_evidence CHECK (NOT isolated_restore_verified OR (encrypted AND restored_at IS NOT NULL AND observed_rpo_seconds IS NOT NULL AND observed_rto_seconds IS NOT NULL AND proof_ref IS NOT NULL))");
        DB::statement("ALTER TABLE ops_deployments ADD CONSTRAINT ops_release_states CHECK (status IN ('draft','approved','blocked'))");
        DB::statement("ALTER TABLE ops_deployments ADD CONSTRAINT ops_release_approval CHECK (status <> 'approved' OR (sbom_verified AND provenance_verified AND staging_smoke_passed AND migration_compatible AND backup_id IS NOT NULL AND decision_ref IS NOT NULL AND approved_at IS NOT NULL))");
    }

    public function down(): void
    {
        // Retention and audit evidence may never be silently dropped.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Refus de supprimer les preuves du module M18. Utiliser une migration de correction.');
            }
        }
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
    }
};
