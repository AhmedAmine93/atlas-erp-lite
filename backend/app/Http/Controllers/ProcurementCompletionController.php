<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * M04 complementary controls: comparison, new award decisions, independently
 * approved order amendments, and signed service-performance receipts.
 */
final class ProcurementCompletionController extends Controller
{
    private function tenant(): int { return app(TenantContext::class)->requireId(); }
    private function access(): void { Gate::authorize('admin'); }

    private function row(string $table,int $id,bool $lock=false): object
    {
        $q=DB::table($table)->where('company_id',$this->tenant())->where('id',$id);
        return ($lock?$q->lockForUpdate():$q)->firstOrFail();
    }
    private function audit(string $action,int $id,array $details=[]): void
    {
        Audit::record('purchase.'.$action,null,['record_id'=>$id]+$details);
    }

    public function compare(int $requestId)
    {
        $this->access();
        $request=$this->row('purchase_requests',$requestId);
        $rows=DB::table('supplier_quotes as q')->join('suppliers as s',function($join){
            $join->on('s.id','=','q.supplier_id')->on('s.company_id','=','q.company_id');
        })->where('q.company_id',$this->tenant())->where('q.purchase_request_id',$requestId)
          ->select('q.id','q.reference','q.supplier_id','s.name as supplier_name','q.subtotal_cents',
            'q.tax_cents','q.shipping_cents','q.total_cents','q.delivery_days',
            'q.valid_until','q.currency','q.status','q.award_reason')
          ->orderBy('q.total_cents')->orderBy('q.delivery_days')->get();
        // Only same-currency offers are compared: no undocumented FX assumptions.
        return response()->json([
            'purchase_request_id'=>$requestId,'currency'=>$request->currency,
            'comparison_basis'=>'all taxes and shipping included; same currency only',
            'offers'=>$rows->map(function($offer) use ($request) {
                $offer->comparable=$offer->currency===$request->currency;
                $offer->valid=$offer->valid_until>=now()->toDateString();
                return $offer;
            }),
        ]);
    }

    public function awardHistory(int $requestId)
    {
        $this->access();
        $this->row('purchase_requests',$requestId);
        return DB::table('procurement_award_events')->where('company_id',$this->tenant())
            ->where('purchase_request_id',$requestId)->orderBy('id')->paginate(50);
    }

    public function reaward(Request $request,int $quoteId)
    {
        $this->access();
        $data=$request->validate(['reason'=>'required|string|min:10|max:2000']);
        return DB::transaction(function() use($request,$quoteId,$data){
            $candidate=$this->row('supplier_quotes',$quoteId,true);
            $parent=$this->row('purchase_requests',$candidate->purchase_request_id,true);
            abort_unless($parent->status==='approved',409,'Demande non approuvée.');
            abort_if((int)$parent->requested_by===(int)$request->user()->id,403,'Arbitrage indépendant obligatoire.');
            abort_unless($candidate->status==='received',409,'Seule une offre reçue peut être retenue.');
            abort_if($candidate->valid_until<now()->toDateString(),409,'Offre expirée.');
            abort_unless($candidate->currency===$parent->currency,422,'Comparaison de devises non configurée.');
            $supplier=$this->row('suppliers',$candidate->supplier_id,true);
            abort_unless($supplier->procurement_status==='active',409,'Fournisseur bloqué.');
            abort_if(DB::table('purchase_orders')->where('company_id',$this->tenant())
                ->where('purchase_request_id',$parent->id)->exists(),409,'Commande déjà engagée : arbitrage verrouillé.');

            $current=DB::table('supplier_quotes')->where('company_id',$this->tenant())
                ->where('purchase_request_id',$parent->id)->where('status','awarded')
                ->lockForUpdate()->first();
            if($current) {
                DB::table('supplier_quotes')->where('company_id',$this->tenant())
                    ->where('id',$current->id)->update(['status'=>'superseded','updated_at'=>now()]);
            }
            DB::table('supplier_quotes')->where('company_id',$this->tenant())
                ->where('id',$quoteId)->update([
                    'status'=>'awarded','award_reason'=>$data['reason'],
                    'awarded_by'=>$request->user()->id,'awarded_at'=>now(),'updated_at'=>now(),
                ]);
            $id=DB::table('procurement_award_events')->insertGetId([
                'company_id'=>$this->tenant(),'purchase_request_id'=>$parent->id,
                'previous_quote_id'=>$current?->id,'new_quote_id'=>$quoteId,
                'decided_by'=>$request->user()->id,'reason'=>$data['reason'],
                'decided_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->audit('quote.reawarded',$id,['new_quote_id'=>$quoteId,'old_quote_id'=>$current?->id]);
            return response()->json(['id'=>$quoteId,'status'=>'awarded','award_event_id'=>$id]);
        },3);
    }

    public function amendments(int $orderId)
    {
        $this->access();
        $this->row('purchase_orders',$orderId);
        return DB::table('purchase_order_amendments')->where('company_id',$this->tenant())
            ->where('purchase_order_id',$orderId)->orderByDesc('id')->paginate(50);
    }

    public function requestAmendment(Request $request,int $orderId)
    {
        $this->access();
        $data=$request->validate([
            'purchase_order_line_id'=>'required|integer|min:1',
            'new_quantity'=>'required|integer|min:1|max:1000000',
            'reason'=>'required|string|min:10|max:2000',
        ]);
        return DB::transaction(function() use($request,$orderId,$data){
            $order=$this->row('purchase_orders',$orderId,true);
            abort_unless(in_array($order->status,['sent','confirmed'],true),409,'Commande non émise ou clôturée.');
            abort_if(DB::table('supplier_invoices')->where('company_id',$this->tenant())
                ->where('purchase_order_id',$orderId)->exists(),409,'Facture présente : utilisez un avoir.');
            $line=$this->row('purchase_order_lines',$data['purchase_order_line_id'],true);
            abort_unless((int)$line->purchase_order_id===$orderId,422,'Ligne hors commande.');
            abort_unless($data['new_quantity']>=(int)$line->received_quantity,409,'Impossible de réduire sous la quantité réceptionnée.');
            abort_unless($data['new_quantity']<=(int)$line->quantity,422,'Augmentation du montant : nouvelle demande approuvée requise.');
            abort_if($data['new_quantity']===(int)$line->quantity,422,'Quantité inchangée.');
            abort_if(DB::table('purchase_order_amendments')->where('company_id',$this->tenant())
                ->where('purchase_order_line_id',$line->id)->where('status','pending')->exists(),
                409,'Avenant déjà en attente.');
            $beforeNet=(int)$line->quantity*(int)$line->unit_price_cents;
            $afterNet=(int)$data['new_quantity']*(int)$line->unit_price_cents;
            $beforeGross=$beforeNet+intdiv($beforeNet*(int)$line->vat_bps+5000,10000);
            $afterGross=$afterNet+intdiv($afterNet*(int)$line->vat_bps+5000,10000);
            $after=(int)$order->total_cents-$beforeGross+$afterGross;
            abort_if($after<0,409,'Montant incohérent.');
            $id=DB::table('purchase_order_amendments')->insertGetId([
                'company_id'=>$this->tenant(),'purchase_order_id'=>$orderId,
                'purchase_order_line_id'=>$line->id,'new_quantity'=>$data['new_quantity'],
                'total_before_cents'=>$order->total_cents,'total_after_cents'=>$after,
                'status'=>'pending','reason'=>$data['reason'],
                'requested_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->audit('order.amendment.requested',$id,['order_id'=>$orderId]);
            return response()->json($this->row('purchase_order_amendments',$id),201);
        },3);
    }

    public function decideAmendment(Request $request,int $id)
    {
        $this->access();
        $data=$request->validate(['decision'=>'required|in:approved,rejected']);
        return DB::transaction(function() use($request,$id,$data){
            $amendment=$this->row('purchase_order_amendments',$id,true);
            abort_unless($amendment->status==='pending',409,'Avenant déjà arbitré.');
            abort_if((int)$amendment->requested_by===(int)$request->user()->id,403,'Approbateur distinct obligatoire.');
            $order=$this->row('purchase_orders',$amendment->purchase_order_id,true);
            $line=$this->row('purchase_order_lines',$amendment->purchase_order_line_id,true);
            abort_unless(in_array($order->status,['sent','confirmed'],true),409,'Commande non modifiable par avenant.');
            abort_if(DB::table('supplier_invoices')->where('company_id',$this->tenant())
                ->where('purchase_order_id',$order->id)->exists(),409,'Facture présente.');
            if($data['decision']==='approved') {
                abort_unless((int)$order->total_cents===(int)$amendment->total_before_cents,409,'Commande modifiée depuis la demande.');
                abort_unless((int)$line->quantity>=(int)$amendment->new_quantity
                    && (int)$line->received_quantity<=(int)$amendment->new_quantity,409,'Reliquat modifié.');
                DB::table('purchase_order_lines')->where('company_id',$this->tenant())
                    ->where('id',$line->id)->update(['quantity'=>$amendment->new_quantity,'updated_at'=>now()]);
                DB::table('purchase_orders')->where('company_id',$this->tenant())
                    ->where('id',$order->id)->update(['total_cents'=>$amendment->total_after_cents,'updated_at'=>now()]);
                $release=(int)$amendment->total_before_cents-(int)$amendment->total_after_cents;
                if ($release>0) DB::table('purchase_commitment_events')->insert([
                    'company_id'=>$this->tenant(),'purchase_order_id'=>$order->id,
                    'event_key'=>'amendment:'.$amendment->id,'kind'=>'release',
                    'amount_cents'=>$release,'recorded_by'=>$request->user()->id,
                    'recorded_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            DB::table('purchase_order_amendments')->where('company_id',$this->tenant())
                ->where('id',$id)->update(['status'=>$data['decision'],
                    'decided_by'=>$request->user()->id,'decided_at'=>now(),'updated_at'=>now()]);
            $this->audit('order.amendment.decided',$id,['decision'=>$data['decision']]);
            return response()->json(['id'=>$id,'status'=>$data['decision']]);
        },3);
    }

    public function services(int $orderId)
    {
        $this->access();
        $this->row('purchase_orders',$orderId);
        return DB::table('purchase_service_acceptances')->where('company_id',$this->tenant())
            ->where('purchase_order_id',$orderId)->orderByDesc('id')->paginate(100);
    }

    public function signService(Request $request,int $orderId)
    {
        $this->access();
        $data=$request->validate([
            'purchase_order_line_id'=>'required|integer|min:1',
            'reference'=>'required|string|max:80',
            'accepted_quantity'=>'required|integer|min:1|max:1000000',
            'evidence_reference'=>'required|string|min:5|max:160',
        ]);
        return DB::transaction(function() use($request,$orderId,$data){
            $order=$this->row('purchase_orders',$orderId,true);
            $prior=DB::table('purchase_service_acceptances')->where('company_id',$this->tenant())
                ->where('reference',$data['reference'])->first();
            if($prior){
                abort_unless((int)$prior->purchase_order_id===$orderId
                    && (int)$prior->purchase_order_line_id===(int)$data['purchase_order_line_id']
                    && (int)$prior->accepted_quantity===(int)$data['accepted_quantity']
                    && $prior->evidence_reference===$data['evidence_reference'],409,'Référence rejouée avec contenu différent.');
                return response()->json($prior);
            }
            abort_unless(in_array($order->status,['sent','confirmed'],true),409,'Commande non réceptionnable.');
            abort_if((int)$order->created_by===(int)$request->user()->id,403,'Signature indépendante du créateur de la commande.');
            $line=$this->row('purchase_order_lines',$data['purchase_order_line_id'],true);
            abort_unless((int)$line->purchase_order_id===$orderId,422,'Ligne hors commande.');
            abort_unless($line->line_type==='service' && $line->product_id===null,422,'Le service fait est réservé aux lignes de prestation.');
            abort_if((int)$line->received_quantity+$data['accepted_quantity']>(int)$line->quantity,
                409,'Service fait supérieur à la commande.');
            $id=DB::table('purchase_service_acceptances')->insertGetId([
                'company_id'=>$this->tenant(),'purchase_order_id'=>$orderId,
                'purchase_order_line_id'=>$line->id,'reference'=>$data['reference'],
                'accepted_quantity'=>$data['accepted_quantity'],'evidence_reference'=>$data['evidence_reference'],
                'signed_by'=>$request->user()->id,'signed_at'=>now(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('purchase_order_lines')->where('company_id',$this->tenant())
                ->where('id',$line->id)->increment('received_quantity',$data['accepted_quantity'],['updated_at'=>now()]);
            $this->audit('service.accepted',$id,['order_id'=>$orderId,'evidence_reference'=>$data['evidence_reference']]);
            return response()->json($this->row('purchase_service_acceptances',$id),201);
        },3);
    }
}
