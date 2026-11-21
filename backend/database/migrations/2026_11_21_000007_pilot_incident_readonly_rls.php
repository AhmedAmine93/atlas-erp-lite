<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F15 pilot: a restricted PostgreSQL reader for the incident statistics probe.
 *
 * IMPORTANT: the normal app connection still owns the tables and can bypass
 * RLS. This is a scoped defense-in-depth pilot, not full ERP RLS enforcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
DO $do$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'atlas_f15_incident_reader') THEN
        CREATE ROLE atlas_f15_incident_reader
          NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
    END IF;
    EXECUTE format('GRANT %I TO %I', 'atlas_f15_incident_reader', CURRENT_USER);
END
$do$
SQL);

        // If the cluster-level role already exists, reject an unexpectedly
        // privileged principal rather than assuming its name implies safety.
        $role = DB::selectOne(<<<'SQL'
SELECT (
    rolcanlogin OR rolsuper OR rolbypassrls OR rolcreaterole OR
    rolcreatedb OR rolreplication
)::int AS unsafe
FROM pg_roles WHERE rolname = 'atlas_f15_incident_reader'
SQL);
        if (! $role || (int) $role->unsafe !== 0) {
            throw new \RuntimeException('F15 diagnostic role has unsafe PostgreSQL attributes.');
        }

        DB::statement('GRANT USAGE ON SCHEMA public TO atlas_f15_incident_reader');
        // Explicit privilege baseline even if this role was pre-provisioned.
        DB::statement('REVOKE ALL ON TABLE public.support_incidents FROM atlas_f15_incident_reader');
        DB::statement('GRANT SELECT ON TABLE public.support_incidents TO atlas_f15_incident_reader');

        $unexpected = DB::selectOne(<<<'SQL'
SELECT has_table_privilege('atlas_f15_incident_reader', 'public.users', 'SELECT')::int AS user_read,
       has_table_privilege('atlas_f15_incident_reader', 'public.support_incidents', 'INSERT')::int AS incident_write
SQL);
        if ((int) $unexpected->user_read !== 0 || (int) $unexpected->incident_write !== 0) {
            throw new \RuntimeException('F15 diagnostic role has unexpected table privileges.');
        }

        DB::statement('ALTER TABLE public.support_incidents ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
CREATE POLICY atlas_f15_incident_read_policy
    ON public.support_incidents
    FOR SELECT TO atlas_f15_incident_reader
    USING (
       company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint
    )
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS atlas_f15_incident_read_policy ON public.support_incidents');
        DB::statement('ALTER TABLE public.support_incidents DISABLE ROW LEVEL SECURITY');
        DB::statement('REVOKE SELECT ON TABLE public.support_incidents FROM atlas_f15_incident_reader');
        // Role is cluster-wide. Keep it for safe database-local rollbacks;
        // do not DROP ROLE from a migration running on one database.
    }
};
