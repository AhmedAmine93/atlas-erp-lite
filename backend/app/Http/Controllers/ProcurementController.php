<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * M04 purchasing workflows. Each mutation uses the verified session tenant and a
 * row lock for state transitions. No client-supplied company_id is accepted.
 * Money is persisted as integer cents; no floating-point business arithmetic.
 */
final class ProcurementController extends Controller
{
    private function company(): int { return app(TenantContext::class)->requireId(); }
    private function authorizePurchasing(): void { Gate::authorize('admin'); }

    private function row(string $table, int $id, bool $lock = false): object
    {
        $query = DB::table($table)->where('company_id', $this->company())->where('id', $id);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function log(string $action, int $id, array $extra = []): void
    {
        Audit::record('purchase.'.$action, null, ['record_id' => $id] + $extra);
    }

    private function ensureSupplier(int $id): object
    {
        $supplier = $this->row('suppliers', $id, true);
        abort_unless(($supplier->procurement_status ?? 'active') === 'active', 409, 'Fournisseur bloqué ou archivé.');
        return $supplier;
    }

    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function orderTotal(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $net = (int) $line['quantity'] * (int) $line['unit_price_cents'];
            $tax = intdiv($net * (int) ($line['vat_bps'] ?? 0) + 5000, 10000);
            $total += $net + $tax;
            abort_if($total > 100000000000, 422, 'Montant total hors limite.');
        }
        return $total;
    }

    public function suppliers(Request $request)
    {
        $this->authorizePurchasing();
        $request->validate(['page' => 'sometimes|integer|min:1']);
        return DB::table('suppliers')->where('company_id', $this->company())
            ->select('id','name','email','phone','address','tax_registration','procurement_status','blocked_reason','blocked_at','created_at')
            ->orderBy('name')->paginate(20);
    }

    public function supplierStatus(Request $request, int $id)
    {
        $this->authorizePurchasing();
        $data = $request->validate([
            'status' => ['required',Rule::in(['active','blocked','archived'])],
            'reason' => 'required_if:status,blocked|string|max:1000',
        ]);
        return DB::transaction(function () use ($id, $data) {
            $supplier = $this->row('suppliers', $id, true);
            abort_if($supplier->procurement_status === 'archived', 409, 'Fournisseur archivé : modification interdite.');
            DB::table('suppliers')->where('id', $id)->where('company_id', $this->company())->update([
                'procurement_status' => $data['status'],
                'blocked_reason' => $data['status'] === 'blocked' ? $data['reason'] : null,
                'blocked_at' => $data['status'] === 'blocked' ? now() : null,
                'updated_at' => now(),
            ]);
            $this->log('supplier.status', $id, ['status'=>$data['status'], 'reason'=>$data['reason'] ?? null]);
            return response()->json(['id'=>$id, 'status'=>$data['status']]);
        }, 3);
    }

    public function bankChange(Request $request, int $supplierId)
    {
        $this->authorizePurchasing();
        $data = $request->validate(['iban'=>'required|string|min:15|max:34']);
        $iban = strtoupper(preg_replace('/\s+/', '', $data['iban']));
        abort_unless(preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban), 422, 'IBAN mal formé.');
        return DB::transaction(function () use ($request, $supplierId, $iban) {
            $this->ensureSupplier($supplierId);
            $existing = DB::table('supplier_bank_changes')->where('company_id',$this->company())
                ->where('supplier_id',$supplierId)->where('status','pending')->lockForUpdate()->exists();
            abort_if($existing,409,'Une demande bancaire est déjà en attente.');
            $id = DB::table('supplier_bank_changes')->insertGetId([
                'company_id'=>$this->company(), 'supplier_id'=>$supplierId,
                'encrypted_iban'=>Crypt::encryptString($iban), 'iban_last4'=>substr($iban,-4),
                'status'=>'pending','requested_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log('supplier.bank.requested',$id,['supplier_id'=>$supplierId]);
            return response()->json(['id'=>$id,'status'=>'pending','iban_last4'=>substr($iban,-4)],201);
        }, 3);
    }

    public function bankList(int $supplierId)
    {
        $this->authorizePurchasing();
        $this->row('suppliers', $supplierId);
        return DB::table('supplier_bank_changes')->where('company_id',$this->company())
            ->where('supplier_id',$supplierId)
            ->select('id','supplier_id','iban_last4','status','requested_by','decided_by','decided_at','created_at')
            ->orderByDesc('id')->paginate(20);
    }

    public function decideBank(Request $request, int $id)
    {
        $this->authorizePurchasing();
        $data=$request->validate(['decision'=>['required',Rule::in(['approved','rejected'])],
            'reason'=>'required_if:decision,rejected|nullable|string|max:1000']);
        return DB::transaction(function () use ($id,$request,$data) {
            $change=$this->row('supplier_bank_changes',$id,true);
            abort_unless($change->status==='pending',409,'Décision déjà prise.');
            abort_if((int)$change->requested_by===(int)$request->user()->id,403,'Validation indépendante obligatoire.');
            DB::table('supplier_bank_changes')->where('id',$id)->where('company_id',$this->company())->update([
                'status'=>$data['decision'],'decided_by'=>$request->user()->id,
                'decided_at'=>now(),'decision_reason'=>$data['reason']??null,'updated_at'=>now(),
            ]);
            $this->log('supplier.bank.decided',$id,['decision'=>$data['decision']]);
            return response()->json(['id'=>$id,'status'=>$data['decision']]);
        },3);
    }

    public function requestHistory(int $id)
    {
        $this->authorizePurchasing();
        $this->row('purchase_requests',$id);
        return DB::table('purchase_request_history')->where('company_id',$this->company())
            ->where('purchase_request_id',$id)->orderBy('revision')->paginate(20);
    }

    public function requestLines(Request $request, int $id)
    {
        $this->authorizePurchasing();
        $this->row('purchase_requests',$id);
        return DB::table('purchase_request_lines')->where('company_id',$this->company())
            ->where('purchase_request_id',$id)->where('active',true)->orderBy('id')->paginate(100);
    }

    public function replaceRequestLines(Request $request, int $id)
    {
        $this->authorizePurchasing();
        $data=$request->validate(['lines'=>'required|array|min:1|max:100',
            'lines.*.description'=>'required|string|max:255',
            'lines.*.quantity'=>'required|integer|min:1|max:1000000',
            'lines.*.unit_price_cents'=>'required|integer|min:0|max:100000000',
            'lines.*.vat_bps'=>'sometimes|integer|min:0|max:10000',
            'cost_center'=>'nullable|string|max:80','needed_on'=>'nullable|date']);
        return DB::transaction(function () use ($request,$data,$id) {
            $purchase=$this->row('purchase_requests',$id,true);
            abort_unless(in_array($purchase->status,['draft','submitted','rejected','approved'],true),409,'Demande non révisable.');
            abort_if(DB::table('purchase_orders')->where('company_id',$this->company())
                ->where('purchase_request_id',$id)->exists(),409,'Commande existante : avenant requis.');
            abort_unless((int)$purchase->requested_by===(int)$request->user()->id,403,'Seul le demandeur peut réviser.');
            $total=$this->orderTotal($data['lines']);
            DB::table('purchase_request_lines')->where('company_id',$this->company())->where('purchase_request_id',$id)->update(['active'=>false,'updated_at'=>now()]);
            foreach ($data['lines'] as $line) DB::table('purchase_request_lines')->insert([
                'company_id'=>$this->company(),'purchase_request_id'=>$id,'description'=>$line['description'],
                'quantity'=>$line['quantity'],'active'=>true,'unit_price_cents'=>$line['unit_price_cents'],
                'vat_bps'=>$line['vat_bps']??0,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $revision=(int)$purchase->revision+1;
            DB::table('purchase_request_history')->insert([
                'company_id'=>$this->company(),'purchase_request_id'=>$id,'revision'=>$purchase->revision,
                'snapshot'=>json_encode($purchase,JSON_THROW_ON_ERROR),'changed_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('purchase_requests')->where('id',$id)->where('company_id',$this->company())->update([
                'estimated_total_cents'=>$total,'cost_center'=>$data['cost_center']??$purchase->cost_center,
                'needed_on'=>$data['needed_on']??$purchase->needed_on,
                'revision'=>$revision,'status'=>'draft','submitted_at'=>null,
                'approved_by'=>null,'approved_at'=>null,'decided_by'=>null,'decided_at'=>null,
                'decision_reason'=>null,'updated_at'=>now(),
            ]);
            $this->log('request.lines.revised',$id,['revision'=>$revision,'total_cents'=>$total]);
            return response()->json(['id'=>$id,'revision'=>$revision,'estimated_total_cents'=>$total,'status'=>'draft']);
        },3);
    }

    public function quotes(Request $request)
    {
        $this->authorizePurchasing();
        $request->validate(['page'=>'sometimes|integer|min:1']);
        return DB::table('supplier_quotes')->where('company_id',$this->company())->orderByDesc('id')->paginate(20);
    }

    public function addQuote(Request $request)
    {
        $this->authorizePurchasing();
        $data=$request->validate([
            'purchase_request_id'=>'required|integer|min:1','supplier_id'=>'required|integer|min:1',
            'reference'=>'required|string|max:80','currency'=>['required',Rule::in(['EUR','USD','GBP'])],
            'subtotal_cents'=>'required|integer|min:0|max:100000000000',
            'tax_cents'=>'required|integer|min:0|max:100000000000',
            'shipping_cents'=>'sometimes|integer|min:0|max:100000000000',
            'delivery_days'=>'required|integer|min:0|max:3650','valid_until'=>'required|date',
        ]);
        return DB::transaction(function () use ($data) {
            $purchase=$this->row('purchase_requests',$data['purchase_request_id'],true);
            abort_unless($purchase->status==='approved',409,'Demande non approuvée.');
            $this->ensureSupplier($data['supplier_id']);
            abort_unless($purchase->currency===$data['currency'],422,'Conversion de devise non paramétrée.');
            $total=$data['subtotal_cents']+$data['tax_cents']+($data['shipping_cents']??0);
            abort_if($total>100000000000,422,'Montant total excessif.');
            $id=DB::table('supplier_quotes')->insertGetId([
                'company_id'=>$this->company(),'purchase_request_id'=>$purchase->id,
                'supplier_id'=>$data['supplier_id'],'reference'=>$data['reference'],
                'currency'=>$data['currency'],'subtotal_cents'=>$data['subtotal_cents'],
                'tax_cents'=>$data['tax_cents'],'shipping_cents'=>$data['shipping_cents']??0,
                'total_cents'=>$total,'delivery_days'=>$data['delivery_days'],
                'valid_until'=>$data['valid_until'],'status'=>'received',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log('quote.received',$id);
            return response()->json($this->row('supplier_quotes',$id),201);
        },3);
    }

    public function awardQuote(Request $request,int $id)
    {
        $this->authorizePurchasing();
        $data=$request->validate(['reason'=>'required|string|min:5|max:2000']);
        return DB::transaction(function () use ($request,$id,$data) {
            $quote=$this->row('supplier_quotes',$id,true);
            $purchase=$this->row('purchase_requests',$quote->purchase_request_id,true);
            abort_unless($purchase->status==='approved',409,'Demande non approuvée.');
            abort_unless($quote->status==='received',409,'Offre déjà arbitrée.');
            abort_if($quote->valid_until < now()->toDateString(),409,'Offre expirée.');
            $this->ensureSupplier($quote->supplier_id);
            abort_if(DB::table('supplier_quotes')->where('company_id',$this->company())
                ->where('purchase_request_id',$purchase->id)->where('status','awarded')->exists(),
                409,'Offre déjà attribuée.');
            abort_if((int)$purchase->requested_by===(int)$request->user()->id,403,'Arbitrage indépendant obligatoire.');
            DB::table('supplier_quotes')->where('id',$id)->where('company_id',$this->company())->update([
                'status'=>'awarded','award_reason'=>$data['reason'],'awarded_by'=>$request->user()->id,
                'awarded_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log('quote.awarded',$id,['reason'=>$data['reason']]);
            return response()->json(['id'=>$id,'status'=>'awarded']);
        },3);
    }

    public function orders(Request $request)
    {
        $this->authorizePurchasing();
        $request->validate(['page'=>'sometimes|integer|min:1']);
        return DB::table('purchase_orders')->where('company_id',$this->company())->orderByDesc('id')->paginate(20);
    }

    public function orderLines(int $id)
    {
        $this->authorizePurchasing();
        $this->row('purchase_orders',$id);
        return DB::table('purchase_order_lines')->where('company_id',$this->company())
            ->where('purchase_order_id',$id)->orderBy('id')->paginate(100);
    }

    public function createOrder(Request $request)
    {
        $this->authorizePurchasing();
        $data=$request->validate([
            'reference'=>'required|string|max:80','supplier_quote_id'=>'required|integer|min:1',
            'lines'=>'required|array|min:1|max:100','lines.*.product_id'=>'nullable|integer|min:1',
            'lines.*.description'=>'required|string|max:255',
            'lines.*.quantity'=>'required|integer|min:1|max:1000000',
            'lines.*.unit_price_cents'=>'required|integer|min:0|max:100000000',
            'lines.*.vat_bps'=>'sometimes|integer|min:0|max:10000',
        ]);
        return DB::transaction(function () use ($request,$data) {
            $quote=$this->row('supplier_quotes',$data['supplier_quote_id'],true);
            $purchase=$this->row('purchase_requests',$quote->purchase_request_id,true);
            abort_unless($purchase->status==='approved' && $quote->status==='awarded',409,'Offre non attribuée ou demande non approuvée.');
            abort_if($quote->valid_until<now()->toDateString(),409,'Offre expirée.');
            $this->ensureSupplier($quote->supplier_id);
            abort_if(DB::table('purchase_orders')->where('company_id',$this->company())
                ->where('purchase_request_id',$purchase->id)->exists(),409,'Commande déjà créée pour cette demande.');
            foreach ($data['lines'] as $line) if (!empty($line['product_id'])) $this->row('products',$line['product_id']);
            $total=$this->orderTotal($data['lines']);
            abort_unless($total===(int)$quote->total_cents,422,'Lignes et offre attribuée ne concordent pas.');
            $id=DB::table('purchase_orders')->insertGetId([
                'company_id'=>$this->company(),'reference'=>$data['reference'],
                'purchase_request_id'=>$purchase->id,'supplier_quote_id'=>$quote->id,
                'supplier_id'=>$quote->supplier_id,'request_revision'=>$purchase->revision,
                'currency'=>$quote->currency,'total_cents'=>$total,'status'=>'draft',
                'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach ($data['lines'] as $line) DB::table('purchase_order_lines')->insert([
                'company_id'=>$this->company(),'purchase_order_id'=>$id,
                'product_id'=>$line['product_id']??null,'description'=>$line['description'],
                'quantity'=>$line['quantity'],'received_quantity'=>0,
                'unit_price_cents'=>$line['unit_price_cents'],'vat_bps'=>$line['vat_bps']??0,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log('order.created',$id);
            return response()->json($this->row('purchase_orders',$id),201);
        },3);
    }

    public function orderTransition(Request $request,int $id)
    {
        $this->authorizePurchasing();
        $data=$request->validate([
            'action'=>['required',Rule::in(['send','confirm','cancel_remainder'])],
            'reason'=>'required_if:action,cancel_remainder|nullable|string|min:5|max:2000'
        ]);
        return DB::transaction(function () use ($id,$data) {
            $order=$this->row('purchase_orders',$id,true);
            $statuses=['send'=>['draft','sent'],'confirm'=>['sent','confirmed'],
                'cancel_remainder'=>['sent','confirmed','cancelled']];
            [$from,$to]=$statuses[$data['action']];
            abort_unless($order->status===$from,409,'Transition interdite.');
            if ($data['action']==='send') $this->ensureSupplier($order->supplier_id);
            DB::table('purchase_orders')->where('company_id',$this->company())->where('id',$id)->update([
                'status'=>$to,'sent_at'=>$data['action']==='send'?now():$order->sent_at,
                'cancelled_at'=>$data['action']==='cancel_remainder'?now():null,
                'cancellation_reason'=>$data['reason']??null,'updated_at'=>now(),
            ]);
            $this->log('order.'.$data['action'],$id,['reason'=>$data['reason']??null]);
            return response()->json(['id'=>$id,'status'=>$to]);
        },3);
    }

    public function receipts(Request $request)
    {
        $this->authorizePurchasing();
        $request->validate(['page'=>'sometimes|integer|min:1']);
        return DB::table('purchase_receipts')->where('company_id',$this->company())->orderByDesc('id')->paginate(20);
    }

    public function receiptLines(int $id)
    {
        $this->authorizePurchasing();
        $this->row('purchase_receipts',$id);
        return DB::table('purchase_receipt_lines')->where('company_id',$this->company())
            ->where('purchase_receipt_id',$id)->orderBy('id')->paginate(100);
    }

    public function receive(Request $request)
    {
        $this->authorizePurchasing();
        $data=$request->validate([
            'purchase_order_id'=>'required|integer|min:1','reference'=>'required|string|max:80',
            'lines'=>'required|array|min:1|max:100',
            'lines.*.purchase_order_line_id'=>'required|integer|min:1|distinct',
            'lines.*.received_quantity'=>'required|integer|min:1|max:1000000',
            'lines.*.accepted_quantity'=>'required|integer|min:0|max:1000000',
            'lines.*.rejected_quantity'=>'required|integer|min:0|max:1000000',
        ]);
        $hash=$this->payloadHash($data);
        return DB::transaction(function () use ($request,$data,$hash) {
            $order=$this->row('purchase_orders',$data['purchase_order_id'],true);
            $prior=DB::table('purchase_receipts')->where('company_id',$this->company())
                ->where('reference',$data['reference'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->payload_hash,$hash),409,'Référence déjà utilisée avec un autre contenu.');
                abort_unless((int)$prior->purchase_order_id===(int)$order->id,409,'Référence utilisée sur une autre commande.');
                return response()->json($prior,200);
            }
            abort_unless(in_array($order->status,['sent','confirmed'],true),409,'Commande non réceptionnable.');
            foreach ($data['lines'] as $line) {
                abort_unless($line['received_quantity']===$line['accepted_quantity']+$line['rejected_quantity'],
                    422,'Quantités reçues, acceptées et rejetées incohérentes.');
                $parent=$this->row('purchase_order_lines',$line['purchase_order_line_id'],true);
                abort_unless((int)$parent->purchase_order_id===(int)$order->id,422,'Ligne étrangère à la commande.');
                abort_if($parent->received_quantity+$line['received_quantity']>$parent->quantity,409,'Dépassement de quantité commandée.');
            }
            $id=DB::table('purchase_receipts')->insertGetId([
                'company_id'=>$this->company(),'purchase_order_id'=>$order->id,
                'reference'=>$data['reference'],'payload_hash'=>$hash,
                'received_by'=>$request->user()->id,'received_at'=>now(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            foreach ($data['lines'] as $line) {
                DB::table('purchase_receipt_lines')->insert([
                    'company_id'=>$this->company(),'purchase_receipt_id'=>$id,
                    'purchase_order_line_id'=>$line['purchase_order_line_id'],
                    'received_quantity'=>$line['received_quantity'],'accepted_quantity'=>$line['accepted_quantity'],
                    'rejected_quantity'=>$line['rejected_quantity'],'created_at'=>now(),'updated_at'=>now(),
                ]);
                DB::table('purchase_order_lines')->where('company_id',$this->company())
                    ->where('id',$line['purchase_order_line_id'])
                    ->increment('received_quantity',$line['received_quantity'],['updated_at'=>now()]);
                $parent=$this->row('purchase_order_lines',$line['purchase_order_line_id']);
                if ($parent->product_id && $line['accepted_quantity']>0) {
                    $product=\App\Models\Product::query()->lockForUpdate()->findOrFail($parent->product_id);
                    app(\App\Services\StockService::class)->move($product,$line['accepted_quantity'],'purchase_receipt:'.$id);
                }
            }
            $this->log('receipt.created',$id,['order_id'=>$order->id]);
            return response()->json($this->row('purchase_receipts',$id),201);
        },3);
    }

    public function invoices(Request $request)
    {
        $this->authorizePurchasing();
        $request->validate(['page'=>'sometimes|integer|min:1']);
        return DB::table('supplier_invoices')->where('company_id',$this->company())->orderByDesc('id')->paginate(20);
    }

    public function createInvoice(Request $request)
    {
        $this->authorizePurchasing();
        $data=$request->validate([
            'purchase_order_id'=>'required|integer|min:1',
            'reference'=>'required|string|min:1|max:120',
            'total_cents'=>'required|integer|min:1|max:100000000000',
        ]);
        return DB::transaction(function () use ($request,$data) {
            $order=$this->row('purchase_orders',$data['purchase_order_id'],true);
            abort_unless(in_array($order->status,['sent','confirmed','cancelled'],true),409,'Commande non envoyée.');
            $reference=strtoupper(preg_replace('/\s+/u','',trim($data['reference'])));
            abort_unless(strlen($reference)>0,422,'Référence invalide.');
            abort_if(DB::table('supplier_invoices')->where('company_id',$this->company())
                ->where('supplier_id',$order->supplier_id)->where('normalized_reference',$reference)->exists(),
                409,'Facture fournisseur déjà enregistrée.');
            $lineRows=DB::table('purchase_order_lines')->where('company_id',$this->company())
                ->where('purchase_order_id',$order->id)->get();
            $accepted=DB::table('purchase_receipt_lines as l')
                ->join('purchase_receipts as r','r.id','=','l.purchase_receipt_id')
                ->where('r.company_id',$this->company())->where('l.company_id',$this->company())
                ->where('r.purchase_order_id',$order->id)
                ->select('l.purchase_order_line_id',DB::raw('SUM(l.accepted_quantity) AS qty'))
                ->groupBy('l.purchase_order_line_id')->get()->keyBy('purchase_order_line_id');
            $receivedTotal=0;
            foreach ($lineRows as $line) {
                $qty=(int)($accepted->get($line->id)?->qty??0);
                $net=$qty*(int)$line->unit_price_cents;
                $receivedTotal += $net + intdiv($net*(int)$line->vat_bps+5000,10000);
            }
            $alreadyMatched=(int)DB::table('supplier_invoices')
                ->where('company_id',$this->company())->where('purchase_order_id',$order->id)
                ->sum('matched_cents');
            $remaining=max(0,$receivedTotal-$alreadyMatched);
            $matched=min((int)$data['total_cents'],$remaining);
            $dispute=(int)$data['total_cents']-$matched;
            $status=$dispute===0?'matched':'disputed';
            $id=DB::table('supplier_invoices')->insertGetId([
                'company_id'=>$this->company(),'purchase_order_id'=>$order->id,
                'supplier_id'=>$order->supplier_id,'reference'=>trim($data['reference']),
                'normalized_reference'=>$reference,'currency'=>$order->currency,
                'total_cents'=>$data['total_cents'],'matched_cents'=>$matched,
                'dispute_cents'=>$dispute,'status'=>$status,'created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log('invoice.recorded',$id,['matched_cents'=>$matched,'dispute_cents'=>$dispute]);
            return response()->json($this->row('supplier_invoices',$id),201);
        },3);
    }

    public function invoiceCredits(int $id)
    {
        $this->authorizePurchasing();
        $this->row('supplier_invoices',$id);
        return DB::table('supplier_credit_notes')->where('company_id',$this->company())
            ->where('supplier_invoice_id',$id)->orderByDesc('id')->paginate(20);
    }

    public function credit(Request $request,int $id)
    {
        $this->authorizePurchasing();
        $data=$request->validate(['reference'=>'required|string|max:120',
            'amount_cents'=>'required|integer|min:1|max:100000000000']);
        return DB::transaction(function () use ($id,$data,$request) {
            $invoice=$this->row('supplier_invoices',$id,true);
            $credits=(int)DB::table('supplier_credit_notes')->where('company_id',$this->company())
                ->where('supplier_invoice_id',$id)->sum('amount_cents');
            abort_if($credits+$data['amount_cents']>$invoice->total_cents,409,'Avoir supérieur au montant restant.');
            $creditId=DB::table('supplier_credit_notes')->insertGetId([
                'company_id'=>$this->company(),'supplier_invoice_id'=>$id,
                'reference'=>$data['reference'],'amount_cents'=>$data['amount_cents'],
                'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->log('invoice.credit',$creditId,['invoice_id'=>$id,'amount_cents'=>$data['amount_cents']]);
            return response()->json(['id'=>$creditId,'supplier_invoice_id'=>$id],201);
        },3);
    }
}
