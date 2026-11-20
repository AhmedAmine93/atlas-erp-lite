<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
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

        return $next($request);
    }
}
