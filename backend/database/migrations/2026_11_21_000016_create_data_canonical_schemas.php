<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('data_source_registry', function (Blueprint $t) {
            $t->unique(['company_id','id'], 'data_source_tenant_id_key');
        });
        Schema::create('data_canonical_schemas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('source_id');
            $t->unsignedInteger('version');
            $t->string('name', 120);
            $t->json('fields');
            $t->string('fingerprint', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->foreign(['company_id','source_id'], 'data_schema_source_tenant_fk')
                ->references(['company_id','id'])->on('data_source_registry')->restrictOnDelete();
            $t->unique(['company_id','source_id','version'], 'data_schema_tenant_version_unique');
            $t->index(['company_id','source_id','created_at'], 'data_schema_history_idx');
        });
    }
    public function down(): void {
        Schema::dropIfExists('data_canonical_schemas');
        Schema::table('data_source_registry', fn (Blueprint $t) => $t->dropUnique('data_source_tenant_id_key'));
    }
};
