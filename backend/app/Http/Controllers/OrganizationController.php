<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Role;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function companies()
    {
        Gate::authorize('admin');

        return Company::whereHas('users', fn ($query) => $query->whereKey(auth()->id()))->withCount('establishments')->orderBy('name')->get();
    }

    public function saveCompany(Request $request, ?int $id = null)
    {
        Gate::authorize('admin');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('companies', 'code')->ignore($id)],
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:40',
            'address' => 'nullable|string|max:2000',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $activeCompanyId = app(TenantContext::class)->requireId();
            $company = $id
                ? Company::whereKey($activeCompanyId)->whereHas('users', fn ($q) => $q->whereKey(auth()->id()))->findOrFail($id)
                : new Company();
            // Editing another company requires switching to it and passing
            // the active company's admin policy.
            if ($id && (int) $id !== $activeCompanyId) {
                abort(404);
            }
            $company->fill($data)->save();
            if (! $id) {
                $company->users()->attach(auth()->id(), [
                    'role_id' => Role::where('name', 'admin')->value('id'),
                ]);
            }
            Audit::record($id ? 'company.updated' : 'company.created', $company);

            return response()->json($company->loadCount('establishments'), $id ? 200 : 201);
        }, 3);
    }

    public function establishments(Request $request)
    {
        Gate::authorize('admin');
        $filter = $request->validate(['company_id' => 'nullable|integer']);
        $activeCompanyId = app(TenantContext::class)->requireId();
        if (isset($filter['company_id']) && (int) $filter['company_id'] !== $activeCompanyId) {
            abort(404);
        }

        return Establishment::where('company_id', $activeCompanyId)
            ->with('company:id,name,code')
            ->orderBy('company_id')->orderBy('code')->get();
    }

    public function saveEstablishment(Request $request, ?int $id = null)
    {
        Gate::authorize('admin');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        // Validate existence before checking tenant membership: malformed or unknown
        // company IDs are input errors (422), not missing tenant resources.
        // Membership is checked before validating establishment uniqueness to avoid
        // exposing information about companies that the user cannot access.
        $companyReference = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
        ]);
        $companyId = (int) $companyReference['company_id'];
        $activeCompanyId = app(TenantContext::class)->requireId();
        abort_unless($companyId === $activeCompanyId, 404);
        $data = $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('establishments', 'code')
                    ->where(fn ($q) => $q->where('company_id', $companyId))->ignore($id),
            ],
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:2000',
            'active' => 'sometimes|boolean',
        ]);

        return DB::transaction(function () use ($id, $data) {
            $establishment = $id
                ? Establishment::where('company_id', app(TenantContext::class)->requireId())->findOrFail($id)
                : new Establishment();
            $establishment->fill($data)->save();
            Audit::record($id ? 'establishment.updated' : 'establishment.created', $establishment, [
                'company_id' => $establishment->company_id,
            ]);

            return response()->json($establishment->load('company:id,name,code'), $id ? 200 : 201);
        }, 3);
    }
}
