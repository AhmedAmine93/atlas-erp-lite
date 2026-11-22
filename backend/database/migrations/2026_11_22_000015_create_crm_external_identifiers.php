<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Stable external aliases for CRM/MDM reconciliation. No external synchronization. */
return new class extends Migration {
    public function up():void
    {
        Schema::create('crm_external_identifiers',function(Blueprint $table){
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('source_system',50);
            $table->string('external_id',180);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('prospect_id')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id','source_system','external_id'],'crm_external_id_unique');
            $table->index(['company_id','customer_id']);
            $table->index(['company_id','supplier_id']);
            $table->index(['company_id','contact_id']);
            $table->index(['company_id','prospect_id']);
            $table->foreign(['company_id','customer_id'])->references(['company_id','id'])->on('customers');
            $table->foreign(['company_id','supplier_id'])->references(['company_id','id'])->on('suppliers');
            $table->foreign(['company_id','contact_id'])->references(['company_id','id'])->on('crm_contacts');
            $table->foreign(['company_id','prospect_id'])->references(['company_id','id'])->on('crm_prospects');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('revoked_by')->references('id')->on('users');
        });
        if(DB::getDriverName()==='pgsql'){
            DB::statement(<<<'SQL'
                ALTER TABLE crm_external_identifiers ADD CONSTRAINT crm_external_id_exactly_one_owner
                CHECK (
                    (CASE WHEN customer_id IS NULL THEN 0 ELSE 1 END)
                  + (CASE WHEN supplier_id IS NULL THEN 0 ELSE 1 END)
                  + (CASE WHEN contact_id IS NULL THEN 0 ELSE 1 END)
                  + (CASE WHEN prospect_id IS NULL THEN 0 ELSE 1 END) = 1
                )
            SQL);
            DB::statement('GRANT SELECT, INSERT, UPDATE ON TABLE public.crm_external_identifiers TO atlas_app_runtime');
            DB::statement('REVOKE DELETE ON TABLE public.crm_external_identifiers FROM atlas_app_runtime');
            DB::statement('ALTER TABLE public.crm_external_identifiers ENABLE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY atlas_f20_runtime_tenant_policy ON public.crm_external_identifiers FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.crm_external_identifiers_id_seq TO atlas_app_runtime');
        }
    }
    public function down():void{Schema::dropIfExists('crm_external_identifiers');}
};
