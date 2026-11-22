<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['maintenance_preventive_plans', 'maintenance_preventive_occurrences'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Preventive maintenance requires PostgreSQL.');
        }

        Schema::create('maintenance_preventive_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('asset_id');
            $table->string('code', 60);
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('priority', 12)->default('normal');
            $table->unsignedInteger('interval_days');
            $table->date('next_due_on');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'asset_id'])
                ->references(['company_id', 'id'])->on('maintenance_assets')->restrictOnDelete();
            $table->index(['company_id', 'active', 'next_due_on']);
        });
        DB::statement("ALTER TABLE maintenance_preventive_plans ADD CONSTRAINT preventive_interval_check CHECK (interval_days BETWEEN 1 AND 3650)");
        DB::statement("ALTER TABLE maintenance_preventive_plans ADD CONSTRAINT preventive_priority_check CHECK (priority IN ('low','normal','high','critical'))");

        Schema::create('maintenance_preventive_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->date('due_on');
            $table->unsignedBigInteger('maintenance_request_id');
            $table->unsignedBigInteger('work_order_id');
            $table->timestamps();
            $table->unique(['company_id', 'plan_id', 'due_on'], 'preventive_occurrence_unique');
            $table->foreign(['company_id', 'plan_id'])
                ->references(['company_id', 'id'])->on('maintenance_preventive_plans')->restrictOnDelete();
            $table->foreign(['company_id', 'maintenance_request_id'])
                ->references(['company_id', 'id'])->on('maintenance_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'work_order_id'])
                ->references(['company_id', 'id'])->on('maintenance_work_orders')->restrictOnDelete();
        });

        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
        if (! $role || (int) $role->elevated !== 0) {
            throw new RuntimeException('Expected restricted atlas_app_runtime role.');
        }
        foreach (self::TABLES as $table) {
            DB::statement("GRANT SELECT, INSERT, UPDATE ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
CREATE POLICY atlas_f20_runtime_tenant_policy ON public."{$table}"
FOR ALL TO atlas_app_runtime
USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
SQL);
            $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", ["public.{$table}"]);
            if (! $sequence?->name) {
                throw new RuntimeException("Sequence missing for {$table}.");
            }
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$sequence->name} TO atlas_app_runtime");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", ["public.{$table}"]);
                if ($sequence?->name) {
                    DB::statement("REVOKE USAGE, SELECT ON SEQUENCE {$sequence->name} FROM atlas_app_runtime");
                }
            }
        }
        Schema::dropIfExists('maintenance_preventive_occurrences');
        Schema::dropIfExists('maintenance_preventive_plans');
    }
};
