<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\TenantContext;
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
        // All stock-consuming paths, including legacy order validation and manual
        // adjustments, must respect V5 commercial reservations.
        $reserved = 0;
        if ($quantity < 0 && Schema::hasTable('sales_reservations')) {
            $reserved = (int) DB::table('sales_reservations')
                ->where('company_id', app(TenantContext::class)->requireId())
                ->where('product_id', $product->id)
                ->selectRaw('COALESCE(SUM(quantity_reserved - quantity_shipped - quantity_released), 0) AS reserved')
                ->value('reserved');
        }
        if ($balance < $reserved || $balance > 100000000) {
            throw ValidationException::withMessages(['stock' => 'Stock insuffisant ou capacité dépassée : '.$product->name]);
        }
        if ($quantity < 0 && $consumeAllocated) {
            // Shared V5 reservation check remains enforced above.
            // Consume eligible allocated stock only through the M05 service.
            app(InventoryStockService::class)->consumeForLegacyMove($product, -$quantity, $reason);
        }
        $product->update(['stock' => $balance]);
        StockMovement::create(['product_id' => $product->id, 'quantity' => $quantity, 'balance_after' => $balance,
            'reason' => $reason, 'order_id' => $orderId, 'user_id' => auth()->id()]);
    }
}
