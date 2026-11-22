<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('reference', 50);
            $t->string('title', 180);
            $t->text('justification')->nullable();
            $t->unsignedBigInteger('estimated_total_cents')->default(0);
            $t->string('currency', 3)->default('EUR');
            $t->string('status', 24)->default('draft');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'reference']);
            $t->index(['company_id', 'status', 'created_at']);
        });
        // No approval or order is implied by a draft request.
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_request_state_check CHECK (status IN ('draft','submitted','rejected','approved','cancelled'))");
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_request_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        }
    }
    public function down(): void { Schema::dropIfExists('purchase_requests'); }
};
