<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Supplier;
use App\Services\TenantContext;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ContactController extends Controller
{
    private function model(string $kind): string
    {
        return $kind === 'customers' ? Customer::class : Supplier::class;
    }

    public function index(Request $r, string $kind)
    {
        Gate::authorize('crmRead');
        $r->validate(['search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);

        return $this->model($kind)::query()->when($r->search, fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($r->search).'%'])->orWhereRaw('LOWER(email) LIKE ?', ['%'.mb_strtolower($r->search).'%'])))
            ->when($kind === 'customers', fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('name')->paginate(20);
    }

    public function show(string $kind, int $id)
    {
        Gate::authorize('crmRead');
        $item = $this->model($kind)::findOrFail($id);

        return $kind === 'customers' ? $item->load(['orders' => fn ($q) => $q->latest()->limit(100)]) : $item;
    }

    public function save(Request $r, string $kind, ?int $id = null)
    {
        Gate::authorize('crm');
        $item = $id ? $this->model($kind)::findOrFail($id) : new ($this->model($kind));
        if ($kind === 'customers') abort_if($item->exists && $item->archived_at !== null, 409, 'Client archivé.');
        $rules = ['name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255',
                Rule::unique($kind)->where('company_id', app(TenantContext::class)->requireId())->ignore($id)],
            'phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:2000'];
        if ($kind === 'suppliers') {
            $rules['tax_registration'] = ['nullable','string','max:80',
                Rule::unique('suppliers')->where('company_id', app(TenantContext::class)->requireId())->ignore($id)];
            $rules['procurement_status'] = 'prohibited';
        }
        $data = $r->validate($rules);

        return DB::transaction(function () use ($item, $data, $id) {
            $item->fill($data)->save();
            Audit::record($id ? 'contact.updated' : 'contact.created', $item);

            return response()->json($item, $id ? 200 : 201);
        });
    }

    public function destroy(string $kind, int $id)
    {
        Gate::authorize('crm');

        return DB::transaction(function () use ($kind, $id) {
            $item = $this->model($kind)::findOrFail($id);
            if ($kind === 'suppliers') {
                $item->procurement_status = 'archived';
                $item->save();
                Audit::record('supplier.archived', $item);
            } else {
                if (! $item->archived_at) {
                    $item->archived_at = now();
                    $item->archive_reason = 'Archivage demandé depuis le CRM';
                    $item->save();
                    Audit::record('crm.customer.archived', $item);
                }
            }

            return response()->noContent();
        });
    }
}
