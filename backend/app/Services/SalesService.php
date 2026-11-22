<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Support\Facades\DB;

class SalesService
{
    public function create(string $kind, array $data)
    {
        return DB::transaction(function () use ($kind, $data) {
            $site = SalesAccess::resolveSite($data['establishment_id'] ?? null);
            $lines = [];
            $totals = ['subtotal_cents' => 0, 'tax_cents' => 0, 'total_cents' => 0];
            foreach ($data['items'] as $line) {
                $p = Product::findOrFail($line['product_id']);
                $item = app(SalesPricing::class)->line($p,$line['quantity'],0,$site,null)['item'];
                $lines[] = $item;
                foreach (array_keys($totals) as $key) $totals[$key] += $item[$key];
            }
            $class = $kind === 'quotes' ? Quote::class : Order::class;
            $doc = $class::create(array_merge($totals, ['sales_establishment_id'=>$site],
                $kind === 'orders' && isset($data['fulfillment_mode']) ? ['fulfillment_mode'=>$data['fulfillment_mode']] : [], ['number' => DocumentNumber::next($kind === 'quotes' ? 'DEV' : 'CMD'),
                'customer_id' => $data['customer_id'], 'notes' => $data['notes'] ?? null, 'created_by' => auth()->id()]));
            $doc->items()->createMany($lines);
            Audit::record($kind.'.created', $doc);

            return $doc->load('items', 'customer');
        }, 3);
    }

    public function convert(int $id): Order
    {
        return DB::transaction(function () use ($id) {
            $q = Quote::lockForUpdate()->findOrFail($id);
            abort_if($q->status !== 'draft', 409, 'Ce devis a déjà été converti.');
            // Revised V5 quotations must complete approval and acceptance first.
            abort_if((int) $q->revision_no > 0, 409, 'Utilisez la conversion de la révision acceptée.');
            $order = Order::create(array_merge($q->only(['customer_id', 'sales_establishment_id', 'notes', 'subtotal_cents', 'tax_cents', 'total_cents']),
                ['number' => DocumentNumber::next('CMD'), 'quote_id' => $q->id, 'created_by' => auth()->id()]));
            $order->items()->createMany($q->items->map(fn ($i) => $i->only(['product_id', 'reference', 'name', 'quantity', 'price_cents', 'vat_bps', 'subtotal_cents', 'tax_cents', 'total_cents']))->all());
            $q->update(['status' => 'converted']);
            Audit::record('quote.converted', $q, ['order_id' => $order->id]);

            return $order->load('items', 'customer');
        }, 3);
    }

    public function validateOrder(int $id): Order
    {
        return DB::transaction(function () use ($id) {
            $order = Order::lockForUpdate()->findOrFail($id);
            abort_if($order->status !== 'draft', 409, 'Cette commande est déjà validée.');
            abort_if($order->fulfillment_mode === 'reservation_v2',409,'Confirmez puis réservez cette commande dans le cycle commercial.');
            // Stable lock ordering avoids deadlocks for orders sharing products.
            $products = Product::whereIn('id', $order->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($order->items as $item) {
                app(StockService::class)->move($products[$item->product_id], -$item->quantity, 'Commande '.$order->number, $order->id);
            }
            $order->update(['status' => 'validated', 'validated_at' => now()]);
            Audit::record('order.validated', $order);

            return $order->load('items','customer');
        }, 3);
    }
}
