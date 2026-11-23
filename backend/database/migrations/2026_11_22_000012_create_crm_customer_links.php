<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M02: logical customer deduplication only. Never change foreign keys on
 * orders, quotes, invoices or financial snapshots.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('crm_customer_links', function(Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->unsignedBigInteger('duplicate_customer_id');
            $table->unsignedBigInteger('canonical_customer_id');
            $table->boolean('active')->default(true);
            $table->text('reason');
            $table->timestampTz('previous_archived_at')->nullable();
            $table->string('previous_archive_reason',500)->nullable();
            $table->unsignedBigInteger('linked_by');
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['company_id','duplicate_customer_id'],'crm_customer_link_unique');
            $table->index(['company_id','canonical_customer_id','active'],'crm_customer_link_canonical_idx');
            $table->foreign(['company_id','duplicate_customer_id'])
                ->references(['company_id','id'])->on('customers');
            $table->foreign(['company_id','canonical_customer_id'])
                ->references(['company_id','id'])->on('customers');
            $table->foreign('linked_by')->references('id')->on('users');
            $table->foreign('reversed_by')->references('id')->on('users');
        });
        if(DB::getDriverName()==='pgsql'){
            DB::statement('GRANT SELECT, INSERT, UPDATE ON TABLE public.crm_customer_links TO atlas_app_runtime');
            DB::statement('REVOKE DELETE ON TABLE public.crm_customer_links FROM atlas_app_runtime');
            DB::statement('ALTER TABLE public.crm_customer_links ENABLE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY atlas_f20_runtime_tenant_policy ON public.crm_customer_links FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.crm_customer_links_id_seq TO atlas_app_runtime');
            DB::statement('ALTER TABLE crm_customer_links ADD CONSTRAINT crm_customer_links_not_self CHECK (duplicate_customer_id <> canonical_customer_id)');
        }
    }
    public function down():void { Schema::dropIfExists('crm_customer_links'); }
};
