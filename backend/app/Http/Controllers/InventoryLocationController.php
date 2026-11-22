<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\InventoryWarehouse;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * M05.03 — Company-scoped, server-authorized warehouse registry.
 * Mutations never accept company_id and do not delete historical locations.
 */
final class InventoryLocationController extends Controller
{
    public function warehouses(Request $request)
    {
        Gate::authorize('movementsRead');
        $filter = $request->validate([
            'search' => 'sometimes|string|max:100',
            'active' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1',
        ]);

        return InventoryWarehouse::query()
            ->when($filter['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($q) => $q->where('code', 'ilike', '%'.$s.'%')->orWhere('name', 'ilike', '%'.$s.'%')
            ))
            ->when(array_key_exists('active', $filter), fn ($q) => $q->where('active', $filter['active']))
            ->orderBy('code')->paginate(20);
    }

    public function createWarehouse(Request $request)
    {
        Gate::authorize('stock');
        $company = app(TenantContext::class)->requireId();
        $values = $request->validate([
            'code' => ['required', 'string', 'alpha_dash', 'max:32',
                Rule::unique('inventory_warehouses', 'code')->where('company_id', $company)],
            'name' => 'required|string|max:180',
            'company_id' => 'prohibited',
            'active' => 'prohibited',
        ]);

        return DB::transaction(function () use ($values) {
            $record = InventoryWarehouse::create($values + ['active' => true]);
            Audit::record('inventory.warehouse.created', $record);
            return response()->json($record, 201);
        }, 3);
    }

    public function updateWarehouse(Request $request, int $id)
    {
        Gate::authorize('stock');
        $values = $request->validate([
            'name' => 'sometimes|required|string|max:180',
            'active' => 'sometimes|boolean',
            'company_id' => 'prohibited',
            'code' => 'prohibited',
        ]);

        return DB::transaction(function () use ($values, $id) {
            $record = InventoryWarehouse::query()->lockForUpdate()->findOrFail($id);
            abort_if($values === [], 422, 'Aucune modification fournie.');
            if (array_key_exists('active', $values) && !$values['active']) {
                abort_if($record->locations()->where('active', true)->exists(), 409,
                    'Désactivez les emplacements actifs avant de fermer le dépôt.');
            }
            $record->update($values);
            Audit::record('inventory.warehouse.updated', $record, ['fields' => array_keys($values)]);
            return response()->json($record->refresh());
        }, 3);
    }

    public function locations(Request $request)
    {
        Gate::authorize('movementsRead');
        $values = $request->validate([
            'warehouse_id' => 'sometimes|integer|min:1',
            'active' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1',
        ]);
        if (isset($values['warehouse_id'])) {
            InventoryWarehouse::query()->findOrFail($values['warehouse_id']);
        }
        return InventoryLocation::query()
            ->with('warehouse:id,company_id,code,name')
            ->when($values['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when(array_key_exists('active', $values), fn ($q) => $q->where('active', $values['active']))
            ->orderBy('warehouse_id')->orderBy('code')->paginate(20);
    }

    public function createLocation(Request $request)
    {
        Gate::authorize('stock');
        $company = app(TenantContext::class)->requireId();
        $values = $request->validate([
            'warehouse_id' => 'required|integer|min:1',
            'code' => 'required|string|alpha_dash|max:32',
            'name' => 'required|string|max:180',
            'condition_code' => ['sometimes', Rule::in(['normal', 'quarantine', 'regulated'])],
            'company_id' => 'prohibited',
            'active' => 'prohibited',
        ]);

        if (($values['condition_code'] ?? 'normal') === 'regulated') {
            Gate::authorize('admin');
        }

        return DB::transaction(function () use ($company, $values) {
            $warehouse = InventoryWarehouse::query()->lockForUpdate()->findOrFail($values['warehouse_id']);
            abort_unless($warehouse->active, 409, 'Le dépôt est inactif.');
            $exists = InventoryLocation::query()
                ->where('warehouse_id', $warehouse->id)->where('code', $values['code'])->exists();
            abort_if($exists, 409, 'Ce code emplacement existe déjà dans ce dépôt.');
            $record = InventoryLocation::create($values + ['active' => true, 'condition_code' => 'normal']);
            Audit::record('inventory.location.created', $record, ['warehouse_id' => $warehouse->id]);
            return response()->json($record->load('warehouse'), 201);
        }, 3);
    }

    public function updateLocation(Request $request, int $id)
    {
        Gate::authorize('stock');
        $values = $request->validate([
            'name' => 'sometimes|required|string|max:180',
            'active' => 'sometimes|boolean',
            'condition_code' => ['sometimes', Rule::in(['normal', 'quarantine', 'regulated'])],
            'code' => 'prohibited',
            'warehouse_id' => 'prohibited',
            'company_id' => 'prohibited',
        ]);

        if (($values['condition_code'] ?? null) === 'regulated') {
            Gate::authorize('admin');
        }

        return DB::transaction(function () use ($id, $values) {
            $record = InventoryLocation::query()->lockForUpdate()->findOrFail($id);
            abort_if($values === [], 422, 'Aucune modification fournie.');
            $warehouse = InventoryWarehouse::query()->lockForUpdate()->findOrFail($record->warehouse_id);
            if (($values['active'] ?? $record->active) && !$warehouse->active) {
                abort(409, 'Un emplacement ne peut être actif dans un dépôt désactivé.');
            }
            $record->update($values);
            Audit::record('inventory.location.updated', $record, ['fields' => array_keys($values)]);
            return response()->json($record->refresh()->load('warehouse'));
        }, 3);
    }
}
