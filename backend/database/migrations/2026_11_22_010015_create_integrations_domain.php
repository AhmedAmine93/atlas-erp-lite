<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M15: additive, tenant-owned integration records. Secrets are always ciphertext,
 * never loggable domain fields. Real provider adapters remain disabled until certified.
 */
return new class extends Migration {
    private const TABLES = [
        'integration_connectors', 'integration_webhook_receipts',
        'integration_exchange_batches', 'integration_jobs',
        'integration_job_runs', 'integration_dead_letters',
        'integration_devices', 'integration_telemetry',
        'integration_outbox', 'integration_audit_events',
    ];

    public function up(): void
    {
        Schema::create('integration_connectors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 60);
            $t->string('name', 180);
            $t->string('provider', 40)->default('mock');
            $t->string('status', 20)->default('draft');
            $t->unsignedBigInteger('owner_id');
            $t->text('secret_ciphertext')->nullable();
            $t->unsignedInteger('secret_version')->default(0);
            $t->timestampTz('sandbox_passed_at')->nullable();
            $t->unsignedInteger('quota_per_minute')->default(30);
            $t->unsignedSmallInteger('timeout_seconds')->default(10);
            $t->timestamps();
            $t->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['company_id','id']);
            $t->unique(['company_id','code']);
            $t->index(['company_id','status']);
        });
        Schema::create('integration_webhook_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('connector_id');
            $t->string('event_id', 120);
            $t->string('event_type', 80);
            $t->char('payload_sha256',64);
            $t->string('status', 25)->default('accepted');
            $t->timestamps();
            $t->foreign(['company_id','connector_id'])->references(['company_id','id'])->on('integration_connectors')->restrictOnDelete();
            $t->unique(['company_id','connector_id','event_id'],'m15_webhook_dedup');
            $t->index(['company_id','created_at']);
        });
        Schema::create('integration_exchange_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('kind', 10);
            $t->string('mapping_version', 30);
            $t->char('sha256',64);
            $t->string('status', 20);
            $t->unsignedInteger('accepted_rows')->default(0);
            $t->unsignedInteger('rejected_rows')->default(0);
            $t->json('report');
            $t->timestamps();
            $t->unique(['company_id','kind','mapping_version','sha256'],'m15_exchange_dedup');
        });
        Schema::create('integration_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 60);
            $t->string('name', 180);
            $t->string('task', 40)->default('health_snapshot');
            $t->string('cadence', 20);
            $t->string('timezone', 80);
            $t->boolean('suspended')->default(true);
            $t->unsignedBigInteger('owner_id');
            $t->timestamps();
            $t->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['company_id','id']);
            $t->unique(['company_id','code']);
        });
        Schema::create('integration_job_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('job_id');
            $t->string('window_key',80);
            $t->string('status', 20);
            $t->json('result');
            $t->timestamps();
            $t->foreign(['company_id','job_id'])->references(['company_id','id'])->on('integration_jobs')->restrictOnDelete();
            $t->unique(['company_id','job_id','window_key'],'m15_job_window_unique');
        });
        Schema::create('integration_dead_letters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('connector_id')->nullable();
            $t->string('source_type',40);
            $t->string('source_reference',120);
            $t->string('correlation_id',120);
            $t->string('cause_code',60);
            $t->string('status',20)->default('needs_review');
            $t->unsignedSmallInteger('attempt_count')->default(0);
            $t->string('error_summary',250);
            $t->timestampTz('next_retry_at')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->timestamps();
            $t->foreign(['company_id','connector_id'])->references(['company_id','id'])->on('integration_connectors')->restrictOnDelete();
            $t->index(['company_id','status','created_at']);
        });
        Schema::create('integration_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('site_id');
            $t->string('code',60);
            $t->string('name',180);
            $t->string('unit',24);
            $t->decimal('lower_bound',16,4)->nullable();
            $t->decimal('upper_bound',16,4)->nullable();
            $t->text('secret_ciphertext');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id','id']);
            $t->unique(['company_id','code']);
            $t->foreign(['company_id','site_id'])->references(['company_id','id'])->on('maintenance_sites')->restrictOnDelete();
        });
        Schema::create('integration_telemetry', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('device_id');
            $t->string('event_id',120);
            $t->decimal('value',16,4);
            $t->string('quality',25);
            $t->timestampTz('observed_at');
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign(['company_id','device_id'])->references(['company_id','id'])->on('integration_devices')->restrictOnDelete();
            $t->unique(['company_id','device_id','event_id'],'m15_telemetry_dedup');
            $t->index(['company_id','device_id','observed_at']);
        });
        Schema::create('integration_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('topic',80);
            $t->string('aggregate_kind',50);
            $t->unsignedBigInteger('aggregate_id');
            $t->json('payload');
            $t->string('status',20)->default('pending');
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['company_id','topic','aggregate_kind','aggregate_id'],'m15_outbox_dedup');
        });
        Schema::create('integration_audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('entity_kind',50);
            $t->unsignedBigInteger('entity_id');
            $t->string('action',80);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->json('details');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['company_id','entity_kind','entity_id','created_at'],'m15_audit_lookup');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE integration_connectors ADD CONSTRAINT m15_connector_status_check CHECK (status IN ('draft','active','suspended'))");
            DB::statement("ALTER TABLE integration_connectors ADD CONSTRAINT m15_connector_provider_check CHECK (provider IN ('mock','sirene','microsoft_graph','google_workspace','sap','oracle','sqlserver','pms','psp','iot'))");
            DB::statement("ALTER TABLE integration_jobs ADD CONSTRAINT m15_job_cadence_check CHECK (cadence IN ('hourly','daily'))");
            DB::statement("ALTER TABLE integration_jobs ADD CONSTRAINT m15_job_task_check CHECK (task='health_snapshot')");
            DB::statement("ALTER TABLE integration_dead_letters ADD CONSTRAINT m15_dlq_state_check CHECK (status IN ('needs_review','resolved'))");
            DB::statement("ALTER TABLE integration_devices ADD CONSTRAINT m15_device_bounds_check CHECK (lower_bound IS NULL OR upper_bound IS NULL OR lower_bound <= upper_bound)");
            $role = DB::selectOne("SELECT rolbypassrls,rolsuper FROM pg_roles WHERE rolname='atlas_app_runtime'");
            if (!$role || $role->rolbypassrls || $role->rolsuper) {
                throw new RuntimeException('M15 requires the restricted runtime role (F16/F20).');
            }
            foreach (self::TABLES as $table) {
                $rights = in_array($table,['integration_telemetry','integration_audit_events','integration_webhook_receipts','integration_outbox'],true)
                    ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
                DB::statement("GRANT {$rights} ON public.\"{$table}\" TO atlas_app_runtime");
                DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
                DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY atlas_m15_tenant ON public.\"{$table}\" TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true),'')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true),'')::bigint)");
                $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", ['public.'.$table]);
                if ($sequence?->name) {
                    // pg_get_serial_sequence returns a server-generated identifier for a fixed table.
                    DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$sequence->name} TO atlas_app_runtime");
                }
            }
        }
    }

    public function down(): void
    {
        // Explicit rollback is destructive. Never invoke on production without a backup.
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
