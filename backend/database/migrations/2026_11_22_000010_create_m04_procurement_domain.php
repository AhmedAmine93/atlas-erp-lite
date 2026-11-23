<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M04: additive purchasing schema. Application runtime stays DML-only.
 * All company-owned references are tenant-composite on PostgreSQL.
 */
return new class extends Migration {
    private const TABLES = [
        'supplier_bank_changes', 'purchase_request_history', 'purchase_request_lines',
        'supplier_quotes', 'purchase_orders', 'purchase_order_lines',
        'purchase_receipts', 'purchase_receipt_lines', 'supplier_invoices',
        'supplier_credit_notes', 'procurement_action_keys'
    ];

    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->string('procurement_status', 16)->default('active');
            $t->string('tax_registration', 80)->nullable();
            $t->text('blocked_reason')->nullable();
            $t->timestamp('blocked_at')->nullable();
            $t->unique(['company_id', 'tax_registration'], 'm04_supplier_tax_unique');
        });
        Schema::table('purchase_requests', function (Blueprint $t) {
            $t->string('cost_center', 80)->nullable();
            $t->date('needed_on')->nullable();
        });

        Schema::create('supplier_bank_changes', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->text('encrypted_iban'); $t->string('iban_last4', 4);
            $t->string('status', 12)->default('pending');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->text('decision_reason')->nullable();
            $t->timestamps(); $t->index(['company_id','supplier_id','status']);
        });
        Schema::create('purchase_request_history', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->json('snapshot'); $t->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['company_id','purchase_request_id','revision'], 'm04_pr_revision_unique');
        });
        Schema::create('purchase_request_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $t->string('description', 255); $t->unsignedInteger('quantity');
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('unit_price_cents'); $t->unsignedInteger('vat_bps')->default(0);
            $t->timestamps(); $t->index(['company_id','purchase_request_id']);
        });
        Schema::create('supplier_quotes', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('reference', 80); $t->string('currency', 3);
            $t->unsignedBigInteger('subtotal_cents'); $t->unsignedBigInteger('tax_cents');
            $t->unsignedBigInteger('shipping_cents')->default(0); $t->unsignedBigInteger('total_cents');
            $t->unsignedInteger('delivery_days')->default(0); $t->date('valid_until');
            $t->string('status', 12)->default('received');
            $t->text('award_reason')->nullable();
            $t->foreignId('awarded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('awarded_at')->nullable();
            $t->timestamps(); $t->unique(['company_id','supplier_id','reference'], 'm04_quote_ref_unique');
            $t->index(['company_id','purchase_request_id','status']);
        });
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('reference', 80);
            $t->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $t->foreignId('supplier_quote_id')->constrained('supplier_quotes')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->unsignedInteger('request_revision');
            $t->string('currency', 3); $t->unsignedBigInteger('total_cents');
            $t->string('status', 24)->default('draft');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('sent_at')->nullable(); $t->timestamp('cancelled_at')->nullable();
            $t->text('cancellation_reason')->nullable();
            $t->timestamps(); $t->unique(['company_id','reference'], 'm04_order_ref_unique');
            $t->unique(['company_id','purchase_request_id'], 'm04_one_order_per_request');
        });
        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $t->string('description', 255); $t->unsignedInteger('quantity');
            $t->string('line_type',16)->default('goods');
            $t->unsignedInteger('received_quantity')->default(0);
            $t->unsignedBigInteger('unit_price_cents'); $t->unsignedInteger('vat_bps')->default(0);
            $t->timestamps(); $t->index(['company_id','purchase_order_id']);
        });
        Schema::create('purchase_receipts', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->string('reference', 80); $t->string('payload_hash', 64);
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('received_at'); $t->timestamps();
            $t->unique(['company_id','reference'], 'm04_receipt_ref_unique');
        });
        Schema::create('purchase_receipt_lines', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_receipt_id')->constrained('purchase_receipts')->restrictOnDelete();
            $t->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $t->unsignedInteger('received_quantity');
            $t->unsignedInteger('accepted_quantity'); $t->unsignedInteger('rejected_quantity');
            $t->timestamps(); $t->unique(['company_id','purchase_receipt_id','purchase_order_line_id'], 'm04_unique_receipt_line');
        });
        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $t->string('reference', 120); $t->string('normalized_reference', 120);
            $t->string('currency', 3); $t->unsignedBigInteger('total_cents');
            $t->unsignedBigInteger('matched_cents')->default(0);
            $t->unsignedBigInteger('dispute_cents')->default(0);
            $t->string('status', 32)->default('disputed');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['company_id','supplier_id','normalized_reference'], 'm04_supplier_invoice_ref_unique');
        });
        Schema::create('supplier_credit_notes', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $t->string('reference',120); $t->unsignedBigInteger('amount_cents');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps(); $t->unique(['company_id','reference'], 'm04_credit_ref_unique');
        });
        Schema::create('procurement_action_keys', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('operation',80); $t->string('request_key',100); $t->string('payload_hash',64);
            $t->unsignedBigInteger('result_id')->nullable(); $t->timestamps();
            $t->unique(['company_id','operation','request_key'], 'm04_idempotency_unique');
        });

        if (DB::getDriverName() !== 'pgsql') return;

        $relations = [
            ['supplier_bank_changes','supplier_id','suppliers'],
            ['purchase_request_history','purchase_request_id','purchase_requests'],
            ['purchase_request_lines','purchase_request_id','purchase_requests'],
            ['supplier_quotes','purchase_request_id','purchase_requests'],
            ['supplier_quotes','supplier_id','suppliers'],
            ['purchase_orders','purchase_request_id','purchase_requests'],
            ['purchase_orders','supplier_quote_id','supplier_quotes'],
            ['purchase_orders','supplier_id','suppliers'],
            ['purchase_order_lines','purchase_order_id','purchase_orders'],
            ['purchase_order_lines','product_id','products'],
            ['purchase_receipts','purchase_order_id','purchase_orders'],
            ['purchase_receipt_lines','purchase_receipt_id','purchase_receipts'],
            ['purchase_receipt_lines','purchase_order_line_id','purchase_order_lines'],
            ['supplier_invoices','purchase_order_id','purchase_orders'],
            ['supplier_invoices','supplier_id','suppliers'],
            ['supplier_credit_notes','supplier_invoice_id','supplier_invoices'],
        ];
        $parents = ['products','suppliers','purchase_requests','supplier_quotes','purchase_orders',
                    'purchase_order_lines','purchase_receipts','supplier_invoices'];
        foreach ($parents as $parent) {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS m04_'.$parent.'_tenant_identity ON '.$parent.' (company_id,id)');
        }
        foreach ($relations as [$child,$column,$parent]) {
            DB::statement('ALTER TABLE '.$child.' ADD CONSTRAINT m04_'.$child.'_'.$column.'_tenant_fk FOREIGN KEY (company_id,'.$column.') REFERENCES '.$parent.' (company_id,id)');
        }
        DB::statement("ALTER TABLE suppliers ADD CONSTRAINT m04_supplier_status_check CHECK (procurement_status IN ('active','blocked','archived'))");
        DB::statement("ALTER TABLE purchase_receipt_lines ADD CONSTRAINT m04_receipt_qty_check CHECK (received_quantity > 0 AND accepted_quantity + rejected_quantity = received_quantity)");
        DB::statement("ALTER TABLE purchase_order_lines ADD CONSTRAINT m04_order_qty_check CHECK (quantity > 0 AND received_quantity <= quantity AND vat_bps <= 10000)");
        DB::statement("ALTER TABLE purchase_order_lines ADD CONSTRAINT m04_order_line_type_check CHECK(line_type IN ('goods','service'))");
        DB::statement("ALTER TABLE purchase_request_lines ADD CONSTRAINT m04_request_qty_check CHECK (quantity > 0 AND vat_bps <= 10000)");
        DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT m04_invoice_amount_check CHECK (matched_cents + dispute_cents = total_cents)");
        DB::statement("CREATE UNIQUE INDEX m04_one_quote_winner ON supplier_quotes (company_id,purchase_request_id) WHERE status='awarded'");

        $runtime = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname='atlas_app_runtime' AND NOT rolsuper AND NOT rolbypassrls");
        if (!$runtime) throw new RuntimeException('M04 requires unprivileged atlas_app_runtime role');
        foreach (self::TABLES as $table) {
            $rights = in_array($table,['purchase_request_history','purchase_receipt_lines','supplier_credit_notes'],true)
                ? 'SELECT,INSERT' : 'SELECT,INSERT,UPDATE';
            DB::statement('GRANT '.$rights.' ON TABLE public."'.$table.'" TO atlas_app_runtime');
            DB::statement('REVOKE DELETE ON TABLE public."'.$table.'" FROM atlas_app_runtime');
            DB::statement('ALTER TABLE public."'.$table.'" ENABLE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY m04_tenant_policy ON public.\"".$table."\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint)");
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public."'.$table.'_id_seq" TO atlas_app_runtime');
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
        Schema::table('purchase_requests', fn (Blueprint $t) => $t->dropColumn(['cost_center','needed_on']));
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropUnique('m04_supplier_tax_unique');
            $t->dropColumn(['procurement_status','tax_registration','blocked_reason','blocked_at']);
        });
    }
};
