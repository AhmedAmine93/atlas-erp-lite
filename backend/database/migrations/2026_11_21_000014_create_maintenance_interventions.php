<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('maintenance_assets', function (Blueprint $t) {
            $t->unique(['company_id', 'site_id', 'id'], 'maint_assets_site_tenant_key');
        });
        Schema::create('maintenance_interventions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('site_id');
            $t->unsignedBigInteger('asset_id')->nullable();
            $t->string('title', 180);
            $t->text('description')->nullable();
            $t->string('category', 24);
            $t->string('priority', 16)->default('normal');
            $t->string('status', 24)->default('new');
            $t->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('qualified_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('qualified_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id'], 'maint_interventions_tenant_id_key');
            $t->foreign(['company_id', 'site_id'], 'maint_intervention_site_fk')
                ->references(['company_id', 'id'])->on('maintenance_sites')->restrictOnDelete();
            // A referenced asset must belong to the *same* site and company.
            $t->foreign(['company_id', 'site_id', 'asset_id'], 'maint_intervention_asset_fk')
                ->references(['company_id', 'site_id', 'id'])->on('maintenance_assets')->restrictOnDelete();
            $t->index(['company_id', 'status', 'priority'], 'maint_intervention_queue_idx');
        });
        Schema::create('maintenance_intervention_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('intervention_id');
            $t->foreign(['company_id', 'intervention_id'], 'maint_intervention_event_fk')
                ->references(['company_id', 'id'])->on('maintenance_interventions')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('from_status', 24)->nullable();
            $t->string('to_status', 24);
            $t->string('reason', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id', 'intervention_id', 'id'], 'maint_intervention_history_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE maintenance_interventions ADD CONSTRAINT maint_intervention_category_check CHECK (category IN ('breakdown','inspection','preventive','other'))");
            DB::statement("ALTER TABLE maintenance_interventions ADD CONSTRAINT maint_intervention_priority_check CHECK (priority IN ('low','normal','high','critical'))");
            DB::statement("ALTER TABLE maintenance_interventions ADD CONSTRAINT maint_intervention_status_check CHECK (status IN ('new','qualified','accepted','rejected'))");
        }
    }
    public function down(): void {
        Schema::dropIfExists('maintenance_intervention_events');
        Schema::dropIfExists('maintenance_interventions');
        Schema::table('maintenance_assets', fn (Blueprint $t) => $t->dropUnique('maint_assets_site_tenant_key'));
    }
};
