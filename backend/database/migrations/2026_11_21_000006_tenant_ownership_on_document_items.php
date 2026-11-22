<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F14/B — Backfill tenant ownership for order/quote lines and enforce
 * tenant-preserving parent/product relationships.
 */
return new class extends Migration
{
    private const LINES = [
        ['quote_items', 'quote_id', 'quotes', 'f14_quote_items_quote_tenant_fk'],
        ['order_items', 'order_id', 'orders', 'f14_order_items_order_tenant_fk'],
    ];

    public function up(): void
    {
        // Migration A already validates document and product tenant ownership.
        // Backfill line ownership from the document; never infer ownership from
        // a product or an authenticated session during a historical migration.
        foreach (self::LINES as [$table, $parentKey, $parent, $fk]) {
            // A newer M03 compatibility migration may have already created
            // this ownership column on a legacy database before F14 is replayed.
            // Do not attempt a destructive duplicate-column ALTER.
            if (!Schema::hasColumn($table, 'company_id')) {
                Schema::table($table, function (Blueprint $schema) {
                    $schema->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
                });
            }

            // Portable PostgreSQL/SQLite correlated statement.
            DB::statement(
                "UPDATE {$table} SET company_id =
                 (SELECT company_id FROM {$parent}
                  WHERE {$parent}.id = {$table}.{$parentKey})"
            );

            $broken = DB::selectOne(
                "SELECT COUNT(*) AS n FROM {$table} AS l
                 LEFT JOIN {$parent} AS d ON d.id = l.{$parentKey}
                 LEFT JOIN products AS p ON p.id = l.product_id
                 WHERE l.company_id IS NULL OR p.id IS NULL OR
                       d.company_id <> l.company_id OR
                       p.company_id <> l.company_id"
            );

            if ((int) $broken->n > 0) {
                throw new \RuntimeException(
                    "F14 line integrity preflight failed on {$table}: {$broken->n} invalid row(s)."
                );
            }

            Schema::table($table, function (Blueprint $schema) {
                $schema->foreignId('company_id')->nullable(false)->change();
                $schema->index('company_id');
            });

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    "CREATE INDEX f14_{$table}_tenant_parent_idx ON {$table} (company_id, {$parentKey})"
                );
                DB::statement(
                    "CREATE INDEX f14_{$table}_tenant_product_idx ON {$table} (company_id, product_id)"
                );
                DB::statement(
                    "ALTER TABLE {$table} ADD CONSTRAINT f14_{$table}_product_tenant_fk
                     FOREIGN KEY (company_id, product_id)
                     REFERENCES products (company_id, id) NOT VALID"
                );
                DB::statement(
                    "ALTER TABLE {$table} ADD CONSTRAINT {$fk}
                     FOREIGN KEY (company_id, {$parentKey})
                     REFERENCES {$parent} (company_id, id) NOT VALID"
                );
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT f14_{$table}_product_tenant_fk");
                DB::statement(
                    "ALTER TABLE {$table} VALIDATE CONSTRAINT {$fk}"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::LINES) as [$table, $parentKey, $parent, $fk]) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT f14_{$table}_product_tenant_fk");
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$fk}");
                DB::statement("DROP INDEX f14_{$table}_tenant_product_idx");
                DB::statement("DROP INDEX f14_{$table}_tenant_parent_idx");
            }
            Schema::table($table, function (Blueprint $schema) {
                $schema->dropIndex(['company_id']);
                $schema->dropConstrainedForeignId('company_id');
            });
        }
    }
};
