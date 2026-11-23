<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Separate damaged arrival from irreversible physical loss. */
return new class extends Migration {
    public function up():void {
        Schema::table('inventory_transfer_receipts',function(Blueprint $t) {
            $t->boolean('damaged')->default(false);
            $t->foreignId('damage_location_id')->nullable()->constrained('inventory_locations')->restrictOnDelete();
            $t->string('damage_reason',500)->nullable();
        });
        if(DB::getDriverName()==='pgsql'){
            DB::statement('ALTER TABLE inventory_transfer_receipts ADD CONSTRAINT inv_damage_location_tenant_fk
                FOREIGN KEY (company_id, damage_location_id) REFERENCES inventory_locations(company_id,id)');
            DB::statement('ALTER TABLE inventory_transfer_receipts ADD CONSTRAINT inv_damage_reason_check CHECK
                (NOT damaged OR (damage_location_id IS NOT NULL AND length(trim(damage_reason)) >= 5))');
        }
    }
    public function down():void {
        Schema::table('inventory_transfer_receipts',function(Blueprint $t){
            $t->dropConstrainedForeignId('damage_location_id');
            $t->dropColumn(['damaged','damage_reason']);
        });
    }
};
