<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M03 V5: additive commercial workflow. Existing "validated" orders are
 * legacy-debited and must never be debited again by the new shipping path.
 */
return new class extends Migration {
    private const TABLES = [
        'sales_quote_revisions', 'sales_discount_approvals', 'sales_reservations',
        'sales_deliveries', 'sales_delivery_lines', 'sales_contracts',
        'sales_contract_versions', 'sales_returns', 'sales_return_lines',
        'sales_credit_notes', 'sales_price_rules', 'sales_idempotency_records'
    ];

    public function up(): void
    {
        // Legacy upgrades may omit the separate F14 line-owner migration.
        // Backfill tenant ownership from immutable parent orders, never infer it
        // from the runtime session. F14 can still run later without losing data.
        if (!Schema::hasColumn('order_items', 'company_id')) {
            Schema::table('order_items', function (Blueprint $t) {
                $t->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            });
            DB::statement('UPDATE order_items SET company_id = (SELECT company_id FROM orders WHERE orders.id = order_items.order_id)');
            if (DB::table('order_items')->whereNull('company_id')->exists()) {
                throw new RuntimeException('Legacy M03 backfill failed: orphan order items.');
            }
            Schema::table('order_items', function (Blueprint $t) {
                $t->foreignId('company_id')->nullable(false)->change();
            });
        }
        Schema::table('quotes', function (Blueprint $t) {
            $t->unsignedInteger('revision_no')->default(0);
            $t->unsignedInteger('document_version')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->string('fulfillment_mode', 24)->default('legacy_debit');
            $t->unsignedInteger('document_version')->default(0);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_status_check');
            DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('draft','sent','accepted','converted','expired','cancelled'))");
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('draft','confirmed','reserved','partially_shipped','shipped','closed','cancelled','validated'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT sales_orders_mode_check CHECK (fulfillment_mode IN ('legacy_debit','reservation_v2'))");
        }

        Schema::create('sales_quote_revisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('quote_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision_no');
            $t->string('status', 24)->default('draft');
            $t->unsignedInteger('discount_bps')->default(0);
            $t->date('valid_until');
            $t->json('snapshot');
            $t->unsignedBigInteger('subtotal_cents');
            $t->unsignedBigInteger('tax_cents');
            $t->unsignedBigInteger('total_cents');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('sent_at')->nullable(); $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id','quote_id','revision_no'],'sales_revision_unique');
            $t->unique(['company_id','id']);
        });
        Schema::create('sales_discount_approvals', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('revision_id')->constrained('sales_quote_revisions')->restrictOnDelete();
            $t->unsignedInteger('discount_bps');
            $t->string('status',16)->default('pending');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('reason')->nullable(); $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id','revision_id']);
        });
        Schema::create('sales_reservations', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity_reserved');
            $t->unsignedInteger('quantity_shipped')->default(0);
            $t->unsignedInteger('quantity_released')->default(0);
            $t->timestamps();
            $t->unique(['company_id','order_item_id']);
            $t->index(['company_id','product_id']);
        });
        Schema::create('sales_deliveries', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->constrained()->restrictOnDelete();
            $t->string('number',48); $t->string('status',24)->default('draft');
            $t->string('carrier',120)->nullable(); $t->string('recipient',180);
            $t->text('proof_reference')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('issued_at')->nullable(); $t->timestamp('received_at')->nullable();
            $t->timestamps(); $t->unique(['company_id','number']); $t->unique(['company_id','id']);
            $t->index(['company_id','order_id','status']);
        });
        Schema::create('sales_delivery_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('delivery_id')->constrained('sales_deliveries')->restrictOnDelete();
            $t->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('price_cents'); $t->unsignedInteger('vat_bps');
            $t->timestamps(); $t->unique(['company_id','delivery_id','order_item_id'],'sales_delivery_one_line');
            $t->unique(['company_id','id']);
        });
        Schema::create('sales_contracts', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('establishment_id')->nullable()->constrained('establishments')->restrictOnDelete();
            $t->string('number',48); $t->string('title',180);
            $t->string('currency',3)->default('EUR'); $t->string('status',24)->default('draft');
            $t->date('effective_on'); $t->date('expires_on');
            $t->string('renewal_rule',100)->nullable();
            $t->unsignedInteger('version_no')->default(1);
            $t->text('terms')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable(); $t->timestamp('terminated_at')->nullable();
            $t->timestamps(); $t->unique(['company_id','number']); $t->unique(['company_id','id']);
            $t->index(['company_id','customer_id','status']);
        });
        Schema::create('sales_contract_versions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('contract_id')->constrained('sales_contracts')->restrictOnDelete();
            $t->unsignedInteger('version_no'); $t->json('snapshot');
            $t->date('effective_on'); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at'); $t->timestamps();
            $t->unique(['company_id','contract_id','version_no'],'sales_contract_version_unique');
        });
        Schema::create('sales_returns', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('delivery_id')->constrained('sales_deliveries')->restrictOnDelete();
            $t->string('number',48); $t->string('status',24)->default('quarantine');
            $t->text('reason'); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('inspected_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('inspected_at')->nullable(); $t->timestamps();
            $t->unique(['company_id','number']); $t->unique(['company_id','id']);
        });
        Schema::create('sales_return_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('return_id')->constrained('sales_returns')->restrictOnDelete();
            $t->foreignId('delivery_line_id')->constrained('sales_delivery_lines')->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('quantity_received'); $t->unsignedInteger('quantity_credit_eligible')->default(0);
            $t->string('quality_status',24)->default('quarantine');
            $t->timestamps(); $t->unique(['company_id','return_id','delivery_line_id'],'sales_return_line_unique');
        });
        Schema::create('sales_credit_notes', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('return_id')->unique()->constrained('sales_returns')->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->string('number',48); $t->unsignedBigInteger('total_cents');
            $t->json('snapshot'); $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('issued_at'); $t->timestamps();
            $t->unique(['company_id','number']);
        });
        Schema::create('sales_price_rules', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('establishment_id')->nullable()->constrained('establishments')->restrictOnDelete();
            $t->string('currency',3)->default('EUR');
            $t->string('title',180); $t->string('status',24)->default('draft');
            $t->unsignedInteger('priority')->default(100);
            $t->unsignedInteger('price_cents');
            $t->unsignedInteger('discount_bps')->default(0);
            $t->unsignedInteger('vat_bps');
            $t->date('starts_on'); $t->date('ends_on');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable(); $t->timestamps();
            $t->index(['company_id','product_id','status','priority'],'sales_prices_lookup');
        });
        Schema::create('sales_idempotency_records', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('operation',100); $t->string('request_key',100);
            $t->char('payload_hash',64); $t->json('result');
            $t->timestamps();
            $t->unique(['company_id','operation','request_key'],'sales_idempotency_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                "ALTER TABLE sales_reservations ADD CONSTRAINT sales_reservation_quantities_check CHECK (quantity_reserved > 0 AND quantity_shipped + quantity_released <= quantity_reserved)",
                "ALTER TABLE sales_delivery_lines ADD CONSTRAINT sales_delivery_positive_check CHECK (quantity > 0)",
                "ALTER TABLE sales_return_lines ADD CONSTRAINT sales_return_quantities_check CHECK (quantity_received > 0 AND quantity_credit_eligible <= quantity_received)",
                "ALTER TABLE sales_price_rules ADD CONSTRAINT sales_price_values_check CHECK (discount_bps <= 10000 AND vat_bps <= 10000 AND starts_on <= ends_on)",
                "ALTER TABLE sales_contracts ADD CONSTRAINT sales_contract_dates_check CHECK (effective_on <= expires_on)",
                "ALTER TABLE sales_quote_revisions ADD CONSTRAINT sales_revision_discount_check CHECK (discount_bps <= 10000)",
            ] as $sql) DB::statement($sql);

            // Database enforcement of tenant-consistent relationships, not just Eloquent scopes.
            DB::statement('CREATE UNIQUE INDEX sales_order_items_company_id_uq ON order_items (company_id, id)');
            foreach ([
                ['sales_quote_revisions','quote_id','quotes'],
                ['sales_discount_approvals','revision_id','sales_quote_revisions'],
                ['sales_reservations','order_id','orders'],
                ['sales_reservations','order_item_id','order_items'],
                ['sales_reservations','product_id','products'],
                ['sales_deliveries','order_id','orders'],
                ['sales_delivery_lines','delivery_id','sales_deliveries'],
                ['sales_delivery_lines','order_item_id','order_items'],
                ['sales_delivery_lines','product_id','products'],
                ['sales_contracts','customer_id','customers'],
                ['sales_contracts','establishment_id','establishments'],
                ['sales_contract_versions','contract_id','sales_contracts'],
                ['sales_returns','delivery_id','sales_deliveries'],
                ['sales_return_lines','return_id','sales_returns'],
                ['sales_return_lines','delivery_line_id','sales_delivery_lines'],
                ['sales_return_lines','product_id','products'],
                ['sales_credit_notes','return_id','sales_returns'],
                ['sales_credit_notes','invoice_id','invoices'],
                ['sales_price_rules','product_id','products'],
                ['sales_price_rules','establishment_id','establishments']
            ] as [$child,$column,$parent]) {
                // Referenced original tables have composite indexes from F14.
                if ($parent === 'establishments') {
                    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sales_establishments_company_id_uq ON establishments (company_id, id)');
                }
                if ($parent === 'invoices') {
                    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS sales_invoices_company_id_uq ON invoices (company_id, id)');
                }
                $name = 'sc_'.$child.'_'.$column.'_fk';
                DB::statement("ALTER TABLE \"{$child}\" ADD CONSTRAINT \"{$name}\" FOREIGN KEY (company_id, \"{$column}\") REFERENCES \"{$parent}\" (company_id, id)");
            }
            foreach (self::TABLES as $table) {
                DB::statement("GRANT SELECT, INSERT, UPDATE ON public.\"{$table}\" TO atlas_app_runtime");
                DB::statement("REVOKE DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
                DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY atlas_m03_company_policy ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
                $sequence = DB::selectOne('SELECT pg_get_serial_sequence(?, \'id\') AS seq', ['public.'.$table]);
                if ($sequence && $sequence->seq) DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$sequence->seq} TO atlas_app_runtime");
            }
        }
    }

    public function down(): void
    {
        // This migration must never quietly destroy real commercial records.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('M03 rollback requires archival and manual data migration: '.$table);
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::statement("DROP POLICY IF EXISTS atlas_m03_company_policy ON public.\"{$table}\"");
                DB::statement("REVOKE SELECT, INSERT, UPDATE, DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            }
        }
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sales_order_items_company_id_uq');
            DB::statement('DROP INDEX IF EXISTS sales_establishments_company_id_uq');
            DB::statement('DROP INDEX IF EXISTS sales_invoices_company_id_uq');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS sales_orders_mode_check');
            DB::statement('ALTER TABLE quotes DROP CONSTRAINT IF EXISTS quotes_status_check');
            DB::statement("ALTER TABLE quotes ADD CONSTRAINT quotes_status_check CHECK (status IN ('draft','converted'))");
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('draft','validated'))");
        }
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['fulfillment_mode','document_version']));
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn(['revision_no','document_version','sent_at','accepted_at']));
    }
};
