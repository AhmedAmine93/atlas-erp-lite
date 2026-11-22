<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['hotel_housekeeping_tasks', 'hotel_room_status_events'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Housekeeping requires PostgreSQL.');
        }
        Schema::table('hotel_rooms', function (Blueprint $table): void {
            $table->unique(['company_id', 'id']);
        });
        Schema::create('hotel_housekeeping_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('room_id');
            $table->date('business_date');
            $table->string('status', 20)->default('dirty');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('inspected_at')->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'hotel_id'])
                ->references(['company_id', 'id'])->on('hotel_properties')->restrictOnDelete();
            $table->foreign(['company_id', 'room_id'])
                ->references(['company_id', 'id'])->on('hotel_rooms')->restrictOnDelete();
            $table->unique(['company_id', 'room_id', 'business_date'], 'housekeeping_room_date_unique');
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'hotel_id', 'business_date', 'status'], 'housekeeping_daily_index');
        });
        DB::statement("ALTER TABLE hotel_housekeeping_tasks ADD CONSTRAINT housekeeping_task_state_check CHECK (status IN ('dirty','in_progress','clean','inspected'))");
        DB::statement("ALTER TABLE hotel_housekeeping_tasks ADD CONSTRAINT housekeeping_inspection_check CHECK ((status = 'inspected' AND inspected_at IS NOT NULL AND inspected_by IS NOT NULL) OR (status <> 'inspected' AND inspected_at IS NULL AND inspected_by IS NULL))");

        Schema::create('hotel_room_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('room_id');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 300)->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'task_id'])
                ->references(['company_id', 'id'])->on('hotel_housekeeping_tasks')->restrictOnDelete();
            $table->foreign(['company_id', 'room_id'])
                ->references(['company_id', 'id'])->on('hotel_rooms')->restrictOnDelete();
            $table->index(['company_id', 'room_id', 'created_at']);
        });

        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
        if (! $role || (int) $role->elevated !== 0) {
            throw new RuntimeException('Restricted runtime database role required.');
        }

        foreach (self::TABLES as $table) {
            $rights = $table === 'hotel_housekeeping_tasks' ? 'SELECT, INSERT, UPDATE' : 'SELECT, INSERT';
            DB::statement("GRANT {$rights} ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("REVOKE UPDATE ON public.\"hotel_room_status_events\" FROM atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
CREATE POLICY atlas_f20_runtime_tenant_policy ON public."{$table}"
FOR ALL TO atlas_app_runtime
USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
SQL);
            $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name", ["public.{$table}"]);
            if (! $sequence?->name) {
                throw new RuntimeException("Sequence missing for {$table}");
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
        Schema::dropIfExists('hotel_room_status_events');
        Schema::dropIfExists('hotel_housekeeping_tasks');
        Schema::table('hotel_rooms', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'id']);
        });
    }
};
