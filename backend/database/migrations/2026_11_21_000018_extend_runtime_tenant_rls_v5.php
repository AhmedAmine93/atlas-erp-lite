<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F20/V5: grant restricted runtime only the DML required by V5 HTTP routes,
 * with PostgreSQL tenant RLS on EVERY newly introduced company-owned table.
 *
 * Migration runs after the V5 000017 tables. The schema owner is the migrator;
 * the unprivileged atlas_app_runtime role never receives DDL privileges.
 */
return new class extends Migration {
    private const MUTABLE = [
        'purchase_requests',
        'maintenance_sites', 'maintenance_assets',
        'maintenance_requests', 'maintenance_work_orders',
        'hotel_properties', 'hotel_rooms', 'hotel_bookings',
        'data_source_registry', 'data_ingestion_batches',
    ];

    private const IMMUTABLE = ['data_ingested_csv_rows'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $role = DB::selectOne("SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR
            rolcreaterole OR rolreplication)::int AS elevated
            FROM pg_roles WHERE rolname = 'atlas_app_runtime'");
        if (!$role || (int) $role->elevated !== 0) {
            throw new RuntimeException('Restricted F16 database role is required for V5 grants.');
        }

        foreach (self::MUTABLE as $table) {
            $this->protectTable($table, 'SELECT, INSERT, UPDATE');
        }
        foreach (self::IMMUTABLE as $table) {
            $this->protectTable($table, 'SELECT, INSERT');
        }

        // Only sequences backing explicitly allowlisted V5 tables are usable.
        $names = array_merge(self::MUTABLE, self::IMMUTABLE);
        foreach ($names as $table) {
            $sequences = DB::select(<<<'SQL'
SELECT seq.relname AS sequence_name FROM pg_class AS seq
JOIN pg_namespace ns ON ns.oid=seq.relnamespace
JOIN pg_depend dep ON dep.objid=seq.oid AND dep.deptype IN ('a','i')
JOIN pg_class tbl ON tbl.oid=dep.refobjid
WHERE seq.relkind='S' AND ns.nspname='public' AND tbl.relname=?
SQL, [$table]);
            foreach ($sequences as $sequence) {
                $identifier = '"'.str_replace('"', '""', $sequence->sequence_name).'"';
                DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.{$identifier} TO atlas_app_runtime");
            }
        }

        foreach ($names as $table) {
            $permissions = DB::selectOne(<<<'SQL'
SELECT has_table_privilege('atlas_app_runtime', ?, 'SELECT')::int AS r,
       has_table_privilege('atlas_app_runtime', ?, 'INSERT')::int AS i,
       has_table_privilege('atlas_app_runtime', ?, 'DELETE')::int AS d
SQL, array_fill(0, 3, 'public.'.$table));
            if ((int) $permissions->r !== 1 || (int) $permissions->i !== 1
                || (int) $permissions->d !== 0) {
                throw new RuntimeException("Unexpected privileges for V5 table {$table}");
            }
        }
    }

    private function protectTable(string $table, string $rights): void
    {
        DB::statement("GRANT {$rights} ON TABLE public.\"{$table}\" TO atlas_app_runtime");
        DB::statement("REVOKE DELETE ON TABLE public.\"{$table}\" FROM atlas_app_runtime");
        DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
CREATE POLICY atlas_f20_runtime_tenant_policy
ON public."{$table}" FOR ALL TO atlas_app_runtime
USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_merge(self::MUTABLE, self::IMMUTABLE) as $table) {
            DB::statement("DROP POLICY IF EXISTS atlas_f20_runtime_tenant_policy ON public.\"{$table}\"");
            DB::statement("ALTER TABLE public.\"{$table}\" DISABLE ROW LEVEL SECURITY");
            DB::statement("REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE public.\"{$table}\" FROM atlas_app_runtime");
        }
        // Revoke only sequence usage on allowlisted V5 sequences as needed for
        // rollback; leave existing core runtime grants untouched.
        foreach (array_merge(self::MUTABLE, self::IMMUTABLE) as $table) {
            $sequences = DB::select(<<<'SQL'
SELECT seq.relname AS sequence_name FROM pg_class seq
JOIN pg_namespace ns ON ns.oid=seq.relnamespace
JOIN pg_depend dep ON dep.objid=seq.oid AND dep.deptype IN ('a','i')
JOIN pg_class tbl ON tbl.oid=dep.refobjid
WHERE seq.relkind='S' AND ns.nspname='public' AND tbl.relname=?
SQL, [$table]);
            foreach ($sequences as $seq) {
                $identifier = '"'.str_replace('"', '""', $seq->sequence_name).'"';
                DB::statement("REVOKE USAGE, SELECT ON SEQUENCE public.{$identifier} FROM atlas_app_runtime");
            }
        }
    }
};
