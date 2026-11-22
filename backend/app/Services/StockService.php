<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    public function adjust(int $id, int $quantity, string $reason): Product
    {
        return DB::transaction(function () use ($id, $quantity, $reason) {
            $p = Product::lockForUpdate()->findOrFail($id);
            $this->move($p, $quantity, $reason);
            Audit::record('stock.adjusted', $p, ['quantity' => $quantity]);

            return $p;
        }, 3);
    }

    // Caller holds product lock and transaction; shared by orders and manual corrections.
    public function move(Product $product, int $quantity, string $reason, ?int $orderId = null, bool $consumeAllocated = true): void
    {
        $balance = $product->stock + $quantity;
        if ($balance < 0 || $balance > 100000000) {
            throw ValidationException::withMessages(['stock' => 'Stock insuffisant ou capacité dépassée : '.$product->name]);
        }
        if ($quantity < 0 && $consumeAllocated) {
            // Existing global stock remains authoritative during M05 transition.
            // Exclude blocked lots, quarantine and in-transit inventory.
            app(InventoryStockService::class)->consumeForLegacyMove(
                $product, -$quantity, $reason
            );
        }
        $product->update(['stock' => $balance]);
        StockMovement::create(['product_id' => $product->id, 'quantity' => $quantity, 'balance_after' => $balance,
            'reason' => $reason, 'order_id' => $orderId, 'user_id' => auth()->id()]);
    }
}
