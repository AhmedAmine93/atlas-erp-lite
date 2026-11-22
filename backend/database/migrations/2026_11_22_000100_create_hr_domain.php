<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M09: isolated, additive PostgreSQL HR schema.
 * All business references are constrained to the same company at SQL level.
 * Private medical attachments and compensation are intentionally NOT stored here.
 */
return new class extends Migration {
    private const TABLES = [
        'hr_employees', 'hr_teams', 'hr_assignments', 'hr_schedules',
        'hr_leave_balances', 'hr_leave_requests', 'hr_time_entries',
        'hr_skills', 'hr_employee_skills', 'hr_events', 'hr_request_keys',
    ];
    private function tenant(Blueprint $t): void {
        $t->id();
        $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
    }
    private function sameCompany(Blueprint $t, string $column, string $parent): void {
        $t->foreign(['company_id', $column])->references(['company_id', 'id'])
            ->on($parent)->restrictOnDelete();
    }
    public function up(): void {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Le module RH nécessite PostgreSQL et ses contraintes de concurrence.');
        }
        DB::table('roles')->updateOrInsert(['name' => 'hr'], ['name' => 'hr']);
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS hr_establishments_tenant_id_uq ON establishments(company_id,id)');
        Schema::create('hr_employees', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('code', 60);
            $t->string('given_name', 100);
            $t->string('family_name', 100);
            $t->string('email', 180)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('establishment_id');
            $t->date('hired_on');
            $t->date('left_on')->nullable();
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id','id']);
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $this->sameCompany($t, 'establishment_id', 'establishments');
        });
        Schema::create('hr_teams', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('code', 60);
            $t->string('name', 180);
            $t->unsignedBigInteger('manager_employee_id')->nullable();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'code']);
            $this->sameCompany($t, 'manager_employee_id', 'hr_employees');
        });
        Schema::create('hr_assignments', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id');
            $t->unsignedBigInteger('establishment_id');
            $t->unsignedBigInteger('team_id')->nullable();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->unsignedSmallInteger('allocation_percent')->default(100);
            $t->timestamps();
            $t->unique(['company_id','id']);
            $t->index(['company_id','employee_id','starts_on']);
            $this->sameCompany($t,'employee_id','hr_employees');
            $this->sameCompany($t,'establishment_id','establishments');
            $this->sameCompany($t,'team_id','hr_teams');
        });
        Schema::create('hr_schedules', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id');
            $t->unsignedBigInteger('establishment_id');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('timezone', 64);
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['company_id','employee_id','starts_at']);
            $t->unique(['company_id','id']);
            $this->sameCompany($t,'employee_id','hr_employees');
            $this->sameCompany($t,'establishment_id','establishments');
        });
        Schema::create('hr_leave_balances', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id');
            $t->unsignedSmallInteger('year');
            $t->decimal('allocated_days',7,2);
            $t->decimal('used_days',7,2)->default(0);
            $t->timestamps();
            $t->unique(['company_id','employee_id','year']);
            $this->sameCompany($t,'employee_id','hr_employees');
        });
        Schema::create('hr_leave_requests', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->decimal('days',7,2);
            $t->unsignedSmallInteger('year');
            $t->string('status',20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('decision_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['company_id','employee_id','starts_on']);
            $t->unique(['company_id','id']);
            $this->sameCompany($t,'employee_id','hr_employees');
        });
        Schema::create('hr_time_entries', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id');
            $t->date('work_on');
            $t->unsignedSmallInteger('duration_minutes');
            $t->string('project_ref', 80)->nullable();
            $t->unsignedBigInteger('work_order_id')->nullable();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','id']);
            $t->index(['company_id','employee_id','work_on']);
            $this->sameCompany($t,'employee_id','hr_employees');
            $this->sameCompany($t,'work_order_id','maintenance_work_orders');
        });
        Schema::create('hr_skills', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('code', 60);
            $t->string('name', 180);
            $t->boolean('safety_critical')->default(false);
            $t->timestamps();
            $t->unique(['company_id','code']);
            $t->unique(['company_id','id']);
        });
        Schema::create('hr_employee_skills', function (Blueprint $t) {
            $this->tenant($t);
            $t->unsignedBigInteger('employee_id');
            $t->unsignedBigInteger('skill_id');
            $t->date('obtained_on');
            $t->date('expires_on')->nullable();
            $t->string('issuer', 180)->nullable();
            $t->string('evidence_ref', 180)->nullable();
            $t->string('status', 20)->default('pending');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','id']);
            $t->index(['company_id','employee_id','expires_on']);
            $this->sameCompany($t,'employee_id','hr_employees');
            $this->sameCompany($t,'skill_id','hr_skills');
        });
        Schema::create('hr_events', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('entity_type', 40);
            $t->unsignedBigInteger('entity_id');
            $t->string('operation', 80);
            $t->string('from_state', 30)->nullable();
            $t->string('to_state', 30)->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 500)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['company_id','entity_type','entity_id','created_at']);
        });
        Schema::create('hr_request_keys', function (Blueprint $t) {
            $this->tenant($t);
            $t->string('operation', 80);
            $t->string('request_key', 100);
            $t->char('payload_hash', 64);
            $t->unsignedBigInteger('result_id')->nullable();
            $t->timestamps();
            $t->unique(['company_id','operation','request_key']);
        });
        foreach ([
          "ALTER TABLE hr_employees ADD CONSTRAINT hr_employee_dates_ck CHECK (left_on IS NULL OR left_on >= hired_on)",
          "ALTER TABLE hr_teams ADD CONSTRAINT hr_team_dates_ck CHECK (effective_to IS NULL OR effective_to >= effective_from)",
          "ALTER TABLE hr_assignments ADD CONSTRAINT hr_assignment_dates_ck CHECK (ends_on IS NULL OR ends_on >= starts_on)",
          "ALTER TABLE hr_assignments ADD CONSTRAINT hr_assignment_capacity_ck CHECK (allocation_percent BETWEEN 1 AND 100)",
          "ALTER TABLE hr_schedules ADD CONSTRAINT hr_schedule_dates_ck CHECK (ends_at > starts_at)",
          "ALTER TABLE hr_schedules ADD CONSTRAINT hr_schedule_status_ck CHECK (status IN ('draft','published','cancelled'))",
          "ALTER TABLE hr_leave_balances ADD CONSTRAINT hr_leave_balance_ck CHECK (allocated_days >= 0 AND used_days >= 0 AND used_days <= allocated_days)",
          "ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_dates_ck CHECK (ends_on >= starts_on AND days > 0 AND EXTRACT(YEAR FROM starts_on) = year AND EXTRACT(YEAR FROM ends_on) = year)",
          "ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_status_ck CHECK (status IN ('draft','submitted','approved','rejected','cancelled'))",
          "ALTER TABLE hr_time_entries ADD CONSTRAINT hr_time_duration_ck CHECK (duration_minutes BETWEEN 1 AND 1440)",
          "ALTER TABLE hr_time_entries ADD CONSTRAINT hr_time_source_ck CHECK ((project_ref IS NOT NULL)::int + (work_order_id IS NOT NULL)::int = 1)",
          "ALTER TABLE hr_time_entries ADD CONSTRAINT hr_time_status_ck CHECK (status IN ('draft','submitted','approved','returned','transferred'))",
          "ALTER TABLE hr_employee_skills ADD CONSTRAINT hr_skill_dates_ck CHECK (expires_on IS NULL OR expires_on >= obtained_on)",
          "ALTER TABLE hr_employee_skills ADD CONSTRAINT hr_skill_status_ck CHECK (status IN ('pending','approved','revoked'))",
        ] as $sql) DB::statement($sql);
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement("ALTER TABLE hr_schedules ADD CONSTRAINT hr_no_published_overlaps EXCLUDE USING gist (company_id WITH =, employee_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (status = 'published')");
        DB::statement("ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_no_approved_leave_overlap EXCLUDE USING gist (company_id WITH =, employee_id WITH =, daterange(starts_on, ends_on + 1, '[)') WITH &&) WHERE (status = 'approved')");
        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
        if (! $role || (int)$role->elevated !== 0) throw new RuntimeException('A restricted atlas_app_runtime role is required.');
        foreach (self::TABLES as $name) {
            DB::statement("GRANT SELECT, INSERT, UPDATE ON TABLE public.\"{$name}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON TABLE public.\"{$name}\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$name}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_f20_runtime_tenant_policy ON public.\"{$name}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS sequence_name", ["public.{$name}"]);
            if ($seq?->sequence_name) DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$seq->sequence_name} TO atlas_app_runtime");
        }
    }
    public function down(): void {
        foreach (array_reverse(self::TABLES) as $name) Schema::dropIfExists($name);
        DB::statement('DROP INDEX IF EXISTS hr_establishments_tenant_id_uq');
        // Existing role assignments are never silently removed on rollback.
    }
};
