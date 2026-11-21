<?php

namespace App\Http\Middleware;

use App\Services\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $requested = $request->session()->get('active_company_id');
        if ($requested !== null) {
            abort_unless(
                $user->companies()->whereKey((int) $requested)->exists(),
                403,
                'Vous n’avez pas accès à cette entreprise.',
            );
            $companyId = (int) $requested;
        } else {
            $companyId = $user->companies()->orderBy('companies.id')->value('companies.id');
            abort_unless($companyId, 403, 'Aucune entreprise autorisée.');
            $request->session()->put('active_company_id', (int) $companyId);
        }

        $request->attributes->set('atlas_company_id', (int) $companyId);

        if (DB::getDriverName() !== 'pgsql') {
            return $next($request);
        }

        // RLS applies for the complete authenticated API request. A local
        // setting is never stored on the pooled connection after COMMIT.
        return DB::transaction(function () use ($request, $next, $companyId): Response {
            // Restore context for nested transactions, notably PHPUnit tests.
            $previous = DB::selectOne(
                "SELECT current_setting('atlas.tenant_id', true) AS tenant_id"
            )?->tenant_id ?? '';

            TenantDatabaseContext::set((int) $companyId);
            try {
                return $next($request);
            } finally {
                // SET LOCAL normally expires on the outermost COMMIT. Restore
                // explicitly for an already-open outer transaction.
                DB::selectOne(
                    "SELECT set_config('atlas.tenant_id', ?, true) AS restored_tenant",
                    [$previous],
                );
            }
        });
    }
}
