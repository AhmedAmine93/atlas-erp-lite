<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Audit-only commitments; a budget capacity policy must be tenant-approved separately. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_commitment_events',function(Blueprint $t){
            $t->id();$t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->string('event_key',100);
            $t->string('kind',16);$t->unsignedBigInteger('amount_cents');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at');$t->timestamps();
            $t->unique(['company_id','event_key'],'m04_commitment_idempotency');
            $t->index(['company_id','purchase_order_id','recorded_at'],'m04_commitment_timeline');
        });
        if(DB::getDriverName()!=='pgsql')return;
        DB::statement('ALTER TABLE purchase_commitment_events ADD CONSTRAINT m04_commitment_tenant_fk FOREIGN KEY (company_id,purchase_order_id) REFERENCES purchase_orders(company_id,id)');
        DB::statement("ALTER TABLE purchase_commitment_events ADD CONSTRAINT m04_commitment_kind_chk CHECK(kind IN ('reserve','release'))");
        DB::statement('GRANT SELECT,INSERT ON TABLE public.purchase_commitment_events TO atlas_app_runtime');
        DB::statement('REVOKE UPDATE,DELETE ON TABLE public.purchase_commitment_events FROM atlas_app_runtime');
        DB::statement('GRANT USAGE,SELECT ON SEQUENCE public.purchase_commitment_events_id_seq TO atlas_app_runtime');
        DB::statement('ALTER TABLE public.purchase_commitment_events ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY m04_tenant_policy ON public.purchase_commitment_events FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id',true),'')::bigint)");
    }
    public function down(): void { Schema::dropIfExists('purchase_commitment_events'); }
};
