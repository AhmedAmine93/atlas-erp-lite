<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F14/A — PostgreSQL composite tenant foreign keys.
 *
 * An Eloquent tenant scope is not a database integrity constraint. This
 * migration rejects historical cross-tenant relationships instead of
 * silently reassigning any rows. No destructive rollback or data repair.
 */
return new class extends Migration
{
    private const RELATIONS = [
        // [child, local reference, parent, constraint]
        ['quotes', 'customer_id', 'customers', 'f14_quotes_customer_tenant_fk'],
        ['orders', 'customer_id', 'customers', 'f14_orders_customer_tenant_fk'],
        ['orders', 'quote_id', 'quotes', 'f14_orders_quote_tenant_fk'],
        ['invoices', 'order_id', 'orders', 'f14_invoices_order_tenant_fk'],
        ['stock_movements', 'product_id', 'products', 'f14_stock_product_tenant_fk'],
        ['stock_movements', 'order_id', 'orders', 'f14_stock_order_tenant_fk'],
        ['support_incident_events', 'support_incident_id', 'support_incidents', 'f14_support_event_tenant_fk'],
        ['support_diagnostic_runs', 'support_incident_id', 'support_incidents', 'f14_support_diagnostic_tenant_fk'],
    ];

    private const PARENTS = ['customers', 'products', 'quotes', 'orders', 'support_incidents'];

    public function up(): void
    {
        // For now strict composite constraints are supported on PostgreSQL.
        // SQLite is usable for lightweight tests, but is NOT a production
        // equivalent; the CI verification uses PostgreSQL 17.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Preflight BEFORE changing the schema. Explicitly fail on corrupt
        // parent links, including nullable quote/order references when set.
        foreach (self::RELATIONS as [$child, $column, $parent]) {
            $bad = DB::selectOne(
                "SELECT COUNT(*) AS n FROM \"{$child}\" AS c
                 LEFT JOIN \"{$parent}\" AS p ON p.id = c.\"{$column}\"
                 WHERE c.\"{$column}\" IS NOT NULL
                 AND (p.id IS NULL OR c.company_id <> p.company_id)"
            );
            if ((int) $bad->n > 0) {
                throw new \RuntimeException(
                    "F14 tenant-integrity preflight failed for {$child}.{$column}: {$bad->n} invalid relation(s)."
                );
            }
        }

        foreach (self::PARENTS as $parent) {
            DB::statement(
                "CREATE UNIQUE INDEX \"f14_{$parent}_company_id_uq\" ON \"{$parent}\" (company_id, id)"
            );
        }

        foreach (self::RELATIONS as [$child, $column, $parent, $name]) {
            DB::statement(
                "CREATE INDEX \"f14_{$child}_{$column}_idx\" ON \"{$child}\" (company_id, \"{$column}\")"
            );
            DB::statement(
                "ALTER TABLE \"{$child}\" ADD CONSTRAINT \"{$name}\"
                 FOREIGN KEY (company_id, \"{$column}\")
                 REFERENCES \"{$parent}\" (company_id, id) NOT VALID"
            );
            DB::statement("ALTER TABLE \"{$child}\" VALIDATE CONSTRAINT \"{$name}\"");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::RELATIONS) as [$child, $column, , $name]) {
            DB::statement("ALTER TABLE \"{$child}\" DROP CONSTRAINT \"{$name}\"");
            DB::statement("DROP INDEX \"f14_{$child}_{$column}_idx\"");
        }
        foreach (array_reverse(self::PARENTS) as $parent) {
            DB::statement("DROP INDEX \"f14_{$parent}_company_id_uq\"");
        }
    }
};
