<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('maintenance_requests', function(Blueprint $t){
            $t->unique(['company_id','id']);
        });
        Schema::create('maintenance_work_orders', function(Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('maintenance_request_id');
            $t->unsignedBigInteger('asset_id');
            $t->string('priority',12);
            $t->string('status',24)->default('open');
            $t->text('summary');
            $t->timestamp('due_at')->nullable();
            $t->text('resolution')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id','maintenance_request_id']);
            $t->foreign(['company_id','maintenance_request_id'])
                ->references(['company_id','id'])->on('maintenance_requests')->restrictOnDelete();
            $t->foreign(['company_id','asset_id'])
                ->references(['company_id','id'])->on('maintenance_assets')->restrictOnDelete();
            $t->index(['company_id','status','due_at']);
        });
        if(DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE maintenance_work_orders ADD CONSTRAINT maintenance_wo_state_check CHECK (status IN ('open','in_progress','completed'))");
            DB::statement("ALTER TABLE maintenance_work_orders ADD CONSTRAINT maintenance_wo_priority_check CHECK (priority IN ('low','normal','high','critical'))");
            DB::statement("ALTER TABLE maintenance_work_orders ADD CONSTRAINT maintenance_wo_resolution_check CHECK ((status <> 'completed') OR (resolution IS NOT NULL AND completed_at IS NOT NULL))");
        }
    }
    public function down(): void {
        Schema::dropIfExists('maintenance_work_orders');
        Schema::table('maintenance_requests', fn(Blueprint $t) => $t->dropUnique(['company_id','id']));
    }
};
