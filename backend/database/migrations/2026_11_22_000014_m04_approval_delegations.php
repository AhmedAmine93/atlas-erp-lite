<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Optional per-tenant delegation ceiling for purchase approvals. */
return new class extends Migration {
    public function up(): void {
        Schema::create('purchase_approval_delegations', function(Blueprint $t){
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $t->string('currency',3)->default('EUR');
            $t->unsignedBigInteger('max_amount_cents');
            $t->date('valid_until')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id','user_id','currency'],'m04_delegation_unique');
            $t->index(['company_id','currency','active'],'m04_delegation_policy');
        });
        if(DB::getDriverName()!=='pgsql') return;
        DB::statement('ALTER TABLE purchase_approval_delegations ADD CONSTRAINT m04_delegation_member_fk FOREIGN KEY (company_id,user_id) REFERENCES company_user(company_id,user_id)');
        DB::statement("ALTER TABLE purchase_approval_delegations ADD CONSTRAINT m04_delegation_currency_check CHECK(currency ~ '^[A-Z]{3}$')");
        DB::statement("ALTER TABLE purchase_approval_delegations ADD CONSTRAINT m04_delegation_positive_check CHECK(max_amount_cents > 0)");
        DB::statement('GRANT SELECT,INSERT,UPDATE ON TABLE public.purchase_approval_delegations TO atlas_app_runtime');
        DB::statement('REVOKE DELETE ON TABLE public.purchase_approval_delegations FROM atlas_app_runtime');
        DB::statement('GRANT USAGE,SELECT ON SEQUENCE public.purchase_approval_delegations_id_seq TO atlas_app_runtime');
        DB::statement('ALTER TABLE public.purchase_approval_delegations ENABLE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY m04_tenant_policy ON public.purchase_approval_delegations FOR ALL TO atlas_app_runtime USING(company_id=NULLIF(current_setting('atlas.tenant_id',true),'')::bigint) WITH CHECK(company_id=NULLIF(current_setting('atlas.tenant_id',true),'')::bigint)");
    }
    public function down():void { Schema::dropIfExists('purchase_approval_delegations'); }
};
