<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F20: database-level defense-in-depth for the explicitly tenant-owned ERP
 * tables, with transaction-local tenant context set by the Laravel middleware.
 *
 * The PostgreSQL role remains NOINHERIT/NOBYPASSRLS (F16). An owner migrator
 * deliberately bypasses RLS to run schema upgrades. Custom GUCs are settable
 * by a role able to issue arbitrary SQL: this is NOT a complete SQL-injection
 * defense; see docs/F20-BUSINESS-TENANT-RLS.md.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
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

        $role = DB::selectOne(<<<'SQL'
SELECT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole
    OR rolreplication)::int AS elevated
FROM pg_roles WHERE rolname = 'atlas_app_runtime'
SQL);
        if (! $role || (int) $role->elevated !== 0) {
            throw new \RuntimeException('F20 requires the restricted F16 runtime database role.');
        }

        // Replace only F16's intentionally permissive runtime incident policy.
        // The F15 diagnostic reader policy remains intact and tenant-scoped.
        DB::statement('DROP POLICY IF EXISTS atlas_f16_runtime_incident_policy ON public.support_incidents');

        foreach (self::TENANT_TABLES as $table) {
            // All F20 table names are hard-coded and have a non-null company_id.
            // The policy fails closed when the GUC is missing or empty.
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement(<<<SQL
CREATE POLICY atlas_f20_runtime_tenant_policy
ON public."{$table}" FOR ALL TO atlas_app_runtime
USING (
    company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint
)
WITH CHECK (
    company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint
)
SQL);
        }

        // A fixed, argument-free metrics aggregate is the *only* global
        // business visibility exposed to the runtime. No direct row access.
        // The owner migrator owns this SECURITY DEFINER function and is
        // responsible for reviewing changes to its fixed SQL body.
        DB::statement(<<<'SQL'
CREATE FUNCTION public.atlas_f20_global_metrics()
RETURNS TABLE (
    orders_validated bigint,
    invoices_outstanding_cents numeric,
    products_low_stock bigint
)
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog
AS $metrics$
    SELECT
        (SELECT COUNT(*) FROM public.orders WHERE status = 'validated'),
        (SELECT COALESCE(SUM(total_cents), 0) FROM public.invoices WHERE status = 'issued'),
        (SELECT COUNT(*) FROM public.products WHERE stock <= alert_threshold)
$metrics$
SQL);
        DB::statement('REVOKE ALL ON FUNCTION public.atlas_f20_global_metrics() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION public.atlas_f20_global_metrics() TO atlas_app_runtime');

        $policies = DB::selectOne(<<<'SQL'
SELECT COUNT(*)::int AS n
FROM pg_policies
WHERE schemaname = 'public'
  AND policyname = 'atlas_f20_runtime_tenant_policy'
  AND 'atlas_app_runtime' = ANY(roles)
SQL);
        if ((int) $policies->n !== count(self::TENANT_TABLES)) {
            throw new \RuntimeException('F20 runtime RLS policies are incomplete.');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('REVOKE EXECUTE ON FUNCTION public.atlas_f20_global_metrics() FROM atlas_app_runtime');
        DB::statement('DROP FUNCTION IF EXISTS public.atlas_f20_global_metrics()');

        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS atlas_f20_runtime_tenant_policy ON public.\"{$table}\"");
            if ($table !== 'support_incidents') {
                DB::statement("ALTER TABLE public.\"{$table}\" DISABLE ROW LEVEL SECURITY");
            }
        }

        // Restore exactly the F16 transitional runtime behavior; F15's
        // diagnostic reader remains active on support_incidents.
        DB::statement(<<<'SQL'
CREATE POLICY atlas_f16_runtime_incident_policy
ON public.support_incidents FOR ALL TO atlas_app_runtime
USING (true) WITH CHECK (true)
SQL);
    }
};
