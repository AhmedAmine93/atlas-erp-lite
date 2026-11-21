<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('purchase_requests', function (Blueprint $t) {
            $t->unique(['company_id', 'id'], 'purchase_req_company_id_unique');
        });
        Schema::create('purchase_approval_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('purchase_request_id');
            $t->foreign(['company_id', 'purchase_request_id'], 'purchase_event_tenant_fk')
                ->references(['company_id', 'id'])->on('purchase_requests')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('from_status', 24);
            $t->string('to_status', 24);
            $t->string('reason', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['company_id','purchase_request_id','created_at'], 'purchase_event_history_idx');
        });
    }
    public function down(): void {
        Schema::dropIfExists('purchase_approval_events');
        Schema::table('purchase_requests', fn (Blueprint $t) => $t->dropUnique('purchase_req_company_id_unique'));
    }
};
