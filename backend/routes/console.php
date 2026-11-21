<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// F16: read-only privilege check for the *real* connected runtime login.
// This is intentionally not run by migrations or automatically on boot.
Artisan::command('atlas:verify-runtime-db', function () {
    if (\Illuminate\Support\Facades\DB::getDriverName() !== 'pgsql') {
        $this->error('F16 requires PostgreSQL.');
        return 2;
    }

    $result = \Illuminate\Support\Facades\DB::selectOne(<<<'SQL'
SELECT current_user AS db_user,
       has_schema_privilege(current_user, 'public', 'CREATE')::int AS schema_create,
       has_table_privilege(current_user, 'public.migrations', 'SELECT')::int AS migrations_read,
       has_table_privilege(current_user, 'public.customers', 'SELECT')::int AS customers_read,
       has_table_privilege(current_user, 'public.customers', 'INSERT')::int AS customers_insert,
       has_table_privilege(current_user, 'public.support_incidents', 'UPDATE')::int AS incidents_update,
       pg_has_role(current_user, 'atlas_f15_incident_reader', 'SET')::int AS can_set_n3_reader
SQL);

    $valid = $result->db_user === 'atlas_app_runtime'
        && (int) $result->schema_create === 0
        && (int) $result->migrations_read === 0
        && (int) $result->customers_read === 1
        && (int) $result->customers_insert === 1
        && (int) $result->incidents_update === 1
        && (int) $result->can_set_n3_reader === 1;

    if (! $valid) {
        $this->error(
            'F16 BLOCKER: connected database identity/privileges are not the expected restricted runtime.'
        );
        return 1;
    }

    $this->info('F16 OK: authenticated restricted PostgreSQL runtime; DDL and migrations denied.');
    $this->warn('Tenant-wide database RLS is NOT enabled. This does not certify production security.');
    return 0;
})->purpose('F16 staging check: verify the actual PostgreSQL runtime login and grants');
