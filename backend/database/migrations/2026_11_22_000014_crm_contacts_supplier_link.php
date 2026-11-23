<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One CRM contact can represent a customer, a supplier, or both in one tenant.
 * Existing supplier and customer records are not rewritten.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suppliers', function(Blueprint $table) {
            $table->unique(['company_id','id'],'suppliers_tenant_id_unique');
        });
        Schema::table('crm_contacts', function(Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->index(['company_id','supplier_id'],'crm_contacts_supplier_idx');
            $table->foreign(['company_id','supplier_id'],'crm_contacts_supplier_tenant_fk')
                ->references(['company_id','id'])->on('suppliers');
        });
    }
    public function down(): void
    {
        Schema::table('crm_contacts', function(Blueprint $table) {
            $table->dropForeign('crm_contacts_supplier_tenant_fk');
            $table->dropIndex('crm_contacts_supplier_idx');
            $table->dropColumn('supplier_id');
        });
        Schema::table('suppliers',function(Blueprint $table) {
            $table->dropUnique('suppliers_tenant_id_unique');
        });
    }
};
