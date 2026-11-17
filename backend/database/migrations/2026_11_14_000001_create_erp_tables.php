<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        foreach (['admin', 'commercial', 'stock', 'reader'] as $role) {
            DB::table('roles')->insert(['name' => $role]);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->default(4)->constrained()->restrictOnDelete();
        });
        foreach (['customers', 'suppliers'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name')->index();
                $t->string('email')->unique();
                $t->string('phone', 40)->nullable();
                $t->text('address')->nullable();
                $t->timestamps();
            });
        }
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 50)->unique();
            $t->string('name')->index();
            $t->string('category', 100)->index();
            $t->unsignedInteger('price_cents');
            $t->unsignedInteger('vat_bps')->default(2000);
            $t->integer('stock')->default(0);
            $t->unsignedInteger('alert_threshold')->default(5);
            $t->timestamps();
        });
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
        foreach (['quotes', 'orders'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->string('number')->unique();
                $t->foreignId('customer_id')->constrained()->restrictOnDelete();
                $t->string('status')->default('draft')->index();
                $t->unsignedBigInteger('subtotal_cents');
                $t->unsignedBigInteger('tax_cents');
                $t->unsignedBigInteger('total_cents');
                $t->text('notes')->nullable();
                $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
                if ($table === 'orders') {
                    $t->foreignId('quote_id')->nullable()->unique()->constrained()->restrictOnDelete();
                    $t->timestamp('validated_at')->nullable();
                }
                $t->timestamps();
                $t->index(['customer_id', 'created_at']);
            });
        }
        foreach (['quote_items' => 'quotes', 'order_items' => 'orders'] as $table => $parent) {
            Schema::create($table, function (Blueprint $t) use ($parent) {
                $t->id();
                $t->foreignId($parent === 'quotes' ? 'quote_id' : 'order_id')->constrained($parent)->cascadeOnDelete();
                $t->foreignId('product_id')->constrained()->restrictOnDelete();
                $t->string('reference');
                $t->string('name');
                $t->unsignedInteger('quantity');
                $t->unsignedInteger('price_cents');
                $t->unsignedInteger('vat_bps');
                $t->unsignedBigInteger('subtotal_cents');
                $t->unsignedBigInteger('tax_cents');
                $t->unsignedBigInteger('total_cents');
            });
        }
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $t->string('number')->nullable()->unique();
            $t->string('status')->default('draft')->index();
            $t->json('snapshot');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('tax_cents');
            $t->unsignedBigInteger('total_cents');
            $t->timestamp('issued_at')->nullable()->index();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->integer('quantity');
            $t->integer('balance_after');
            $t->string('reason');
            $t->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->index(['product_id', 'created_at']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action')->index();
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_values_check CHECK (stock >= 0 AND price_cents >= 0 AND vat_bps BETWEEN 0 AND 10000 AND alert_threshold >= 0)');
            foreach (['quote_items', 'order_items'] as $table) {
                DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_values_check CHECK (quantity > 0 AND price_cents >= 0 AND vat_bps BETWEEN 0 AND 10000 AND total_cents = subtotal_cents + tax_cents)");
            }
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movement_balance_check CHECK (balance_after >= 0 AND quantity <> 0)');
            foreach (['quotes', 'orders', 'invoices'] as $table) {
                DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_totals_check CHECK (subtotal_cents >= 0 AND tax_cents >= 0 AND total_cents = subtotal_cents + tax_cents)");
            }
            DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('draft','converted'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('draft','validated'))");
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft','issued','paid','cancelled'))");
        }
    }

    public function down(): void
    {
        foreach (['audit_logs', 'stock_movements', 'invoices', 'order_items', 'quote_items', 'orders', 'quotes', 'document_sequences', 'products', 'suppliers', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('role_id'));
        Schema::dropIfExists('roles');
    }
};
