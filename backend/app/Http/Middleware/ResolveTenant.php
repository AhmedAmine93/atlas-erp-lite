<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
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

        // P066.05: a browser tab must not silently execute a pending write
        // against a different company after another tab switches the session.
        // Optional to preserve legacy API clients; the Next.js app always
        // includes its last verified company ID on business requests.
        // /me, /logout, and /tenant/switch can reconcile or change context.
        if (! $request->is('api/me', 'api/logout', 'api/tenant/switch')) {
            $expected = $request->header('X-Atlas-Expected-Company');
            if ($expected !== null && (
                ! is_string($expected)
                || ! preg_match('/^[1-9][0-9]{0,17}$/D', $expected)
                || (int) $expected !== (int) $companyId
            )) {
                return response()->json([
                    'message' => 'Le contexte entreprise a changé. Rechargez votre espace.',
                ], 409)->header('X-Atlas-Context-Stale', '1');
            }
        }

        if (DB::getDriverName() !== 'pgsql') {
            return $next($request);
        }

        // Keep both cross-tab context validation and transaction-scoped PostgreSQL RLS.
        return DB::transaction(function () use ($request, $next, $companyId): Response {
            $previous = DB::selectOne(
                "SELECT current_setting('atlas.tenant_id', true) AS tenant_id"
            )?->tenant_id ?? '';

            TenantDatabaseContext::set((int) $companyId);
            try {
                return $next($request);
            } finally {
                DB::selectOne(
                    "SELECT set_config('atlas.tenant_id', ?, true) AS restored_tenant",
                    [$previous],
                );
            }
        });
    }
}
