<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Ready for payment" is an internal signoff only, never a bank transfer.
 * Signing events are append-only and cannot be altered by the HTTP role.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_invoice_payment_authorizations',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $t->unsignedBigInteger('authorized_amount_cents');
            $t->foreignId('authorized_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('authorized_at');
            $t->timestamps();
            $t->unique(['company_id','supplier_invoice_id'],'m04_single_invoice_authorization');
        });
        if(DB::getDriverName()!=='pgsql')return;
        DB::statement('ALTER TABLE supplier_invoice_payment_authorizations ADD CONSTRAINT m04_payment_invoice_tenant_fk FOREIGN KEY (company_id,supplier_invoice_id) REFERENCES supplier_invoices(company_id,id)');
        DB::statement('GRANT SELECT,INSERT ON TABLE public.supplier_invoice_payment_authorizations TO atlas_app_runtime');
        DB::statement('REVOKE UPDATE,DELETE ON TABLE public.supplier_invoice_payment_authorizations FROM atlas_app_runtime');
        DB::statement('GRANT USAGE,SELECT ON SEQUENCE public.supplier_invoice_payment_authorizations_id_seq TO atlas_app_runtime');
        DB::statement('ALTER TABLE public.supplier_invoice_payment_authorizations ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY m04_tenant_policy ON public.supplier_invoice_payment_authorizations FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint)");
    }
    public function down(): void { Schema::dropIfExists('supplier_invoice_payment_authorizations'); }
};
