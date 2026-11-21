<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_incidents', function (Blueprint $table) {
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->unsignedTinyInteger('escalation_level')->default(2);
            $table->index(['company_id', 'resolution_due_at'], 'support_incidents_company_sla');
        });

        Schema::create('support_incident_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('support_incident_id')->constrained('support_incidents')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'support_incident_id', 'id'], 'support_events_tenant_incident');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_incident_events');
        Schema::table('support_incidents', function (Blueprint $table) {
            $table->dropIndex('support_incidents_company_sla');
            $table->dropColumn([
                'response_due_at', 'resolution_due_at', 'first_responded_at',
                'closed_at', 'escalated_at', 'escalation_level',
            ]);
        });
    }
};
