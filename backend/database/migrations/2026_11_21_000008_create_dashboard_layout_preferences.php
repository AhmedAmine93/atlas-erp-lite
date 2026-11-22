<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('dashboard_layout_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->unsignedInteger('schema_version')->default(1);
            $table->json('layout');
            $table->timestamps();
            $table->unique(['company_id', 'user_id', 'role_id'], 'dashboard_layout_owner_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('dashboard_layout_preferences');
    }
};
