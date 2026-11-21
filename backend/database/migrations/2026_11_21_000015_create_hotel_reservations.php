<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('hotel_rooms', function (Blueprint $t) {
            $t->unique(['company_id','hotel_id','id'], 'hotel_room_tenant_hotel_key');
        });
        Schema::create('hotel_reservations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('hotel_id');
            $t->unsignedBigInteger('room_id');
            $t->string('reference', 40);
            $t->string('guest_name', 160);
            $t->date('arrival_on');
            $t->date('departure_on');
            $t->string('channel', 20)->default('direct');
            $t->string('status', 24)->default('confirmed');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['company_id','hotel_id','room_id'], 'hotel_booking_room_fk')
                ->references(['company_id','hotel_id','id'])->on('hotel_rooms')->restrictOnDelete();
            $t->unique(['company_id','reference'], 'hotel_booking_reference_unique');
            $t->index(['company_id','hotel_id','status','arrival_on'], 'hotel_booking_hotel_dates_idx');
            $t->index(['company_id','room_id','arrival_on','departure_on'], 'hotel_booking_room_dates_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE hotel_reservations ADD CONSTRAINT hotel_booking_dates_check CHECK (arrival_on < departure_on)");
            DB::statement("ALTER TABLE hotel_reservations ADD CONSTRAINT hotel_booking_status_check CHECK (status IN ('confirmed','cancelled'))");
            DB::statement("ALTER TABLE hotel_reservations ADD CONSTRAINT hotel_booking_channel_check CHECK (channel = 'direct')");
            // Defense-in-depth: even manual inserts cannot double-book an active room.
            // btree_gist is a trusted PostgreSQL extension.
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE hotel_reservations ADD CONSTRAINT hotel_no_active_room_overlap EXCLUDE USING gist (room_id WITH =, daterange(arrival_on, departure_on, '[)') WITH &&) WHERE (status = 'confirmed')");
        }
    }
    public function down(): void {
        Schema::dropIfExists('hotel_reservations');
        Schema::table('hotel_rooms', fn (Blueprint $t) => $t->dropUnique('hotel_room_tenant_hotel_key'));
    }
};
