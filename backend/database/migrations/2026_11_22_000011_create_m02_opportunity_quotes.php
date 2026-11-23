<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tenant-isolated, immutable association between a CRM opportunity and a sales quote. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_opportunity_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->unsignedBigInteger('opportunity_id');
            $table->unsignedBigInteger('quote_id');
            $table->unsignedBigInteger('linked_by');
            $table->timestamps();
            $table->unique(['company_id','quote_id'], 'crm_opportunity_quote_unique');
            $table->foreign(['company_id','opportunity_id'])
                ->references(['company_id','id'])->on('crm_opportunities');
            $table->foreign('quote_id')->references('id')->on('quotes');
            $table->foreign('linked_by')->references('id')->on('users');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('GRANT SELECT, INSERT ON TABLE public.crm_opportunity_quotes TO atlas_app_runtime');
            DB::statement('REVOKE UPDATE, DELETE ON TABLE public.crm_opportunity_quotes FROM atlas_app_runtime');
            DB::statement('ALTER TABLE public.crm_opportunity_quotes ENABLE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY atlas_f20_runtime_tenant_policy ON public.crm_opportunity_quotes FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.crm_opportunity_quotes_id_seq TO atlas_app_runtime');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_quotes');
    }
};
