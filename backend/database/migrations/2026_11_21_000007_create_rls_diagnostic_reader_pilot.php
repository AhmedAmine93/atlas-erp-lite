<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F15 pilot: PostgreSQL RLS for a dedicated NOLOGIN, SELECT-only diagnostic
 * role. The app's table-owning connection is not FORCE-RLS enabled here.
 * Promote to application-wide RLS only after separating DB owner/migrator
 * credentials from the unprivileged runtime connection.
 */
return new class extends Migration
{
    private const TABLES = [
        'customers', 'suppliers', 'products',
        'quotes', 'quote_items', 'orders', 'order_items', 'invoices',
        'stock_movements', 'audit_logs', 'establishments',
        'support_incidents', 'support_incident_events', 'support_diagnostic_runs',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
DO $atlas$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'atlas_rls_reader') THEN
        CREATE ROLE atlas_rls_reader NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS;
    END IF;
END
$atlas$;
SQL);

        $role = DB::selectOne(
            "SELECT (rolcanlogin::int + rolsuper::int +
                      rolbypassrls::int + rolcreaterole::int) AS elevated
             FROM pg_roles WHERE rolname = 'atlas_rls_reader'"
        );
        if (! $role || (int) $role->elevated !== 0) {
            throw new \RuntimeException('F15 reader role must be NOLOGIN, non-superuser and NOBYPASSRLS.');
        }

        // The current migration account may use SET LOCAL ROLE for the pilot.
        // The owner continues running existing ERP paths until runtime DB
        // credentials are separated and application-wide FORCE RLS is planned.
        $login = (string) DB::selectOne('SELECT current_user AS name')->name;
        $quotedLogin = '"'.str_replace('"', '""', $login).'"';
        DB::statement("GRANT atlas_rls_reader TO {$quotedLogin}");
        DB::statement('GRANT USAGE ON SCHEMA public TO atlas_rls_reader');

        foreach (self::TABLES as $table) {
            // Explicitly strip non-read privileges if an identically named role
            // pre-exists. No privileges on users/roles/company_user/credentials.
            DB::statement("REVOKE ALL ON TABLE public.\"{$table}\" FROM atlas_rls_reader");
            DB::statement("GRANT SELECT ON TABLE public.\"{$table}\" TO atlas_rls_reader");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement(
                "CREATE POLICY f15_{$table}_reader_select
                 ON public.\"{$table}\"
                 FOR SELECT TO atlas_rls_reader
                 USING (
                     company_id = NULLIF(current_setting('atlas.company_id', true), '')::bigint
                 )"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_reverse(self::TABLES) as $table) {
            DB::statement("DROP POLICY IF EXISTS f15_{$table}_reader_select ON public.\"{$table}\"");
            DB::statement("REVOKE ALL ON TABLE public.\"{$table}\" FROM atlas_rls_reader");
            DB::statement("ALTER TABLE public.\"{$table}\" DISABLE ROW LEVEL SECURITY");
        }

        DB::statement('REVOKE USAGE ON SCHEMA public FROM atlas_rls_reader');
        // Do not DROP ROLE: it is a cluster-level principal, potentially
        // reused by other DBs. Cleanup/membership revocation is DBA-managed.
    }
};
