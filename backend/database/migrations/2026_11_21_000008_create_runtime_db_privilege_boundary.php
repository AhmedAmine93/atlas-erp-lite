<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F16: provision a NOLOGIN application role with DML-only privileges.
 *
 * This role is cluster-wide but cannot log in until a separate provisioning
 * step installs its password. The existing atlas owner is retained for schema
 * migrations. No production Compose changes in this release.
 *
 * IMPORTANT: this is privilege separation, NOT general multi-tenant RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
DO $role$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'atlas_app_runtime') THEN
        CREATE ROLE atlas_app_runtime
          NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE
          NOREPLICATION NOBYPASSRLS;
    END IF;
END
$role$;
SQL);

        $role = DB::selectOne(<<<'SQL'
SELECT (rolcanlogin OR rolsuper OR rolbypassrls OR rolcreatedb OR
        rolcreaterole OR rolreplication)::int AS elevated
FROM pg_roles WHERE rolname = 'atlas_app_runtime'
SQL);
        if (! $role || (int) $role->elevated !== 0) {
            throw new \RuntimeException('F16 runtime role has unacceptable privileges before provisioning.');
        }

        // Allow the migration owner to assume the restricted role for
        // non-destructive permission tests. Do not INHERIT its privileges.
        $owner = (string) DB::selectOne('SELECT current_user AS name')->name;
        $quotedOwner = '"'.str_replace('"', '""', $owner).'"';
        DB::statement("GRANT atlas_app_runtime TO {$quotedOwner} WITH INHERIT FALSE, SET TRUE");

        DB::statement('GRANT USAGE ON SCHEMA public TO atlas_app_runtime');
        DB::statement('REVOKE CREATE ON SCHEMA public FROM atlas_app_runtime');

        // These are the explicit tables currently needed by Laravel auth,
        // sessions, CRM, sales, stock, and incident operations. Do NOT grant
        // access to migrations: only the schema-owner migrator can migrate.
        $tables = [
            'users', 'password_reset_tokens', 'sessions', 'roles',
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'companies', 'company_user', 'establishments',
            'customers', 'suppliers', 'products', 'document_sequences',
            'quotes', 'quote_items', 'orders', 'order_items', 'invoices',
            'stock_movements', 'audit_logs',
            'support_incidents', 'support_incident_events',
            'support_diagnostic_runs',
        ];
        foreach ($tables as $table) {
            DB::statement(
                "GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.\"{$table}\" TO atlas_app_runtime"
            );
        }

        // Generated identity / bigserial values require sequence USAGE.
        // All sequences currently belong to these tables. Explicitly exclude
        // the migration repository, which has no application runtime need.
        $sequences = DB::select(<<<'SQL'
SELECT ns.nspname AS schema_name, seq.relname AS sequence_name
FROM pg_class seq
JOIN pg_namespace ns ON ns.oid = seq.relnamespace
JOIN pg_depend dep ON dep.objid = seq.oid AND dep.deptype IN ('a', 'i')
JOIN pg_class tbl ON tbl.oid = dep.refobjid
WHERE seq.relkind = 'S' AND ns.nspname = 'public' AND tbl.relname <> 'migrations'
SQL);
        foreach ($sequences as $sequence) {
            $ident = '"'.str_replace('"', '""', $sequence->schema_name).'"."'
                .str_replace('"', '""', $sequence->sequence_name).'"';
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE {$ident} TO atlas_app_runtime");
        }

        // F15 enables RLS on incidents for the dedicated N3 SQL reader.
        // The normal runtime needs a separate transitional policy to avoid
        // inadvertently hiding incidents from the application. This policy
        // is NOT tenant-filtered; Laravel authorization remains mandatory.
        DB::statement(<<<'SQL'
CREATE POLICY atlas_f16_runtime_incident_policy
ON public.support_incidents FOR ALL TO atlas_app_runtime
USING (true) WITH CHECK (true)
SQL);

        // The N3 probe deliberately switches to the *more restricted* F15
        // role. Role membership allows SET ROLE, but not inherited SELECT.
        DB::statement(<<<'SQL'
GRANT atlas_f15_incident_reader TO atlas_app_runtime
WITH INHERIT FALSE, SET TRUE
SQL);

        $tableCheck = DB::selectOne(<<<'SQL'
SELECT has_table_privilege('atlas_app_runtime','public.migrations','SELECT')::int AS migration_read,
       has_table_privilege('atlas_app_runtime','public.support_incidents','UPDATE')::int AS incident_update
SQL);
        if ((int) $tableCheck->migration_read !== 0 || (int) $tableCheck->incident_update !== 1) {
            throw new \RuntimeException('F16 runtime grants did not match the expected privilege boundary.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS atlas_f16_runtime_incident_policy ON public.support_incidents');
        DB::statement('REVOKE atlas_f15_incident_reader FROM atlas_app_runtime');
        // Role is cluster-wide, so leave deletion to a DBA once all DBs are
        // checked. Revoke per-database access instead.
        DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM atlas_app_runtime');
        DB::statement('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM atlas_app_runtime');
        DB::statement('REVOKE USAGE ON SCHEMA public FROM atlas_app_runtime');
    }
};
