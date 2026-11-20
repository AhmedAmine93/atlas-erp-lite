<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Establishment;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    public function companies()
    {
        Gate::authorize('admin');

        return Company::withCount('establishments')->orderBy('name')->get();
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
            $company = $id ? Company::findOrFail($id) : new Company();
            $company->fill($data)->save();
            Audit::record($id ? 'company.updated' : 'company.created', $company);

            return response()->json($company->loadCount('establishments'), $id ? 200 : 201);
        }, 3);
    }

    public function establishments(Request $request)
    {
        Gate::authorize('admin');
        $filter = $request->validate(['company_id' => 'nullable|integer|exists:companies,id']);

        return Establishment::with('company:id,name,code')
            ->when(isset($filter['company_id']), fn ($q) => $q->where('company_id', $filter['company_id']))
            ->orderBy('company_id')->orderBy('code')->get();
    }

    public function saveEstablishment(Request $request, ?int $id = null)
    {
        Gate::authorize('admin');
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        $companyId = $request->input('company_id');
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
            $establishment = $id ? Establishment::findOrFail($id) : new Establishment();
            $establishment->fill($data)->save();
            Audit::record($id ? 'establishment.updated' : 'establishment.created', $establishment, [
                'company_id' => $establishment->company_id,
            ]);

            return response()->json($establishment->load('company:id,name,code'), $id ? 200 : 201);
        }, 3);
    }
}
