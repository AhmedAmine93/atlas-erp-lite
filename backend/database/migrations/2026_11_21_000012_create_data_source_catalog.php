<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('data_source_registry', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code', 60);
            $t->string('name', 180);
            $t->string('source_type', 30);
            $t->string('classification', 24)->default('internal');
            $t->string('status', 24)->default('draft');
            $t->string('schema_version', 40)->nullable();
            $t->text('purpose')->nullable();
            // No credentials, endpoints, tokens or arbitrary executable config.
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->index(['company_id', 'source_type', 'status']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE data_source_registry ADD CONSTRAINT data_source_type_check CHECK (source_type IN ('api','postgresql','oracle','csv','pms','kafka'))");
            DB::statement("ALTER TABLE data_source_registry ADD CONSTRAINT data_source_status_check CHECK (status IN ('draft','validated','disabled'))");
            DB::statement("ALTER TABLE data_source_registry ADD CONSTRAINT data_source_classification_check CHECK (classification IN ('public','internal','confidential','restricted'))");
        }
    }
    public function down(): void { Schema::dropIfExists('data_source_registry'); }
};
