<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('data_ingestion_batches', function(Blueprint $t) {
            $t->unique(['company_id','id']);
        });
        Schema::create('data_ingested_csv_rows', function(Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('batch_id');
            $t->string('external_id',80);
            $t->bigInteger('amount_cents');
            $t->timestamps();
            $t->foreign(['company_id','batch_id'])->references(['company_id','id'])
                ->on('data_ingestion_batches')->restrictOnDelete();
            $t->unique(['company_id','batch_id','external_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('data_ingested_csv_rows');
        Schema::table('data_ingestion_batches',fn(Blueprint $t)=>$t->dropUnique(['company_id','id']));
    }
};
