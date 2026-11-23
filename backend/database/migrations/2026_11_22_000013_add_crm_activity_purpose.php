<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::table('crm_activities',function(Blueprint $table){
            $table->string('purpose',24)->default('service');
        });
        if(DB::getDriverName()==='pgsql'){
            DB::statement("ALTER TABLE crm_activities ADD CONSTRAINT crm_activity_purpose_ck CHECK(purpose IN ('service','sales','marketing'))");
        }
    }
    public function down():void
    {
        Schema::table('crm_activities',fn(Blueprint $table)=>$table->dropColumn('purpose'));
    }
};
