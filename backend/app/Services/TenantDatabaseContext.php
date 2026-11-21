<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * F20: bind the validated active tenant to the CURRENT PostgreSQL transaction.
 *
 * Only middleware/controllers call this; raw user input must never choose a
 * tenant here. PostgreSQL SET LOCAL state expires automatically at COMMIT or
 * ROLLBACK, including on persistent/reused connections.
 */
final class TenantDatabaseContext
{
    public static function set(int $companyId): void
    {
        if ($companyId < 1) {
            throw new \InvalidArgumentException('Invalid tenant database context.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Tenant database context requires an active transaction.');
        }
        DB::selectOne(
            "SELECT set_config('atlas.tenant_id', ?, true) AS applied_tenant",
            [(string) $companyId],
        );
    }
}
