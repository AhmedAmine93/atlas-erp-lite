<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryLedger;
use App\Models\InventoryLocation;
use App\Models\InventoryLot;
use App\Models\InventoryTransfer;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * M05 stock allocation is a subset of the legacy products.stock global stock.
 * Each operation MUST lock the products row first. In-flight transfers remain
 * part of the global physical stock but are never sellable.
 */
final class InventoryStockService
{
    public function allocated(int $productId): int
    {
        return (int) InventoryBalance::query()->where('product_id', $productId)->sum('quantity');
    }

    public function inTransit(int $productId): int
    {
        return (int) InventoryTransfer::query()->where('product_id', $productId)
            ->where('status', 'in_transit')
            ->selectRaw('COALESCE(SUM(quantity_shipped - quantity_received - quantity_lost), 0) AS total')
            ->value('total');
    }

    public function unallocated(Product $product): int
    {
        $remaining = (int) $product->stock - $this->allocated($product->id) - $this->inTransit($product->id);
        if ($remaining < 0) {
            throw new \LogicException('Inventory reconciliation invariant violated for product '.$product->id);
        }
        return $remaining;
    }

    public function location(int $id, bool $forWrite = false): InventoryLocation
    {
        $q = InventoryLocation::query()->with('warehouse');
        if ($forWrite) $q->lockForUpdate();
        $location = $q->findOrFail($id);
        if ($forWrite) {
            abort_unless($location->active && $location->warehouse?->active, 409,
                'Cet emplacement ou son dépôt est inactif.');
        }
        return $location;
    }

    public function lot(Product $product, ?int $lotId): ?InventoryLot
    {
        if ($lotId === null) return null;
        return InventoryLot::query()->where('product_id', $product->id)->findOrFail($lotId);
    }

    public function balance(Product $product, InventoryLocation $location, ?InventoryLot $lot): InventoryBalance
    {
        return InventoryBalance::query()->firstOrCreate([
            'product_id' => $product->id, 'location_id' => $location->id,
            'lot_id' => $lot?->id,
        ], ['quantity' => 0]);
    }

    public function change(
        InventoryBalance $balance, int $delta, string $operation, string $reference,
        string $reason, ?string $key = null, ?string $hash = null
    ): InventoryLedger {
        if ($delta === 0) throw new \InvalidArgumentException('Nonzero inventory movement required.');
        $next = (int) $balance->quantity + $delta;
        if ($next < 0 || $next > 100000000) {
            throw ValidationException::withMessages(['quantity' => 'Solde insuffisant ou capacité dépassée.']);
        }
        $balance->update(['quantity' => $next]);
        return InventoryLedger::create([
            'product_id' => $balance->product_id,
            'location_id' => $balance->location_id,
            'lot_id' => $balance->lot_id,
            'quantity' => $delta, 'balance_after' => $next,
            'operation' => $operation, 'source_reference' => $reference,
            'reason' => $reason, 'operation_key' => $key,
            'operation_hash' => $hash, 'user_id' => auth()->id(),
        ]);
    }

    public function matchingReplay(string $operation, string $key, string $hash): ?InventoryLedger
    {
        $existing = InventoryLedger::query()->where('operation', $operation)
            ->where('operation_key', $key)->first();
        if ($existing && !hash_equals((string) $existing->operation_hash, $hash)) {
            abort(409, 'Clé de rejeu déjà utilisée avec un contenu différent.');
        }
        return $existing;
    }

    /**
     * Prevent blocked/quarantined/in-transit material being counted as sellable.
     * Caller holds the products row lock when used for business decisions.
     */
    public function sellable(Product $product): int
    {
        $eligible = InventoryBalance::query()
            ->join('inventory_locations as l', 'l.id', '=', 'inventory_balances.location_id')
            ->join('inventory_warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('inventory_lots as t', 't.id', '=', 'inventory_balances.lot_id')
            ->where('inventory_balances.product_id', $product->id)
            ->where('l.active', true)->where('w.active', true)
            ->where('l.condition_code', 'normal')
            ->where(fn ($q) => $q->whereNull('t.id')
                ->orWhere(fn ($q) => $q->where('t.status', 'available')
                    ->where(fn ($q) => $q->whereNull('t.expires_on')
                        ->orWhereDate('t.expires_on', '>=', today()))))
            ->sum('inventory_balances.quantity');

        return $this->unallocated($product) + (int) $eligible;
    }

    /** Sale or legacy negative adjustment, called with the products row locked. */
    public function consumeForLegacyMove(Product $product, int $quantity, string $sourceReference): void
    {
        if ($quantity <= 0) throw new \InvalidArgumentException('Positive quantity expected.');
        $unallocated = $this->unallocated($product);

        // A warehouse stock item is sellable only in a normal active location
        // and in an available lot. Never consume in-transit or quarantined units.
        $eligible = InventoryBalance::query()
            ->join('inventory_locations as l', 'l.id', '=', 'inventory_balances.location_id')
            ->join('inventory_warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->leftJoin('inventory_lots as t', 't.id', '=', 'inventory_balances.lot_id')
            ->where('inventory_balances.product_id', $product->id)
            ->where('l.active', true)->where('w.active', true)
            ->where('l.condition_code', 'normal')
            ->where(fn ($q) => $q->whereNull('t.id')
                ->orWhere(fn ($q) => $q->where('t.status', 'available')
                    ->where(fn ($q) => $q->whereNull('t.expires_on')
                        ->orWhereDate('t.expires_on', '>=', today()))))
            ->select('inventory_balances.*')
            ->orderByRaw('t.expires_on ASC NULLS LAST')
            ->orderBy('inventory_balances.id')->get();

        $sellable = $unallocated + (int) $eligible->sum('quantity');
        if ($quantity > $sellable) {
            throw ValidationException::withMessages(['quantity' =>
                'Stock vendable insuffisant : quarantaine, blocage ou transit.']);
        }
        $remaining = max(0, $quantity - $unallocated);
        foreach ($eligible as $balance) {
            if ($remaining === 0) break;
            $take = min($remaining, (int) $balance->quantity);
            if ($take === 0) continue;
            $this->change($balance, -$take, 'legacy_sale', $sourceReference,
                'Débit pour vente ou correction de stock global');
            $remaining -= $take;
        }
        if ($remaining !== 0) throw new \LogicException('Sellable stock consumption mismatch.');
    }
}
