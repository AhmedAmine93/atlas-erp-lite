<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('maintenance_requests',function(Blueprint $t){
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('asset_id');
            $t->string('reference',60);
            $t->string('title',180);
            $t->text('description')->nullable();
            $t->string('priority',12)->default('normal');
            $t->string('status',20)->default('new');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','reference']);
            $t->index(['company_id','status','created_at']);
        });
        Schema::table('maintenance_assets',function(Blueprint $t){
            $t->unique(['company_id','id']);
        });
        Schema::table('maintenance_requests',function(Blueprint $t){
            $t->foreign(['company_id','asset_id'])->references(['company_id','id'])
              ->on('maintenance_assets')->restrictOnDelete();
        });
        if(DB::getDriverName()==='pgsql'){
            DB::statement("ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_request_status_check CHECK(status IN ('new','qualified','rejected','closed'))");
            DB::statement("ALTER TABLE maintenance_requests ADD CONSTRAINT maintenance_request_priority_check CHECK(priority IN ('low','normal','high','critical'))");
        }
    }
    public function down(): void {
        Schema::dropIfExists('maintenance_requests');
        Schema::table('maintenance_assets',fn(Blueprint $t)=>$t->dropUnique(['company_id','id']));
    }
};
