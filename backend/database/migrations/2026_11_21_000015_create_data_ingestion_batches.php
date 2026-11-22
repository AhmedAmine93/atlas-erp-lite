<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('data_source_registry',fn(Blueprint $t)=>$t->unique(['company_id','id']));
        Schema::create('data_ingestion_batches',function(Blueprint $t){
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('source_id');
            $t->string('batch_key',100);
            $t->char('sha256',64);
            $t->unsignedBigInteger('expected_rows');
            $t->string('status',24)->default('registered');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','source_id','batch_key']);
            $t->foreign(['company_id','source_id'])->references(['company_id','id'])
               ->on('data_source_registry')->restrictOnDelete();
        });
        if(DB::getDriverName()==='pgsql'){
            DB::statement("ALTER TABLE data_ingestion_batches ADD CONSTRAINT data_batch_status_check CHECK(status IN ('registered','validated','rejected','processed'))");
            DB::statement("ALTER TABLE data_ingestion_batches ADD CONSTRAINT data_batch_sha_check CHECK(sha256 ~ '^[a-f0-9]{64}$')");
        }
    }
    public function down(): void {
        Schema::dropIfExists('data_ingestion_batches');
        Schema::table('data_source_registry',fn(Blueprint $t)=>$t->dropUnique(['company_id','id']));
    }
};
