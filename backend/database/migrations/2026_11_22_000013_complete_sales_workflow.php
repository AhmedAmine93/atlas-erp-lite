<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const IMMUTABLE = ['sales_delivery_lines', 'sales_contract_versions', 'sales_credit_notes', 'sales_idempotency_records'];

    public function up(): void
    {
        foreach (['company_user', 'quotes', 'orders'] as $name) {
            Schema::table($name, function (Blueprint $t) use ($name) {
                $t->foreignId('sales_establishment_id')->nullable()->constrained('establishments')->restrictOnDelete();
                $t->index(['company_id','sales_establishment_id']);
                if ($name === 'company_user') $t->unsignedInteger('sales_discount_limit_bps')->default(0);
            });
        }
        Schema::table('sales_contracts', function (Blueprint $t) {
            $t->json('prices')->nullable();
            $t->boolean('receipt_required')->default(false);
        });
        Schema::table('sales_idempotency_records', function (Blueprint $t) {
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('scope_site_id')->nullable();
        });
        Schema::table('sales_deliveries', function (Blueprint $t) {
            $t->text('exception_reason')->nullable();
            $t->timestamp('reported_lost_at')->nullable();
            $t->foreignId('reported_lost_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        if (DB::getDriverName() !== 'pgsql') return;
        foreach (['company_user', 'quotes', 'orders'] as $table) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT m03_{$table}_site_fk FOREIGN KEY (company_id, sales_establishment_id) REFERENCES establishments(company_id, id)");
        }
        DB::statement('ALTER TABLE company_user ADD CONSTRAINT m03_delegation_bounds CHECK (sales_discount_limit_bps BETWEEN 0 AND 10000)');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('draft','confirmed','reserved','prepared','partially_shipped','shipped','closed','cancelled','validated'))");
        foreach (self::IMMUTABLE as $table) {
            DB::statement("REVOKE UPDATE, DELETE ON $table FROM atlas_app_runtime");
        }
    }

    public function down(): void
    {
        if (DB::table('orders')->where('status','prepared')->exists()
            || DB::table('sales_contracts')->whereNotNull('prices')->orWhere('receipt_required',true)->exists()
            || DB::table('sales_deliveries')->whereNotNull('reported_lost_at')->exists()
            || DB::table('sales_idempotency_records')->whereNotNull('actor_id')->exists()
            || DB::table('company_user')->whereNotNull('sales_establishment_id')->orWhere('sales_discount_limit_bps','>',0)->exists()
            || DB::table('orders')->whereNotNull('sales_establishment_id')->exists()
            || DB::table('quotes')->whereNotNull('sales_establishment_id')->exists()) {
            throw new RuntimeException('Les données M03 doivent être archivées avant ce retour arrière.');
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::IMMUTABLE as $table) DB::statement("GRANT UPDATE ON $table TO atlas_app_runtime");
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('draft','confirmed','reserved','partially_shipped','shipped','closed','cancelled','validated'))");
            DB::statement('ALTER TABLE company_user DROP CONSTRAINT m03_delegation_bounds');
            foreach (['company_user','quotes','orders'] as $table) DB::statement("ALTER TABLE $table DROP CONSTRAINT m03_{$table}_site_fk");
        }
        Schema::table('sales_deliveries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reported_lost_by');
            $t->dropColumn(['exception_reason','reported_lost_at']);
        });
        Schema::table('sales_idempotency_records', function (Blueprint $t) {
            $t->dropConstrainedForeignId('actor_id');
            $t->dropColumn('scope_site_id');
        });
        Schema::table('sales_contracts', fn (Blueprint $t) => $t->dropColumn(['prices','receipt_required']));
        Schema::table('company_user', fn (Blueprint $t) => $t->dropColumn('sales_discount_limit_bps'));
        foreach (['orders','quotes','company_user'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->dropIndex(['company_id','sales_establishment_id']);
                $t->dropConstrainedForeignId('sales_establishment_id');
            });
        }
    }
};
