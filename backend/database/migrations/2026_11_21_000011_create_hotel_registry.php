<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('hotel_properties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name', 180);
            $t->string('timezone', 80)->default('Europe/Paris');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id', 'id']);
        });
        Schema::create('hotel_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('hotel_id');
            $t->string('room_number', 30);
            $t->string('floor_code', 30)->nullable();
            $t->string('category', 80);
            $t->string('room_status', 24)->default('available');
            $t->timestamps();
            // Room "101" can exist in multiple hotels but never twice in one.
            $t->foreign(['company_id','hotel_id'])->references(['company_id','id'])
                ->on('hotel_properties')->restrictOnDelete();
            $t->unique(['hotel_id','room_number']);
            $t->index(['company_id','hotel_id','room_status']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE hotel_rooms ADD CONSTRAINT hotel_room_status_check CHECK (room_status IN ('available','out_of_service','blocked'))");
        }
    }
    public function down(): void {
        Schema::dropIfExists('hotel_rooms');
        Schema::dropIfExists('hotel_properties');
    }
};
