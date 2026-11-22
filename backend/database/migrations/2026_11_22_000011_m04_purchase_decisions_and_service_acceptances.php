<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additional M04 control records: re-awards, post-issue order amendments and
 * signed services. No destructive modification of issued commercial documents.
 */
return new class extends Migration {
    private const TABLES = ['procurement_award_events','purchase_order_amendments','purchase_service_acceptances'];

    public function up(): void
    {
        Schema::create('procurement_award_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $t->foreignId('previous_quote_id')->nullable()->constrained('supplier_quotes')->restrictOnDelete();
            $t->foreignId('new_quote_id')->constrained('supplier_quotes')->restrictOnDelete();
            $t->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $t->text('reason'); $t->timestamp('decided_at'); $t->timestamps();
            $t->index(['company_id','purchase_request_id','decided_at'],'m04_award_timeline');
        });
        Schema::create('purchase_order_amendments', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $t->unsignedInteger('new_quantity');
            $t->unsignedBigInteger('total_before_cents'); $t->unsignedBigInteger('total_after_cents');
            $t->string('status',16)->default('pending');
            $t->string('reason',2000);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->timestamps();
            $t->index(['company_id','purchase_order_id','status'],'m04_amendment_lookup');
        });
        Schema::create('purchase_service_acceptances', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->restrictOnDelete();
            $t->string('reference',80);
            $t->unsignedInteger('accepted_quantity');
            $t->string('evidence_reference',160);
            $t->foreignId('signed_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('signed_at'); $t->timestamps();
            $t->unique(['company_id','reference'],'m04_service_reference_unique');
            $t->index(['company_id','purchase_order_id'],'m04_service_order_index');
        });

        if (DB::getDriverName() !== 'pgsql') return;
        foreach ([
            ['procurement_award_events','purchase_request_id','purchase_requests'],
            ['procurement_award_events','previous_quote_id','supplier_quotes'],
            ['procurement_award_events','new_quote_id','supplier_quotes'],
            ['purchase_order_amendments','purchase_order_id','purchase_orders'],
            ['purchase_order_amendments','purchase_order_line_id','purchase_order_lines'],
            ['purchase_service_acceptances','purchase_order_id','purchase_orders'],
            ['purchase_service_acceptances','purchase_order_line_id','purchase_order_lines'],
        ] as [$child,$column,$parent]) {
            DB::statement("ALTER TABLE {$child} ADD CONSTRAINT m04_{$child}_{$column}_fk FOREIGN KEY (company_id, {$column}) REFERENCES {$parent} (company_id,id)");
        }
        DB::statement("ALTER TABLE purchase_order_amendments ADD CONSTRAINT m04_amendment_status_chk CHECK(status IN ('pending','approved','rejected'))");
        DB::statement("ALTER TABLE purchase_order_amendments ADD CONSTRAINT m04_amendment_positive_chk CHECK(new_quantity > 0)");
        DB::statement("ALTER TABLE purchase_service_acceptances ADD CONSTRAINT m04_service_qty_chk CHECK(accepted_quantity > 0)");
        DB::statement("CREATE UNIQUE INDEX m04_one_pending_amendment ON purchase_order_amendments(company_id,purchase_order_line_id) WHERE status='pending'");
        $runtime=DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname='atlas_app_runtime' AND NOT rolsuper AND NOT rolbypassrls");
        if (!$runtime) throw new RuntimeException('A restricted atlas_app_runtime role is required for M04');
        foreach (self::TABLES as $table) {
            $rights=$table==='purchase_order_amendments'?'SELECT,INSERT,UPDATE':'SELECT,INSERT';
            DB::statement('GRANT '.$rights.' ON TABLE public."'.$table.'" TO atlas_app_runtime');
            DB::statement('REVOKE DELETE ON TABLE public."'.$table.'" FROM atlas_app_runtime');
            DB::statement('ALTER TABLE public."'.$table.'" ENABLE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY m04_tenant_policy ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint)");
            DB::statement('GRANT USAGE,SELECT ON SEQUENCE public."'.$table.'_id_seq" TO atlas_app_runtime');
        }
    }
    public function down(): void {
        foreach (array_reverse(self::TABLES) as $table) Schema::dropIfExists($table);
    }
};
