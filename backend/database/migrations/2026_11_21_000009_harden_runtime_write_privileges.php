<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F19: reduce database writes granted to the authenticated application role.
 *
 * The schema migrator owns the tables and stays privileged. This is an
 * incremental defense-in-depth control, NOT tenant-wide RLS enforcement.
 * If the operational API starts writing to any revoked table, CI must fail.
 */
return new class extends Migration
{
    private const APPEND_ONLY = [
        'audit_logs',
        'stock_movements',
        'support_incident_events',
        'support_diagnostic_runs',
    ];

    private const NO_RUNTIME_DELETE = [
        'companies',
        'support_incidents',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // F16 must already have created this role and the tables.
        $role = DB::selectOne(
            "SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole)::int AS elevated
             FROM pg_roles WHERE rolname = 'atlas_app_runtime'"
        );
        if (! $role || (int) $role->elevated !== 0) {
            throw new \RuntimeException('F19 requires a nonprivileged F16 runtime role.');
        }

        // Role definitions belong to migrations, never to the HTTP runtime.
        DB::statement('REVOKE INSERT, UPDATE, DELETE ON TABLE public.roles FROM atlas_app_runtime');

        // Preserve INSERT+SELECT for event/audit/stock journals, but disallow
        // retrospective modification/deletion via a compromised SQL account.
        foreach (self::APPEND_ONLY as $table) {
            DB::statement(
                "REVOKE UPDATE, DELETE ON TABLE public.\"{$table}\" FROM atlas_app_runtime"
            );
        }

        // These business objects are state-transitioned, not removed, via the
        // current API. Forbid destructive direct deletes as a second boundary.
        foreach (self::NO_RUNTIME_DELETE as $table) {
            DB::statement(
                "REVOKE DELETE ON TABLE public.\"{$table}\" FROM atlas_app_runtime"
            );
        }

        // Verify effective ACLs instead of trusting the REVOKE alone:
        // other role memberships or PUBLIC grants could reintroduce a right.
        foreach (self::APPEND_ONLY as $table) {
            $permissions = DB::selectOne(
                "SELECT has_table_privilege('atlas_app_runtime', ?, 'SELECT')::int AS r,
                        has_table_privilege('atlas_app_runtime', ?, 'INSERT')::int AS i,
                        has_table_privilege('atlas_app_runtime', ?, 'UPDATE')::int AS u,
                        has_table_privilege('atlas_app_runtime', ?, 'DELETE')::int AS d",
                array_fill(0, 4, 'public.'.$table),
            );
            if ((int) $permissions->r !== 1 || (int) $permissions->i !== 1
                || (int) $permissions->u !== 0 || (int) $permissions->d !== 0) {
                throw new \RuntimeException("F19 unexpectedly elevated {$table} privileges.");
            }
        }

        foreach ([...self::NO_RUNTIME_DELETE, 'roles'] as $table) {
            $canDelete = DB::selectOne(
                "SELECT has_table_privilege('atlas_app_runtime', ?, 'DELETE')::int AS granted",
                ['public.'.$table],
            );
            if ((int) $canDelete->granted !== 0) {
                throw new \RuntimeException("F19 runtime still has DELETE on {$table}.");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Return only to F16's previous known DML baseline, never DROP ROLE.
        DB::statement('GRANT INSERT, UPDATE, DELETE ON TABLE public.roles TO atlas_app_runtime');
        foreach (self::APPEND_ONLY as $table) {
            DB::statement(
                "GRANT UPDATE, DELETE ON TABLE public.\"{$table}\" TO atlas_app_runtime"
            );
        }
        foreach (self::NO_RUNTIME_DELETE as $table) {
            DB::statement(
                "GRANT DELETE ON TABLE public.\"{$table}\" TO atlas_app_runtime"
            );
        }
    }
};
