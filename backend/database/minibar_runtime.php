<?php

use Illuminate\Support\Facades\DB;

// Migration-only helper. Callers supply fixed table names and fixed grant lists.
return static function (array $tableRights): void {
    foreach ($tableRights as $table => $rights) {
        DB::statement("REVOKE ALL ON public.$table FROM PUBLIC, atlas_app_runtime");
        DB::statement("GRANT $rights ON public.$table TO atlas_app_runtime");
        DB::statement("ALTER TABLE public.$table ENABLE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY minibar_tenant ON public.$table FOR ALL TO atlas_app_runtime
            USING (company_id = NULLIF(current_setting('atlas.tenant_id', true),'')::bigint)
            WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true),'')::bigint)");
        DB::statement("REVOKE ALL ON SEQUENCE public.{$table}_id_seq FROM PUBLIC, atlas_app_runtime");
        DB::statement("GRANT USAGE ON SEQUENCE public.{$table}_id_seq TO atlas_app_runtime");
    }
};
