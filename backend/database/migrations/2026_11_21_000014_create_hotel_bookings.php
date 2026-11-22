<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('hotel_bookings',function(Blueprint $t){
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('hotel_id');
            $t->unsignedBigInteger('room_id');
            $t->string('reference',60);
            $t->string('guest_display_name',180);
            $t->date('arrival_date');
            $t->date('departure_date');
            $t->string('status',24)->default('draft');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','hotel_id','reference']);
            $t->index(['company_id','hotel_id','arrival_date','departure_date']);
        });
        Schema::table('hotel_rooms',function(Blueprint $t){
            $t->unique(['company_id','hotel_id','id']);
        });
        Schema::table('hotel_bookings',function(Blueprint $t){
            $t->foreign(['company_id','hotel_id'])->references(['company_id','id'])
              ->on('hotel_properties')->restrictOnDelete();
            $t->foreign(['company_id','hotel_id','room_id'])->references(['company_id','hotel_id','id'])
              ->on('hotel_rooms')->restrictOnDelete();
        });
        if(DB::getDriverName()==='pgsql'){
            DB::statement("ALTER TABLE hotel_bookings ADD CONSTRAINT hotel_bookings_dates_check CHECK(departure_date > arrival_date)");
            DB::statement("ALTER TABLE hotel_bookings ADD CONSTRAINT hotel_bookings_status_check CHECK(status IN ('draft','confirmed','cancelled','checked_in','checked_out','no_show'))");
        }
    }
    public function down(): void {
        Schema::dropIfExists('hotel_bookings');
        Schema::table('hotel_rooms',fn(Blueprint $t)=>$t->dropUnique(['company_id','hotel_id','id']));
    }
};
