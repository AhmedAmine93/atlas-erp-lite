<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['support_n2', 'support_n3'] as $name) {
            DB::table('roles')->insertOrIgnore(['name' => $name]);
        }

        // The legacy PR #11 was not merged; create a tenant-safe table directly.
        Schema::create('support_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->string('title', 180);
            $table->text('description');
            $table->string('severity', 2)->default('P3');
            $table->string('status', 20)->default('open');
            $table->string('environment', 20)->default('demo');
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'severity'], 'support_incidents_company_status_severity');
            $table->index(['company_id', 'created_at'], 'support_incidents_company_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_incidents');
        // Keep role definitions to avoid breaking existing membership FKs.
    }
};
