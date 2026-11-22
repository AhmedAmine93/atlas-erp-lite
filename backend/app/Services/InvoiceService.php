<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function create(int $orderId): Invoice
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            abort_if($order->status !== 'validated', 409, 'Validez la commande avant de la facturer.');
            abort_if($order->invoice()->exists(), 409, 'Cette commande possède déjà une facture.');
            $invoice = Invoice::create(array_merge($order->only(['subtotal_cents', 'tax_cents', 'total_cents']), ['order_id' => $orderId,
                'snapshot' => ['customer' => $order->customer->only(['name', 'email', 'phone', 'address']), 'order_number' => $order->number,
                    'items' => $order->items->toArray(), 'seller' => config('atlas.seller')]]));
            Audit::record('invoice.created', $invoice);

            return $invoice;
        }, 3);
    }

    public function transition(int $id, string $status, ?string $dueAt = null): Invoice
    {
        return DB::transaction(function () use ($id, $status, $dueAt) {
            $invoice = Invoice::lockForUpdate()->findOrFail($id);
            $allowed = ['draft' => ['issued', 'cancelled'], 'issued' => ['paid', 'cancelled'], 'paid' => [], 'cancelled' => []];
            abort_unless(in_array($status, $allowed[$invoice->status]), 409, 'Transition de facture interdite.');
            $changes = ['status' => $status];
            if ($status === 'issued') {
                $changes['number'] = DocumentNumber::next('FAC');
                $changes['issued_at'] = now();
                if ($dueAt !== null) $changes['due_at'] = $dueAt;
            }
            if ($status === 'paid') {
                $changes['paid_at'] = now();
            }
            $invoice->update($changes);
            Audit::record('invoice.'.$status, $invoice);

            return $invoice;
        }, 3);
    }
}
