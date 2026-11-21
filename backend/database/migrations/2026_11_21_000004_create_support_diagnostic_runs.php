<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_diagnostic_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('support_incident_id')->constrained('support_incidents')->restrictOnDelete();
            $table->foreignId('triggered_by')->constrained('users')->restrictOnDelete();
            $table->string('check_name', 40);
            $table->string('status', 16);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();
            $table->index(['company_id', 'support_incident_id', 'id'], 'support_diag_tenant_incident_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_diagnostic_runs');
    }
};
