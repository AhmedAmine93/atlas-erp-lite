<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P052 / P068: tenant-scoped technicians, teams and clash-free work-order slots.
 * Uses PostgreSQL exclusion constraints to resolve concurrent scheduling races
 * at the database boundary, not only at controller level.
 */
return new class extends Migration
{
    private const TABLES = [
        'maintenance_teams', 'maintenance_technicians', 'maintenance_schedules',
    ];

    public function up(): void
    {
        // PostgreSQL requires a UNIQUE key matching the full composite FK target.
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->unique(['company_id', 'id']);
        });
        Schema::create('maintenance_teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name', 180);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id']);
        });
        Schema::create('maintenance_technicians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('code', 60);
            $table->string('name', 180);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'team_id'])
                ->references(['company_id', 'id'])->on('maintenance_teams')->restrictOnDelete();
            $table->index(['company_id', 'team_id', 'active']);
        });
        Schema::create('maintenance_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('technician_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 16)->default('scheduled');
            $table->foreignId('planned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 300)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'work_order_id'])
                ->references(['company_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
            $table->foreign(['company_id', 'technician_id'])
                ->references(['company_id', 'id'])->on('maintenance_technicians')->restrictOnDelete();
            $table->index(['company_id', 'starts_at', 'ends_at']);
            $table->index(['company_id', 'technician_id', 'starts_at']);
        });
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('P068 scheduling requires PostgreSQL exclusion constraints.');
        }

        DB::statement("ALTER TABLE maintenance_schedules ADD CONSTRAINT maintenance_slot_valid CHECK (ends_at > starts_at)");
        DB::statement("ALTER TABLE maintenance_schedules ADD CONSTRAINT maintenance_schedule_status_check CHECK (status IN ('scheduled','cancelled'))");
        DB::statement("ALTER TABLE maintenance_schedules ADD CONSTRAINT maintenance_cancel_metadata_check CHECK ((status = 'scheduled' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL) OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND LENGTH(TRIM(cancellation_reason)) >= 10))");
        // An order can only have one current technician assignment. Cancel to reschedule.
        DB::statement("CREATE UNIQUE INDEX maintenance_one_active_order_slot ON maintenance_schedules (company_id, work_order_id) WHERE status = 'scheduled'");
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        // Enforced during concurrent INSERTs even from independent connections.
        DB::statement(<<<'SQL'
ALTER TABLE maintenance_schedules
ADD CONSTRAINT maintenance_technician_no_slot_overlap
EXCLUDE USING gist (
    company_id WITH =,
    technician_id WITH =,
    tstzrange(starts_at, ends_at, '[)') WITH &&
) WHERE (status = 'scheduled')
SQL);

        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
        if (! $role || (int) $role->elevated !== 0) {
            throw new RuntimeException('Restricted F20 PostgreSQL runtime role is required.');
        }

        foreach (self::TABLES as $table) {
            DB::statement("GRANT SELECT, INSERT, UPDATE ON TABLE public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON TABLE public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
CREATE POLICY atlas_f20_runtime_tenant_policy ON public."{$table}"
FOR ALL TO atlas_app_runtime
USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
SQL);
            $sequence = DB::selectOne(
                "SELECT pg_get_serial_sequence(?, 'id') AS sequence_name",
                ["public.{$table}"]
            );
            if (! $sequence || ! $sequence->sequence_name) {
                throw new RuntimeException("Expected id sequence for {$table}");
            }
            // This identifier comes exclusively from PostgreSQL metadata for a fixed allowlist.
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$sequence->sequence_name} TO atlas_app_runtime");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                $sequence = DB::selectOne(
                    "SELECT pg_get_serial_sequence(?, 'id') AS sequence_name",
                    ["public.{$table}"]
                );
                if ($sequence && $sequence->sequence_name) {
                    DB::statement("REVOKE USAGE, SELECT ON SEQUENCE {$sequence->sequence_name} FROM atlas_app_runtime");
                }
            }
        }
        Schema::dropIfExists('maintenance_schedules');
        Schema::dropIfExists('maintenance_technicians');
        Schema::dropIfExists('maintenance_teams');
        Schema::table('maintenance_work_orders', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'id']);
        });
        // Keep btree_gist: other schemas may also depend on it.
    }
};
