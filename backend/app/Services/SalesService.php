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
            $lines = [];
            $totals = ['subtotal_cents' => 0, 'tax_cents' => 0, 'total_cents' => 0];
            foreach ($data['items'] as $line) {
                $p = Product::findOrFail($line['product_id']);
                $amounts = Money::line($p->price_cents, $line['quantity'], $p->vat_bps);
                $lines[] = array_merge($amounts, ['product_id' => $p->id, 'reference' => $p->reference, 'name' => $p->name,
                    'quantity' => $line['quantity'], 'price_cents' => $p->price_cents, 'vat_bps' => $p->vat_bps]);
                foreach ($totals as $key => $value) {
                    $totals[$key] += $amounts[$key];
                }
            }
            $class = $kind === 'quotes' ? Quote::class : Order::class;
            $doc = $class::create(array_merge($totals, ['number' => DocumentNumber::next($kind === 'quotes' ? 'DEV' : 'CMD'),
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
            $order = Order::create(array_merge($q->only(['customer_id', 'notes', 'subtotal_cents', 'tax_cents', 'total_cents']),
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
