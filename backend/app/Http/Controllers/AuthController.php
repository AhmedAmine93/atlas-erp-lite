<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantDatabaseContext;
use App\Services\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data)) {
            throw ValidationException::withMessages(['email' => 'Identifiants incorrects.']);
        }
        $request->session()->regenerate();
        $firstCompany = $request->user()->companies()->orderBy('companies.id')->value('companies.id');
        if (! $firstCompany) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Aucune entreprise autorisée pour ce compte.']);
        }
        $request->session()->put('active_company_id', (int) $firstCompany);
        $request->attributes->set('atlas_company_id', (int) $firstCompany);

        // Login is intentionally outside ResolveTenant. Give its audit event
        // the same transaction-local RLS context as authenticated API calls.
        if (DB::getDriverName() === 'pgsql') {
            DB::transaction(function () use ($firstCompany) {
                TenantDatabaseContext::set((int) $firstCompany);
                Audit::record('auth.login');
            });
        } else {
            Audit::record('auth.login');
        }

        return app(TenantRoles::class)->profile($request->user(), (int) $firstCompany);
    }

    public function logout(Request $request)
    {
        Audit::record('auth.logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
