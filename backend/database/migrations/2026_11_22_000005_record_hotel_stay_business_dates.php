<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hotel_bookings', function (Blueprint $table) {
            $table->date('checked_in_on')->nullable();
            $table->date('checked_out_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_bookings', function (Blueprint $table) {
            $table->dropColumn(['checked_in_on', 'checked_out_on']);
        });
    }
};
