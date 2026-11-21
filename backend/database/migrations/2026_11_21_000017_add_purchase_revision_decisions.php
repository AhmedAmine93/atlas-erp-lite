<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('purchase_requests', function (Blueprint $t) {
            $t->unsignedInteger('revision')->default(1);
            $t->text('decision_reason')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('purchase_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('decided_by');
            $t->dropColumn(['revision','decision_reason','decided_at']);
        });
    }
};
