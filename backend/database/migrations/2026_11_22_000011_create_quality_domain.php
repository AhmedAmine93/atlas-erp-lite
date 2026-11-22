<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M12 V5.1: additive, tenant-owned quality domain. No existing business tables
 * are rewritten. Runtime may INSERT/SELECT measurements and journal events,
 * but cannot change or delete issued evidence.
 */
return new class extends Migration {
    private const TABLES = [
        'quality_plans', 'quality_checks', 'quality_nonconformities',
        'quality_actions', 'quality_audits', 'quality_findings',
        'quality_documents', 'quality_document_versions',
        'quality_read_receipts', 'quality_events', 'quality_request_keys',
    ];
    private const INSERT_ONLY = [
        'quality_checks', 'quality_read_receipts', 'quality_events',
    ];

    private function tenant(Blueprint $t): void {
        $t->id();
        $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
        $t->unique(['company_id', 'id']);
    }

    public function up(): void {
        // Roles have meaning only when explicitly granted through company_user.
        foreach (['quality_manager', 'quality_auditor', 'quality_operator'] as $role) {
            DB::table('roles')->insertOrIgnore(['name' => $role]);
        }

        Schema::create('quality_plans', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('code', 60);
            $t->unsignedInteger('revision');
            $t->string('title', 180);
            $t->string('metric', 100);
            $t->decimal('minimum', 16, 4);
            $t->decimal('maximum', 16, 4);
            $t->unsignedInteger('sample_size');
            $t->date('valid_from');
            $t->date('valid_until')->nullable();
            $t->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'code', 'revision']);
            $t->index(['company_id', 'code', 'valid_from']);
        });
        Schema::create('quality_checks', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('plan_id');
            $t->string('source_type', 24);
            $t->string('source_ref', 100);
            $t->unsignedBigInteger('product_id')->nullable();
            $t->decimal('measured', 16, 4);
            $t->unsignedInteger('inspected_units');
            $t->unsignedInteger('rejected_units')->default(0);
            $t->string('result', 8);
            $t->string('evidence_ref', 255);
            $t->unsignedBigInteger('corrects_check_id')->nullable();
            $t->foreignId('inspected_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['company_id', 'plan_id'])->references(['company_id', 'id'])
                ->on('quality_plans')->restrictOnDelete();
            $t->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])
                ->on('products')->restrictOnDelete();
            $t->index(['company_id', 'source_type', 'source_ref']);
        });
        Schema::table('quality_checks', function (Blueprint $t) {
            $t->foreign(['company_id', 'corrects_check_id'])->references(['company_id', 'id'])
                ->on('quality_checks')->restrictOnDelete();
        });
        Schema::create('quality_nonconformities', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('check_id')->nullable();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('reference', 60);
            $t->string('title', 180);
            $t->string('severity', 16);
            $t->string('defect_code', 80)->nullable();
            $t->text('scope');
            $t->unsignedInteger('quarantine_units')->default(0);
            $t->string('status', 24)->default('open');
            $t->string('decision', 24)->nullable();
            $t->text('closure_reason')->nullable();
            $t->string('closure_evidence', 255)->nullable();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['company_id', 'reference']);
            $t->unique(['company_id', 'check_id']);
            $t->foreign(['company_id', 'check_id'])->references(['company_id', 'id'])
                ->on('quality_checks')->restrictOnDelete();
            $t->foreign(['company_id', 'product_id'])->references(['company_id', 'id'])
                ->on('products')->restrictOnDelete();
            $t->index(['company_id', 'status', 'severity']);
        });
        Schema::create('quality_actions', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('nonconformity_id');
            $t->string('kind', 16);
            $t->string('title', 180);
            $t->text('root_cause')->nullable();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->date('due_on')->nullable();
            $t->string('target_metric', 120)->nullable();
            $t->decimal('target_value', 16, 4)->nullable();
            $t->decimal('measured_value', 16, 4)->nullable();
            $t->string('evidence_ref', 255)->nullable();
            $t->string('status', 24)->default('needs_plan');
            $t->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('escalated_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->foreign(['company_id', 'nonconformity_id'])->references(['company_id', 'id'])
                ->on('quality_nonconformities')->restrictOnDelete();
            $t->index(['company_id', 'status', 'due_on']);
        });
        Schema::create('quality_audits', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('reference', 60);
            $t->string('scope', 255);
            $t->string('standard', 120);
            $t->string('standard_version', 60);
            $t->foreignId('auditor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 24)->default('planned');
            $t->string('closure_evidence', 255)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['company_id', 'reference']);
        });
        Schema::create('quality_findings', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('audit_id');
            $t->string('title', 180);
            $t->string('severity', 16);
            $t->text('description');
            $t->string('evidence_ref', 255);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['company_id', 'audit_id'])->references(['company_id', 'id'])
                ->on('quality_audits')->restrictOnDelete();
        });
        Schema::create('quality_documents', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('code', 60);
            $t->string('title', 180);
            $t->string('country_code', 2);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('quality_document_versions', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('document_id');
            $t->unsignedInteger('revision');
            $t->longText('content');
            $t->char('sha256', 64);
            $t->string('status', 16)->default('draft');
            $t->foreignId('authored_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('effective_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
            $t->foreign(['company_id', 'document_id'])->references(['company_id', 'id'])
                ->on('quality_documents')->restrictOnDelete();
            $t->unique(['company_id', 'document_id', 'revision'], 'quality_doc_revision_unique');
            $t->index(['company_id', 'status', 'effective_at']);
        });
        Schema::create('quality_read_receipts', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('version_id');
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->char('sha256', 64);
            $t->timestamp('acknowledged_at');
            $t->foreign(['company_id', 'version_id'])->references(['company_id', 'id'])
                ->on('quality_document_versions')->restrictOnDelete();
            $t->unique(['company_id', 'version_id', 'user_id'], 'quality_reads_once');
        });
        Schema::create('quality_events', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('entity_type', 40);
            $t->unsignedBigInteger('entity_id');
            $t->string('action', 80);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->json('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'entity_type', 'entity_id', 'created_at'], 'quality_events_lookup');
        });
        Schema::create('quality_request_keys', function (Blueprint $t) {
            $this->tenant($t);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('operation', 120);
            $t->string('request_key', 128);
            $t->char('payload_sha256', 64);
            $t->json('result')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'actor_id', 'operation', 'request_key'], 'quality_requests_unique');
        });

        if (DB::getDriverName() !== 'pgsql') return;
        $checks = [
            'quality_plans' => "(revision > 0 AND sample_size > 0 AND minimum <= maximum AND (valid_until IS NULL OR valid_until >= valid_from))",
            'quality_checks' => "(inspected_units > 0 AND rejected_units <= inspected_units AND result IN ('pass','fail') AND source_type IN ('lot','receipt','production','import'))",
            'quality_nonconformities' => "(severity IN ('minor','major','critical') AND status IN ('open','contained','closed') AND (status <> 'closed' OR (closure_reason IS NOT NULL AND closure_evidence IS NOT NULL AND closed_by IS NOT NULL)))",
            'quality_actions' => "(kind IN ('corrective','preventive') AND status IN ('needs_plan','planned','implemented','effective','ineffective'))",
            'quality_audits' => "(status IN ('planned','in_progress','closed') AND ends_on >= starts_on)",
            'quality_findings' => "(severity IN ('minor','major','critical'))",
            'quality_document_versions' => "(status IN ('draft','review','approved','published','obsolete') AND revision > 0)",
        ];
        foreach ($checks as $table => $condition) {
            DB::statement("ALTER TABLE public.\"{$table}\" ADD CONSTRAINT {$table}_quality_ck CHECK {$condition}");
        }
        // This is a short technical guard only: never overwrite signed evidence.
        DB::statement(<<<'SQL'
CREATE FUNCTION atlas_quality_immutable() RETURNS trigger LANGUAGE plpgsql AS $body$
BEGIN
    RAISE EXCEPTION 'M12: issued quality evidence is immutable';
END
$body$
SQL);
        foreach (self::INSERT_ONLY as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON public.\"{$table}\" FOR EACH ROW EXECUTE FUNCTION atlas_quality_immutable()");
        }
        foreach (self::TABLES as $table) {
            $rights = in_array($table, self::INSERT_ONLY, true) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
            DB::statement("GRANT {$rights} ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            if (in_array($table, self::INSERT_ONLY, true)) DB::statement("REVOKE UPDATE ON public.\"{$table}\" FROM atlas_app_runtime");
            $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS seq", ['public.'.$table]);
            if ($seq?->seq) DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$seq->seq} TO atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_quality_company ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
        }
    }

    public function down(): void {
        // Never silently erase regulated records on a real database.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("M12 rollback refused: {$table} contains retained quality records.");
            }
        }
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
        if (DB::getDriverName() === 'pgsql') DB::statement('DROP FUNCTION IF EXISTS atlas_quality_immutable()');
    }
};
