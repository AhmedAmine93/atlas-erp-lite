<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M16 V5.1: additive, tenant-owned AI registries, evidence and human decisions.
 * No model calls, network requests or business side effects in SQL triggers.
 */
return new class extends Migration {
    private const TABLES = [
        'ai_models', 'ai_agents', 'ai_documents', 'ai_runs', 'ai_anomalies',
        'ai_forecasts', 'ai_suggestions', 'ai_events',
    ];

    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 80); $t->string('name', 160);
            $t->string('version', 60); $t->string('kind', 32)->default('rules');
            $t->string('status', 24)->default('draft');
            $t->string('policy_version', 60);
            $t->text('description')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'code', 'version']);
        });
        Schema::create('ai_agents', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 80); $t->string('name', 160);
            $t->unsignedBigInteger('model_id')->nullable();
            $t->string('status', 24)->default('draft');
            $t->unsignedInteger('configuration_version')->default(1);
            $t->json('allowed_tools'); $t->unsignedInteger('max_steps')->default(1);
            $t->timestamps(); $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'code']);
            $t->foreign(['company_id', 'model_id'])->references(['company_id', 'id'])->on('ai_models')->restrictOnDelete();
        });
        Schema::create('ai_documents', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('establishment_id')->nullable();
            $t->string('title', 180); $t->string('source_type', 60);
            $t->string('source_ref', 240);
            $t->text('excerpt'); $t->string('classification', 24)->default('internal');
            $t->string('status', 24)->default('active');
            $t->timestamp('revoked_at')->nullable(); $t->timestamps();
            $t->unique(['company_id', 'id']); $t->unique(['company_id', 'source_type', 'source_ref']);
            $t->index(['company_id', 'status', 'classification']);
            $t->foreign(['company_id', 'establishment_id'])->references(['company_id', 'id'])->on('establishments')->restrictOnDelete();
        });
        Schema::create('ai_runs', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('agent_id')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('question'); $t->text('answer');
            $t->json('references'); $t->string('status', 32);
            $t->timestamps(); $t->index(['company_id', 'created_at']);
            $t->foreign(['company_id', 'agent_id'])->references(['company_id', 'id'])->on('ai_agents')->restrictOnDelete();
        });
        Schema::create('ai_anomalies', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('model_id');
            $t->string('model_version', 60); $t->string('policy_version', 60);
            $t->string('entity_type', 32); $t->string('entity_ref', 120);
            $t->decimal('score', 8, 6); $t->decimal('threshold', 8, 6);
            $t->json('evidence'); $t->string('status', 24)->default('open');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'created_at']);
            $t->foreign(['company_id', 'model_id'])->references(['company_id', 'id'])->on('ai_models')->restrictOnDelete();
            $t->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('ai_forecasts', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('model_id'); $t->string('model_version', 60);
            $t->date('cutoff_date'); $t->date('horizon_start'); $t->date('horizon_end');
            $t->decimal('predicted_value', 18, 4); $t->decimal('lower_bound', 18, 4);
            $t->decimal('upper_bound', 18, 4); $t->decimal('backtest_mae', 18, 4)->nullable();
            $t->string('training_population', 180); $t->json('backtest_evidence');
            $t->string('status', 20)->default('active');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'status', 'cutoff_date']);
            $t->foreign(['company_id', 'model_id'])->references(['company_id', 'id'])->on('ai_models')->restrictOnDelete();
        });
        Schema::create('ai_suggestions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('idempotency_key', 120); $t->string('payload_sha256', 64);
            $t->string('action_type', 60); $t->json('arguments');
            $t->unsignedBigInteger('cost_cents'); $t->unsignedInteger('revision')->default(1);
            $t->string('policy_version', 60); $t->text('explanation');
            $t->timestamp('expires_at'); $t->string('status', 24)->default('proposed');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('decided_by')->nullable(); $t->timestamp('decided_at')->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable(); $t->timestamp('executed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id']); $t->unique(['company_id', 'idempotency_key']);
            $t->index(['company_id', 'status', 'expires_at']);
            $t->foreign(['company_id', 'work_order_id'])->references(['company_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $t->foreign('decided_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('ai_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('suggestion_id')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('event_type', 80); $t->json('evidence');
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'created_at']);
            $t->foreign(['company_id', 'suggestion_id'])->references(['company_id', 'id'])->on('ai_suggestions')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') return;
        foreach (self::TABLES as $table) {
            $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
            if (!$role || (int) $role->elevated !== 0) throw new RuntimeException('Restricted runtime role required for M16.');
            $rights = $table === 'ai_events' || $table === 'ai_runs' ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
            DB::statement("GRANT {$rights} ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_m16_tenant_policy ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.\"{$table}_id_seq\" TO atlas_app_runtime");
        }
        DB::statement("ALTER TABLE ai_suggestions ADD CONSTRAINT ai_suggestions_status_check CHECK (status IN ('proposed','approved','rejected','executed','expired'))");
        DB::statement("ALTER TABLE ai_anomalies ADD CONSTRAINT ai_anomalies_score_check CHECK (score >= 0 AND score <= 1 AND threshold >= 0 AND threshold <= 1)");
        DB::statement("ALTER TABLE ai_forecasts ADD CONSTRAINT ai_forecasts_interval_check CHECK (lower_bound <= predicted_value AND predicted_value <= upper_bound AND horizon_start > cutoff_date AND horizon_end >= horizon_start)");
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP POLICY IF EXISTS atlas_m16_tenant_policy ON public.\"{$table}\"");
                DB::statement("REVOKE ALL PRIVILEGES ON TABLE public.\"{$table}\" FROM atlas_app_runtime");
                DB::statement("REVOKE ALL PRIVILEGES ON SEQUENCE public.\"{$table}_id_seq\" FROM atlas_app_runtime");
            }
            Schema::dropIfExists($table);
        }
    }
};
