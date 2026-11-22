<?php

namespace App\Http\Controllers;

use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Services\Audit;
use App\Services\InventoryStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class InventoryStockController extends Controller
{
    public function balances(Request $r, InventoryStockService $service)
    {
        Gate::authorize('movementsRead');
        $values = $r->validate([
            'product_id' => 'sometimes|integer|min:1',
            'location_id' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1',
        ]);
        if (isset($values['product_id'])) Product::query()->findOrFail($values['product_id']);
        if (isset($values['location_id'])) $service->location($values['location_id']);

        return InventoryBalance::query()
            ->when(!Gate::allows('admin'), fn ($q) => $q->whereIn('location_id', \App\Models\InventoryLocation::query()->where('condition_code', '!=', 'regulated')->select('id')))
            ->when($values['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($values['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->orderBy('product_id')->orderBy('location_id')->paginate(20);
    }

    public function ledger(Request $r)
    {
        Gate::authorize('movementsRead');
        $values = $r->validate([
            'product_id' => 'sometimes|integer|min:1',
            'location_id' => 'sometimes|integer|min:1',
            'page' => 'sometimes|integer|min:1',
        ]);
        if (isset($values['product_id'])) Product::query()->findOrFail($values['product_id']);
        if (isset($values['location_id'])) app(InventoryStockService::class)->location($values['location_id']);
        return InventoryLedger::query()
            ->when(!Gate::allows('admin'), fn ($q) => $q->whereIn('location_id', \App\Models\InventoryLocation::query()->where('condition_code', '!=', 'regulated')->select('id')))
            ->when($values['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($values['location_id'] ?? null, fn ($q, $v) => $q->where('location_id', $v))
            ->orderByDesc('id')->paginate(20);
    }

    /**
     * M05 transitional explicit location allocation: moves an unallocated
     * part of legacy products.stock to a physical location without changing
     * the company-wide quantity. No implicit historical backfill.
     */
    public function allocate(Request $r, InventoryStockService $stock)
    {
        Gate::authorize('stock');
        $v = $r->validate([
            'product_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1',
            'lot_id' => 'nullable|integer|min:1',
            'quantity' => 'required|integer|min:1|max:1000000',
            'source_reference' => 'required|string|max:160',
            'reason' => 'required|string|min:5|max:500',
            'operation_key' => 'required|uuid',
            'company_id' => 'prohibited',
        ]);
        $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($stock, $v, $hash) {
            $product = Product::query()->lockForUpdate()->findOrFail($v['product_id']);
            if ($existing = $stock->matchingReplay('allocate', $v['operation_key'], $hash)) {
                return response()->json($existing);
            }
            $location = $stock->location($v['location_id'], true);
            $lot = $stock->lot($product, $v['lot_id'] ?? null);
            abort_if($location->condition_code === 'regulated' && !auth()->user()->can('admin'), 403);
            abort_unless($stock->unallocated($product) >= $v['quantity'], 409,
                'Le stock non localisé disponible est insuffisant.');
            $balance = $stock->balance($product, $location, $lot);
            $entry = $stock->change($balance, $v['quantity'], 'allocate',
                $v['source_reference'], $v['reason'], $v['operation_key'], $hash);
            Audit::record('inventory.stock.allocated', $product, ['ledger_id' => $entry->id]);
            return response()->json($entry, 201);
        }, 3);
    }

    /** External receipt creates both legacy global stock and local stock. */
    public function receive(Request $r, InventoryStockService $stock)
    {
        Gate::authorize('stock');
        $v = $r->validate([
            'product_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1',
            'lot_id' => 'nullable|integer|min:1',
            'quantity' => 'required|integer|min:1|max:1000000',
            'source_reference' => 'required|string|max:160',
            'reason' => 'required|string|min:5|max:500',
            'operation_key' => 'required|uuid',
            'company_id' => 'prohibited',
        ]);
        $hash = hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($stock, $v, $hash) {
            $product = Product::query()->lockForUpdate()->findOrFail($v['product_id']);
            if ($existing = $stock->matchingReplay('receive', $v['operation_key'], $hash)) {
                return response()->json($existing);
            }
            $location = $stock->location($v['location_id'], true);
            $lot = $stock->lot($product, $v['lot_id'] ?? null);
            abort_if($location->condition_code === 'regulated' && !auth()->user()->can('admin'), 403);
            app(\App\Services\StockService::class)->move($product, $v['quantity'],
                'Réception '.$v['source_reference']);
            $entry = $stock->change($stock->balance($product, $location, $lot),
                $v['quantity'], 'receive', $v['source_reference'], $v['reason'],
                $v['operation_key'], $hash);
            Audit::record('inventory.stock.received', $product, ['ledger_id' => $entry->id]);
            return response()->json($entry, 201);
        }, 3);
    }
}
