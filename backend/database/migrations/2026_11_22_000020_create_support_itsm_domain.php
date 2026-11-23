<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M11 ITSM additive schema. No existing incidents are moved or deleted.
 * Business events and knowledge revisions are append-only at application level.
 */
return new class extends Migration {
    private const TABLES = [
        'support_service_catalog', 'support_cases', 'support_case_events',
        'support_case_incidents', 'support_sla_policies', 'support_sla_escalations',
        'support_knowledge_articles', 'support_knowledge_revisions', 'support_request_replays',
    ];
    private const INSERT_ONLY = [
        'support_case_events', 'support_case_incidents', 'support_knowledge_revisions',
    ];

    public function up(): void
    {
        foreach (['support_n1', 'support_manager', 'support_cab'] as $role) {
            DB::table('roles')->insertOrIgnore(['name' => $role]);
        }

        Schema::create('support_service_catalog', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 60);
            $t->string('title', 180);
            $t->text('description')->nullable();
            $t->boolean('requires_approval')->default(true);
            $t->boolean('sensitive')->default(false);
            $t->string('eligible_role', 40)->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id', 'id']);
        });

        Schema::create('support_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('reference', 40);
            $t->string('kind', 20); // ticket, problem, service, change
            $t->string('title', 180);
            $t->text('description');
            $t->string('status', 24)->default('open');
            $t->string('priority', 2)->default('P3');
            $t->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('catalog_id')->nullable()->constrained('support_service_catalog')->restrictOnDelete();
            $t->unsignedInteger('approved_version')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->string('service_name', 180)->nullable();
            $t->string('risk_level', 12)->nullable();
            $t->timestamp('window_start')->nullable();
            $t->timestamp('window_end')->nullable();
            $t->text('rollback_plan')->nullable();
            $t->text('root_cause')->nullable();
            $t->text('workaround')->nullable();
            $t->text('permanent_fix')->nullable();
            $t->text('result_evidence')->nullable();
            $t->string('pipeline_url', 500)->nullable();
            $t->text('verification')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'reference']);
            $t->unique(['company_id', 'id']);
            $t->index(['company_id', 'kind', 'status', 'priority']);
            $t->index(['company_id', 'assigned_to', 'created_at']);
        });

        Schema::create('support_case_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignId('case_id')->constrained('support_cases')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 40);
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24)->nullable();
            $t->unsignedInteger('version');
            $t->json('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'case_id', 'id']);
        });

        Schema::create('support_case_incidents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignId('case_id')->constrained('support_cases')->restrictOnDelete();
            $t->foreignId('incident_id')->constrained('support_incidents')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['company_id', 'case_id', 'incident_id'], 'support_case_incident_unique');
        });

        Schema::create('support_sla_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('severity', 2);
            $t->unsignedInteger('version');
            $t->unsignedInteger('response_minutes');
            $t->unsignedInteger('resolution_minutes');
            $t->string('calendar', 20)->default('continuous');
            $t->timestamp('effective_from');
            $t->boolean('published')->default(false);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'severity', 'version']);
            $t->index(['company_id', 'severity', 'published', 'effective_from'], 'support_sla_active_lookup');
        });

        Schema::create('support_sla_escalations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignId('incident_id')->constrained('support_incidents')->restrictOnDelete();
            $t->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedTinyInteger('target_level');
            $t->string('status', 16)->default('pending');
            $t->text('reason');
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'created_at']);
        });

        Schema::create('support_knowledge_articles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('title', 180);
            $t->text('content');
            $t->string('classification', 12)->default('support');
            $t->string('status', 20)->default('draft');
            $t->boolean('critical')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('next_review_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->index(['company_id', 'status', 'classification']);
        });

        Schema::create('support_knowledge_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignId('article_id')->constrained('support_knowledge_articles')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('action', 24);
            $t->json('snapshot');
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['company_id', 'article_id', 'version']);
        });

        Schema::create('support_request_replays', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('operation', 100);
            $t->string('idempotency_key', 120);
            $t->string('payload_hash', 64);
            $t->json('response')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'operation', 'idempotency_key'], 'support_replays_unique');
        });

        if (DB::getDriverName() !== 'pgsql') return;

        // Tenant integrity is not delegated to API code or to RLS alone.
        foreach ([
            ['support_cases', 'catalog_id', 'support_service_catalog'],
            ['support_case_events', 'case_id', 'support_cases'],
            ['support_case_incidents', 'case_id', 'support_cases'],
            ['support_case_incidents', 'incident_id', 'support_incidents'],
            ['support_sla_escalations', 'incident_id', 'support_incidents'],
            ['support_knowledge_revisions', 'article_id', 'support_knowledge_articles'],
        ] as [$child, $column, $parent]) {
            DB::statement("ALTER TABLE public.\"{$child}\" ADD CONSTRAINT \"m11_{$child}_{$column}_tenant_fk\" FOREIGN KEY (company_id, \"{$column}\") REFERENCES public.\"{$parent}\" (company_id, id)");
        }

        // Same restricted PostgreSQL runtime as F16/F20. Never grant DELETE.
        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname='atlas_app_runtime'");
        if (! $role || (int) $role->elevated !== 0) {
            throw new RuntimeException('M11 requires the existing restricted atlas_app_runtime database role.');
        }
        foreach (self::TABLES as $table) {
            $rights = in_array($table, self::INSERT_ONLY, true) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
            DB::statement("GRANT {$rights} ON TABLE public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON TABLE public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_m11_runtime_tenant ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", ['public.'.$table]);
            if ($seq && $seq->name) {
                // pg_get_serial_sequence returns a PostgreSQL-generated, schema-qualified name.
                $identifier = implode('.', array_map(fn ($part) => '"'.str_replace('"', '""', $part).'"', explode('.', $seq->name)));
                DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$identifier} TO atlas_app_runtime");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_reverse(self::TABLES) as $table) {
                DB::statement("DROP POLICY IF EXISTS atlas_m11_runtime_tenant ON public.\"{$table}\"");
            }
        }
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
        // Roles are shared global dictionary records and deliberately retained.
    }
};
