<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('maintenance_sites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 50);
            $t->string('name', 180);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->unique(['company_id', 'id']);
        });
        Schema::create('maintenance_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('site_id');
            $t->string('asset_code', 60);
            $t->string('name', 180);
            $t->string('status', 24)->default('in_service');
            $t->date('commissioned_on')->nullable();
            $t->timestamps();
            $t->foreign(['company_id', 'site_id'])->references(['company_id','id'])
                ->on('maintenance_sites')->restrictOnDelete();
            $t->unique(['company_id', 'asset_code']);
            $t->index(['company_id','site_id','status']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE maintenance_assets ADD CONSTRAINT maintenance_asset_state_check CHECK (status IN ('in_service','out_of_service','retired'))");
        }
    }
    public function down(): void {
        Schema::dropIfExists('maintenance_assets');
        Schema::dropIfExists('maintenance_sites');
    }
};
