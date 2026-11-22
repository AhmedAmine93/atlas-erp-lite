<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Hotel room blocks require PostgreSQL.');
        }

        Schema::create('hotel_room_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('hotel_id');
            $table->unsignedBigInteger('room_id');
            $table->string('kind', 24); // hold | maintenance | restriction
            $table->string('status', 16)->default('active');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestampTz('expires_at')->nullable(); // only for expiring holds
            $table->string('reason', 300);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();
            $table->foreign(['company_id', 'hotel_id', 'room_id'])
                ->references(['company_id', 'hotel_id', 'id'])
                ->on('hotel_rooms')->restrictOnDelete();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'hotel_id', 'room_id', 'status', 'starts_on', 'ends_on'],
                'hotel_room_blocks_lookup');
        });

        DB::statement("ALTER TABLE hotel_room_blocks ADD CONSTRAINT room_blocks_kind_check
            CHECK (kind IN ('hold','maintenance','restriction'))");
        DB::statement("ALTER TABLE hotel_room_blocks ADD CONSTRAINT room_blocks_status_check
            CHECK (status IN ('active','released'))");
        DB::statement("ALTER TABLE hotel_room_blocks ADD CONSTRAINT room_blocks_dates_check
            CHECK (ends_on > starts_on)");
        DB::statement("ALTER TABLE hotel_room_blocks ADD CONSTRAINT room_blocks_expiration_check
            CHECK ((kind = 'hold' AND expires_at IS NOT NULL) OR
                   (kind <> 'hold' AND expires_at IS NULL))");
        DB::statement("ALTER TABLE hotel_room_blocks ADD CONSTRAINT room_blocks_release_check
            CHECK ((status = 'active' AND released_by IS NULL AND released_at IS NULL) OR
                   (status = 'released' AND released_by IS NOT NULL AND released_at IS NOT NULL))");

        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR
            rolcreaterole OR rolreplication)::int AS elevated
            FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
        if (! $role || (int) $role->elevated !== 0) {
            throw new RuntimeException('Restricted runtime database role required for hotel blocks.');
        }
        DB::statement('GRANT SELECT, INSERT, UPDATE ON public.hotel_room_blocks TO atlas_app_runtime');
        DB::statement('REVOKE DELETE ON public.hotel_room_blocks FROM atlas_app_runtime');
        DB::statement('ALTER TABLE public.hotel_room_blocks ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
CREATE POLICY atlas_f20_runtime_tenant_policy ON public.hotel_room_blocks
FOR ALL TO atlas_app_runtime
USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
SQL);
        $sequence = DB::selectOne("SELECT pg_get_serial_sequence('public.hotel_room_blocks', 'id') AS name");
        if (! $sequence?->name) {
            throw new RuntimeException('Room block sequence missing.');
        }
        DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$sequence->name} TO atlas_app_runtime");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS atlas_f20_runtime_tenant_policy ON public.hotel_room_blocks');
            DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON public.hotel_room_blocks FROM atlas_app_runtime');
            DB::statement('REVOKE USAGE, SELECT ON SEQUENCE public.hotel_room_blocks_id_seq FROM atlas_app_runtime');
        }
        Schema::dropIfExists('hotel_room_blocks');
    }
};
