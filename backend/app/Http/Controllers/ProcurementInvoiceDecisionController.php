<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Conservative supplier invoice re-matching and independent payment signoff.
 * No payment service is called. All decisions are tied to authenticated users.
 */
final class ProcurementInvoiceDecisionController extends Controller
{
    private function tenant(): int { return app(TenantContext::class)->requireId(); }
    private function row(string $table,int $id): object
    {
        return DB::table($table)->where('company_id',$this->tenant())->where('id',$id)
            ->lockForUpdate()->firstOrFail();
    }
    private function authorized(): void { Gate::authorize('admin'); }

    /** Calculate receipts + signed service performance at locked order level. */
    private function eligibleAmount(object $order): int
    {
        $tenant=$this->tenant();
        $received=DB::table('purchase_receipt_lines as line')
            ->join('purchase_receipts as receipt',function($join){
                $join->on('receipt.id','=','line.purchase_receipt_id')
                     ->on('receipt.company_id','=','line.company_id');
            })
            ->where('line.company_id',$tenant)->where('receipt.purchase_order_id',$order->id)
            ->groupBy('line.purchase_order_line_id')
            ->select('line.purchase_order_line_id',DB::raw('SUM(line.accepted_quantity) AS qty'))
            ->get()->keyBy('purchase_order_line_id');
        $services=DB::table('purchase_service_acceptances')
            ->where('company_id',$tenant)->where('purchase_order_id',$order->id)
            ->groupBy('purchase_order_line_id')
            ->select('purchase_order_line_id',DB::raw('SUM(accepted_quantity) AS qty'))
            ->get()->keyBy('purchase_order_line_id');
        $sum=0;
        foreach(DB::table('purchase_order_lines')->where('company_id',$tenant)
            ->where('purchase_order_id',$order->id)->get() as $line){
            $qty=(int)($received->get($line->id)?->qty??0)
                +(int)($services->get($line->id)?->qty??0);
            $net=$qty*(int)$line->unit_price_cents;
            $sum += $net+intdiv($net*(int)$line->vat_bps+5000,10000);
        }
        return $sum;
    }

    public function rematch(int $invoiceId)
    {
        $this->authorized();
        return DB::transaction(function() use($invoiceId){
            // Lock the order before invoice to serialize against all receipts.
            $initial=DB::table('supplier_invoices')->where('company_id',$this->tenant())
                ->where('id',$invoiceId)->firstOrFail();
            $order=$this->row('purchase_orders',$initial->purchase_order_id);
            $invoice=$this->row('supplier_invoices',$invoiceId);
            abort_if(DB::table('supplier_invoice_payment_authorizations')
                ->where('company_id',$this->tenant())->where('supplier_invoice_id',$invoiceId)
                ->exists(),409,'Facture déjà autorisée pour paiement.');
            $eligible=$this->eligibleAmount($order);
            $others=(int)DB::table('supplier_invoices')->where('company_id',$this->tenant())
                ->where('purchase_order_id',$order->id)->where('id','<>',$invoiceId)
                ->sum('matched_cents');
            $matched=min((int)$invoice->total_cents,max(0,$eligible-$others));
            $disputed=(int)$invoice->total_cents-$matched;
            $status=$disputed>0?'disputed':'matched';
            DB::table('supplier_invoices')->where('company_id',$this->tenant())
                ->where('id',$invoiceId)->update([
                    'matched_cents'=>$matched,'dispute_cents'=>$disputed,
                    'status'=>$status,'updated_at'=>now(),
                ]);
            Audit::record('purchase.invoice.rematched',null,[
                'record_id'=>$invoiceId,'matched_cents'=>$matched,
                'dispute_cents'=>$disputed,
            ]);
            return response()->json(['id'=>$invoiceId,'matched_cents'=>$matched,
                'dispute_cents'=>$disputed,'status'=>$status]);
        },3);
    }

    public function paymentSignoff(Request $request,int $invoiceId)
    {
        $this->authorized();
        return DB::transaction(function() use($request,$invoiceId){
            $initial=DB::table('supplier_invoices')->where('company_id',$this->tenant())
                ->where('id',$invoiceId)->firstOrFail();
            $order=$this->row('purchase_orders',$initial->purchase_order_id);
            $invoice=$this->row('supplier_invoices',$invoiceId);
            abort_if(DB::table('supplier_invoice_payment_authorizations')
                ->where('company_id',$this->tenant())->where('supplier_invoice_id',$invoiceId)
                ->exists(),409,'Paiement déjà autorisé.');
            abort_if((int)$invoice->created_by===(int)$request->user()->id,
                403,'Une autre personne doit autoriser le bon à payer.');
            // Fail closed if the receipt balance changed after the last match.
            $eligible=$this->eligibleAmount($order);
            $matchedTotal=(int)DB::table('supplier_invoices')->where('company_id',$this->tenant())
                ->where('purchase_order_id',$order->id)->sum('matched_cents');
            abort_if($matchedTotal>$eligible,409,'Rapprochement des réceptions obsolète.');
            $credits=(int)DB::table('supplier_credit_notes')->where('company_id',$this->tenant())
                ->where('supplier_invoice_id',$invoiceId)->sum('amount_cents');
            $net=(int)$invoice->total_cents-$credits;
            abort_if($net<=0,409,'Solde nul : aucune autorisation nécessaire.');
            abort_if($net>(int)$invoice->matched_cents,409,'Solde net supérieur aux réceptions acceptées.');
            abort_unless(in_array($invoice->status,['matched','disputed'],true),409,'Facture non autorisable.');
            $id=DB::table('supplier_invoice_payment_authorizations')->insertGetId([
                'company_id'=>$this->tenant(),'supplier_invoice_id'=>$invoiceId,
                'authorized_amount_cents'=>$net,'authorized_by'=>$request->user()->id,
                'authorized_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('supplier_invoices')->where('company_id',$this->tenant())
                ->where('id',$invoiceId)->update(['status'=>'ready_for_payment','updated_at'=>now()]);
            Audit::record('purchase.invoice.payment.authorized',null,[
                'record_id'=>$invoiceId,'authorization_id'=>$id,'amount_cents'=>$net,
            ]);
            return response()->json(['invoice_id'=>$invoiceId,'status'=>'ready_for_payment',
                'authorized_amount_cents'=>$net],201);
        },3);
    }

    public function authorizations(int $invoiceId)
    {
        $this->authorized();
        DB::table('supplier_invoices')->where('company_id',$this->tenant())
            ->where('id',$invoiceId)->firstOrFail();
        return DB::table('supplier_invoice_payment_authorizations')
            ->where('company_id',$this->tenant())->where('supplier_invoice_id',$invoiceId)
            ->orderByDesc('id')->paginate(20);
    }
}
