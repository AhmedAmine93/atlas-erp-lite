<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * F15: bounded diagnostic SELECT access under PostgreSQL row-level security.
 *
 * We currently connect as the schema owner for historic ERP functionality.
 * Switching to a NOLOGIN read-only role within a transaction makes RLS
 * effective for these *predefined* diagnostic queries. This is NOT a
 * substitute for a separate unprivileged runtime DB account.
 */
final class TenantReadOnlyDb
{
    public function run(callable $callback): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL is required for F15 RLS diagnostics.');
        }

        // Read from server-authorized tenant context only, never from request
        // payload / query string or user-provided SQL.
        $companyId = app(TenantContext::class)->requireId();

        return DB::transaction(function () use ($callback, $companyId): mixed {
            $previous = DB::selectOne(
                "SELECT current_setting('atlas.company_id', true) AS company"
            )->company ?? '';

            // SET LOCAL ROLE is transaction-bound, not persistent on a pooled
            // connection. The limited role has SELECT on tenant tables only,
            // without access to users, secrets, or tenant memberships.
            DB::statement('SET LOCAL ROLE atlas_rls_reader');
            DB::selectOne(
                "SELECT set_config('atlas.company_id', ?, true) AS value",
                [(string) $companyId]
            );

            // If the callback throws, the transaction/savepoint rollback
            // restores both role and local setting. Do not run RESET ROLE
            // inside a failed PostgreSQL transaction.
            $result = $callback();

            DB::selectOne(
                "SELECT set_config('atlas.company_id', ?, true) AS value",
                [(string) $previous]
            );
            DB::statement('RESET ROLE');

            return $result;
        }, 3);
    }
}
