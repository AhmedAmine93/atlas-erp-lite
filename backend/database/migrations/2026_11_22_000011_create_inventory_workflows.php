<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M05 ledger and workflows — additive, no data movement from legacy products.stock.
 * All company-owned relations have an RLS policy for atlas_app_runtime on PostgreSQL.
 */
return new class extends Migration {
    private const MUTABLE = [
        'inventory_lots', 'inventory_balances', 'inventory_serials',
        'inventory_counts', 'inventory_transfers', 'inventory_replenishments',
    ];
    private const APPEND_ONLY = ['inventory_ledger', 'inventory_transfer_receipts'];

    private function company(Blueprint $t): void
    {
        $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
    }

    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $t) {
            $t->id(); $this->company($t);
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->string('code', 80);
            $t->string('status', 16)->default('quarantine');
            $t->date('expires_on')->nullable();
            $t->text('decision_reason')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'product_id', 'code'], 'inv_lot_code_unique');
            $t->unique(['company_id', 'product_id', 'id'], 'inv_lot_compound_unique');
            $t->unique(['company_id', 'id']);
        });

        Schema::create('inventory_balances', function (Blueprint $t) {
            $t->id(); $this->company($t);
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $t->unsignedBigInteger('quantity')->default(0);
            $t->timestamps();
            $t->index(['company_id', 'product_id', 'location_id'], 'inv_balances_lookup');
            $t->unique(['company_id', 'id']);
        });

        Schema::create('inventory_ledger', function (Blueprint $t) {
            $t->id(); $this->company($t);
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $t->integer('quantity'); $t->unsignedBigInteger('balance_after');
            $t->string('operation', 32);
            $t->string('source_reference', 160);
            $t->string('reason', 500);
            $t->uuid('operation_key')->nullable();
            $t->char('operation_hash', 64)->nullable();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['company_id', 'operation', 'operation_key'], 'inv_ledger_operation_unique');
            $t->index(['company_id', 'product_id', 'created_at'], 'inv_ledger_product_idx');
        });

        Schema::create('inventory_serials', function (Blueprint $t) {
            $t->id(); $this->company($t);
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $t->foreignId('location_id')->nullable()->constrained('inventory_locations')->restrictOnDelete();
            $t->string('serial_number', 120);
            $t->string('status', 16)->default('available');
            $t->timestamps();
            $t->unique(['company_id', 'product_id', 'serial_number'], 'inv_serial_unique');
        });

        Schema::create('inventory_counts', function (Blueprint $t) {
            $t->id(); $this->company($t);
            $t->string('reference', 80);
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('location_id')->constrained('inventory_locations')->restrictOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $t->unsignedBigInteger('snapshot_quantity');
            $t->unsignedBigInteger('first_count')->nullable();
            $t->unsignedBigInteger('second_count')->nullable();
            $t->string('status', 20)->default('draft');
            $t->string('reason', 500)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('counted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('recounted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'reference'], 'inv_count_reference_unique');
            $t->index(['company_id', 'location_id', 'status'], 'inv_count_scope_idx');
        });

        Schema::create('inventory_transfers', function (Blueprint $t) {
            $t->id(); $this->company($t);
            $t->string('reference', 80);
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('lot_id')->nullable()->constrained('inventory_lots')->restrictOnDelete();
            $t->foreignId('source_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $t->foreignId('target_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $t->unsignedBigInteger('quantity_requested');
            $t->unsignedBigInteger('quantity_shipped')->default(0);
            $t->unsignedBigInteger('quantity_received')->default(0);
            $t->unsignedBigInteger('quantity_lost')->default(0);
            $t->string('status', 20)->default('requested');
            $t->uuid('shipment_key')->nullable();
            $t->char('shipment_hash', 64)->nullable();
            $t->uuid('loss_key')->nullable();
            $t->char('loss_hash', 64)->nullable();
            $t->string('loss_reason', 500)->nullable();
            $t->foreignId('lost_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('shipped_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'reference'], 'inv_transfer_ref_unique');
            $t->unique(['company_id', 'shipment_key'], 'inv_transfer_ship_unique');
            $t->unique(['company_id', 'id']);
        });

        Schema::create('inventory_transfer_receipts', function (Blueprint $t) {
            $t->id(); $this->company($t);
            $t->foreignId('transfer_id')->constrained('inventory_transfers')->restrictOnDelete();
            $t->string('reference', 80);
            $t->unsignedBigInteger('quantity');
            $t->uuid('operation_key');
            $t->char('operation_hash', 64);
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['company_id', 'operation_key'], 'inv_receipt_replay_unique');
            $t->unique(['company_id', 'transfer_id', 'reference'], 'inv_receipt_ref_unique');
        });

        Schema::create('inventory_replenishments', function (Blueprint $t) {
            $t->id(); $this->company($t);
            $t->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $t->unsignedBigInteger('safety_quantity');
            $t->unsignedBigInteger('available_quantity');
            $t->unsignedBigInteger('reserved_quantity')->default(0);
            $t->unsignedBigInteger('incoming_quantity')->default(0);
            $t->unsignedBigInteger('suggested_quantity');
            $t->string('status', 20)->default('proposed');
            $t->uuid('operation_key');
            $t->char('operation_hash', 64);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'operation_key'], 'inv_replenish_key_unique');
            $t->index(['company_id', 'product_id', 'status'], 'inv_replenish_lookup');
        });

        DB::statement("CREATE UNIQUE INDEX inv_count_open_scope ON inventory_counts
            (company_id, product_id, location_id, COALESCE(lot_id, 0))
            WHERE status IN ('draft','counted','recounted')");

        if (DB::getDriverName() !== 'pgsql') {
            // SQLite test DB can still run the API tests; PostgreSQL is the
            // production integrity baseline for partial indexes and RLS.
            DB::statement('CREATE UNIQUE INDEX inv_balance_one_per_lot ON inventory_balances(company_id, product_id, location_id, COALESCE(lot_id, 0))');
            return;
        }

        DB::statement('CREATE UNIQUE INDEX inv_balance_one_per_lot ON inventory_balances(company_id, product_id, location_id, COALESCE(lot_id, 0))');
        // Compatibility with F14 legacy restore: the retrofit may have marked
        // the earlier composite-key migration as applied before creating its indexes.
        // A unique parent key is mandatory for every tenant-composite FK below.
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS inv_products_company_id_pk_idx ON products(company_id, id)');
        DB::statement('CREATE UNIQUE INDEX inv_supplier_company_id_pk_idx ON suppliers(company_id, id)');
        DB::statement("ALTER TABLE inventory_lots ADD CONSTRAINT inv_lot_state CHECK (status IN ('available','quarantine','blocked','recalled'))");
        DB::statement("ALTER TABLE inventory_serials ADD CONSTRAINT inv_serial_state CHECK (status IN ('available','quarantine','sold'))");
        DB::statement("ALTER TABLE inventory_ledger ADD CONSTRAINT inv_ledger_quantity CHECK (quantity <> 0)");
        DB::statement("ALTER TABLE inventory_counts ADD CONSTRAINT inv_count_state CHECK (status IN ('draft','counted','recounted','approved','cancelled'))");
        DB::statement("ALTER TABLE inventory_transfers ADD CONSTRAINT inv_transfer_state CHECK (status IN ('requested','in_transit','received','cancelled'))");
        DB::statement("ALTER TABLE inventory_transfers ADD CONSTRAINT inv_transfer_quantity CHECK (quantity_requested > 0 AND quantity_shipped <= quantity_requested AND quantity_received + quantity_lost <= quantity_shipped)");
        DB::statement("ALTER TABLE inventory_transfer_receipts ADD CONSTRAINT inv_receipt_positive CHECK (quantity > 0)");
        DB::statement("ALTER TABLE inventory_replenishments ADD CONSTRAINT inv_replenish_state CHECK (status IN ('proposed','approved','rejected'))");

        foreach ([
            ['inventory_lots', 'product_id', 'products', 'inv_lot_product'],
            ['inventory_balances', 'product_id', 'products', 'inv_balance_product'],
            ['inventory_balances', 'location_id', 'inventory_locations', 'inv_balance_location'],
            ['inventory_ledger', 'product_id', 'products', 'inv_ledger_product'],
            ['inventory_ledger', 'location_id', 'inventory_locations', 'inv_ledger_location'],
            ['inventory_serials', 'product_id', 'products', 'inv_serial_product'],
            ['inventory_serials', 'location_id', 'inventory_locations', 'inv_serial_location'],
            ['inventory_counts', 'product_id', 'products', 'inv_count_product'],
            ['inventory_counts', 'location_id', 'inventory_locations', 'inv_count_location'],
            ['inventory_transfers', 'product_id', 'products', 'inv_transfer_product'],
            ['inventory_transfers', 'source_location_id', 'inventory_locations', 'inv_transfer_source'],
            ['inventory_transfers', 'target_location_id', 'inventory_locations', 'inv_transfer_target'],
            ['inventory_transfer_receipts', 'transfer_id', 'inventory_transfers', 'inv_receipt_transfer'],
            ['inventory_replenishments', 'product_id', 'products', 'inv_replenish_product'],
            ['inventory_replenishments', 'supplier_id', 'suppliers', 'inv_replenish_supplier'],
        ] as [$child, $field, $parent, $name]) {
            DB::statement("ALTER TABLE {$child} ADD CONSTRAINT {$name}_tenant_fk
                FOREIGN KEY (company_id, {$field}) REFERENCES {$parent}(company_id,id)");
        }

        foreach (['inventory_balances', 'inventory_ledger', 'inventory_serials', 'inventory_counts',
                  'inventory_transfers'] as $child) {
            DB::statement("ALTER TABLE {$child} ADD CONSTRAINT {$child}_lot_tenant_fk
                FOREIGN KEY (company_id, product_id, lot_id)
                REFERENCES inventory_lots(company_id, product_id, id)");
        }

        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole
            OR rolreplication)::int AS elevated FROM pg_roles WHERE rolname='atlas_app_runtime'");
        if (!$role || (int) $role->elevated !== 0) {
            throw new RuntimeException('M05 requires the restricted runtime database role.');
        }

        foreach (array_merge(self::MUTABLE, self::APPEND_ONLY) as $table) {
            $grants = in_array($table, self::APPEND_ONLY, true) ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE';
            DB::statement("GRANT {$grants} ON public.{$table} TO atlas_app_runtime");
            DB::statement("REVOKE DELETE ON public.{$table} FROM atlas_app_runtime");
            if (in_array($table, self::APPEND_ONLY, true)) {
                DB::statement("REVOKE UPDATE ON public.{$table} FROM atlas_app_runtime");
            }
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.{$table}_id_seq TO atlas_app_runtime");
            DB::statement("ALTER TABLE public.{$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY atlas_m05_tenant_policy ON public.{$table}
                FOR ALL TO atlas_app_runtime
                USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
                WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_reverse(array_merge(self::MUTABLE, self::APPEND_ONLY)) as $table) {
                DB::statement("DROP POLICY IF EXISTS atlas_m05_tenant_policy ON public.{$table}");
                DB::statement("ALTER TABLE public.{$table} DISABLE ROW LEVEL SECURITY");
                DB::statement("REVOKE SELECT, INSERT, UPDATE, DELETE ON public.{$table} FROM atlas_app_runtime");
                DB::statement("REVOKE USAGE, SELECT ON SEQUENCE public.{$table}_id_seq FROM atlas_app_runtime");
            }
        }
        foreach (['inventory_replenishments', 'inventory_transfer_receipts',
                  'inventory_transfers', 'inventory_counts', 'inventory_serials',
                  'inventory_ledger', 'inventory_balances', 'inventory_lots'] as $table) {
            Schema::dropIfExists($table);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS inv_supplier_company_id_pk_idx');
            DB::statement('DROP INDEX IF EXISTS inv_products_company_id_pk_idx');
        }
    }
};
