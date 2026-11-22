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
            $v5=$order->fulfillment_mode==='reservation_v2';
            $eligible=$v5 ? in_array($order->status,['shipped','cancelled'],true) : $order->status==='validated';
            abort_unless($eligible,409,'Terminez les expéditions ou annulez le reliquat avant facturation.');
            abort_if($order->invoice()->exists(),409,'Cette commande possède déjà une facture.');
            $items=$order->items->toArray();
            $totals=$order->only(['subtotal_cents','tax_cents','total_cents']);
            if ($v5) {
                $deliveries=DB::table('sales_deliveries')->where('company_id',$order->company_id)->where('order_id',$order->id);
                $frozen=DB::table('sales_quote_revisions')->where('company_id',$order->company_id)->where('quote_id',$order->quote_id)
                    ->where('status','converted')->value('snapshot');
                $terms=$frozen ? json_decode($frozen,true,512,JSON_THROW_ON_ERROR) : [];
                if ((clone $deliveries)->where('status','lost')->exists()
                    || (($terms['contract']['receipt_required'] ?? false) && (clone $deliveries)->where('status','!=','received')->exists())) {
                    abort(409,'La livraison doit être régularisée avant facturation.');
                }
                $shipped=DB::table('sales_delivery_lines')->where('company_id',$order->company_id)
                    ->whereIn('delivery_id',$deliveries->select('id'))->groupBy('order_item_id')
                    ->selectRaw('order_item_id, SUM(quantity) AS quantity')->pluck('quantity','order_item_id');
                abort_if($shipped->isEmpty(),409,'Aucune quantité livrée à facturer.');
                $totals=array_fill_keys(array_keys($totals),0);
                $items=[];
                foreach ($order->items as $line) {
                    $qty=(int)($shipped[$line->id] ?? 0);
                    if ($qty===0) continue;
                    $amounts=Money::line($line->price_cents,$qty,$line->vat_bps);
                    foreach ($amounts as $key=>$value) $totals[$key]+=$value;
                    $items[]=array_merge($line->toArray(),['quantity'=>$qty],$amounts);
                }
            }
            $invoice = Invoice::create(array_merge($totals,['order_id'=>$orderId,
                'snapshot'=>['customer'=>$order->customer->only(['name','email','phone','address']),'order_number'=>$order->number,
                    'items'=>$items,'seller'=>config('atlas.seller')]]));
            Audit::record('invoice.created', $invoice);

            return $invoice;
        }, 3);
    }

    public function transition(int $id, string $status): Invoice
    {
        return DB::transaction(function () use ($id, $status) {
            $invoice = Invoice::lockForUpdate()->findOrFail($id);
            $allowed = ['draft' => ['issued', 'cancelled'], 'issued' => ['paid', 'cancelled'], 'paid' => [], 'cancelled' => []];
            abort_unless(in_array($status, $allowed[$invoice->status]), 409, 'Transition de facture interdite.');
            $changes = ['status' => $status];
            if ($status === 'issued') {
                $changes['number'] = DocumentNumber::next('FAC');
                $changes['issued_at'] = now();
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
