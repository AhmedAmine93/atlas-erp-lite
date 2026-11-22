<?php

namespace App\Http\Controllers;

use App\Models\{Customer,Invoice,Order,Product,Quote};
use App\Services\{Audit,DocumentNumber,Money,StockService,TenantContext};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Gate};
use Illuminate\Validation\ValidationException;

class SalesCommerceController extends Controller
{
    private const LISTS = [
        'revisions'=>'sales_quote_revisions',
        'approvals'=>'sales_discount_approvals',
        'reservations'=>'sales_reservations',
        'deliveries'=>'sales_deliveries',
        'contracts'=>'sales_contracts',
        'returns'=>'sales_returns',
        'credits'=>'sales_credit_notes',
        'pricing'=>'sales_price_rules',
    ];

    private function company(): int { return app(TenantContext::class)->requireId(); }
    private function rows(string $table) { return DB::table($table)->where('company_id', $this->company()); }
    private function one(string $table, int $id) { return $this->rows($table)->where('id',$id)->first() ?? abort(404); }
    private function lock(string $table, int $id) { return $this->rows($table)->where('id',$id)->lockForUpdate()->first() ?? abort(404); }
    private function error(string $message): never { abort(409, $message); }
    private function inserted(string $table, array $data): object
    {
        $id = DB::table($table)->insertGetId(array_merge($data, ['company_id'=>$this->company(),'created_at'=>now(),'updated_at'=>now()]));
        return $this->one($table,$id);
    }
    private function updateRow(string $table,int $id,array $data): object
    {
        $this->rows($table)->where('id',$id)->update(array_merge($data,['updated_at'=>now()]));
        return $this->one($table,$id);
    }
    private function audit(string $action, string $table, int $id, array $meta=[]): void
    {
        Audit::record('sales.'.$action,null,array_merge(['table'=>$table,'id'=>$id,'company_id'=>$this->company()],$meta));
    }
    private function authorized(string $ability): void { Gate::authorize($ability); }

    /**
     * Serializes same-key requests before checking the digest and inserting.
     * No global business operation can escape this transaction.
     */
    private function idempotent(Request $r,string $operation,callable $callback)
    {
        $key = $r->header('Idempotency-Key');
        if (!$key || !preg_match('/^[A-Za-z0-9_-]{8,100}$/D',$key)) {
            throw ValidationException::withMessages(['idempotency_key'=>'Clé Idempotency-Key (8 à 100 caractères) obligatoire.']);
        }
        $digest = hash('sha256',json_encode($r->all(),JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($r,$key,$operation,$digest,$callback) {
            $company=$this->company();
            if (DB::getDriverName()==='pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['m03:'.$company.':'.$operation.':'.$key]);
            }
            $prior=$this->rows('sales_idempotency_records')->where('operation',$operation)->where('request_key',$key)->lockForUpdate()->first();
            if ($prior) {
                if (!hash_equals($prior->payload_hash,$digest)) $this->error('Clé de requête réutilisée avec un contenu différent.');
                return response()->json(json_decode($prior->result,true),200);
            }
            $result=$callback();
            $response=is_array($result)?$result:(array)$result;
            $this->inserted('sales_idempotency_records',['operation'=>$operation,'request_key'=>$key,
                'payload_hash'=>$digest,'result'=>json_encode($response,JSON_THROW_ON_ERROR)]);
            return response()->json($response,201);
        },3);
    }

    public function index(Request $request,string $kind)
    {
        $this->authorized('salesRead');
        if (!array_key_exists($kind,self::LISTS)) abort(404);
        $data=$request->validate(['status'=>'nullable|string|max:30','search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1']);
        $table=self::LISTS[$kind]; $query=$this->rows($table);
        if (!empty($data['status']) && in_array($kind,['revisions','approvals','deliveries','contracts','returns','pricing'],true)) $query->where('status',$data['status']);
        if (!empty($data['search']) && in_array($kind,['deliveries','contracts','returns'],true)) $query->where('number','like','%'.$data['search'].'%');
        return $query->orderByDesc('id')->paginate(20);
    }

    public function detail(string $kind,int $id)
    {
        $this->authorized('salesRead');
        $table=self::LISTS[$kind]??abort(404); $record=$this->one($table,$id);
        if ($kind==='deliveries') $record->lines=$this->rows('sales_delivery_lines')->where('delivery_id',$id)->get();
        if ($kind==='returns') $record->lines=$this->rows('sales_return_lines')->where('return_id',$id)->get();
        if ($kind==='contracts') $record->versions=$this->rows('sales_contract_versions')->where('contract_id',$id)->orderBy('version_no')->get();
        return response()->json($record);
    }

    public function reviseQuote(Request $r,int $quote)
    {
        $this->authorized('sales');
        $v=$r->validate(['discount_bps'=>'required|integer|min:0|max:10000','valid_until'=>'required|date|after_or_equal:today']);
        return $this->idempotent($r,'quote-revise-'.$quote,function () use ($r,$quote,$v) {
            $q=Quote::lockForUpdate()->findOrFail($quote);
            if (!in_array($q->status,['draft','sent','accepted','expired'],true)) $this->error('Devis non révisable.');
            $number=(int)$q->revision_no+1;
            $lines=[];$s=0;$tax=0;
            foreach ($q->items as $item) {
                $unit=intdiv($item->price_cents*(10000-$v['discount_bps'])+5000,10000);
                $amount=Money::line($unit,$item->quantity,$item->vat_bps);
                $lines[]=array_merge($item->only(['product_id','reference','name','quantity','vat_bps']),['price_cents'=>$unit],$amount);
                $s+=$amount['subtotal_cents'];$tax+=$amount['tax_cents'];
            }
            $revision=$this->inserted('sales_quote_revisions',[
                'quote_id'=>$q->id,'revision_no'=>$number,'status'=>'draft',
                'discount_bps'=>$v['discount_bps'],'valid_until'=>$v['valid_until'],
                'snapshot'=>json_encode(['items'=>$lines,'customer_id'=>$q->customer_id],JSON_THROW_ON_ERROR),
                'subtotal_cents'=>$s,'tax_cents'=>$tax,'total_cents'=>$s+$tax,'created_by'=>$r->user()->id
            ]);
            $q->update(['revision_no'=>$number,'document_version'=>((int)$q->document_version)+1,'status'=>'draft']);
            if ($v['discount_bps']>0) {
                $this->inserted('sales_discount_approvals',['revision_id'=>$revision->id,'discount_bps'=>$v['discount_bps'],
                    'requested_by'=>$r->user()->id,'status'=>'pending']);
            }
            $this->audit('quote.revised','quotes',$q->id,['revision_id'=>$revision->id]);
            return $revision;
        });
    }

    public function approveDiscount(Request $r,int $id)
    {
        $this->authorized('admin');
        $v=$r->validate(['decision'=>'required|in:approved,rejected','reason'=>'required|string|min:5|max:1000']);
        return $this->idempotent($r,'discount-decision-'.$id,function () use ($r,$id,$v) {
            $approval=$this->lock('sales_discount_approvals',$id);
            if ($approval->status!=='pending') $this->error('Décision déjà prise.');
            if ((int)$approval->requested_by===(int)$r->user()->id) abort(403,'Approbation par une autre personne requise.');
            $result=$this->updateRow('sales_discount_approvals',$id,[
                'status'=>$v['decision'],'reason'=>$v['reason'],'decided_by'=>$r->user()->id,'decided_at'=>now()]);
            $this->audit('discount.decided','sales_discount_approvals',$id);
            return $result;
        });
    }

    public function quoteTransition(Request $r,int $revision,string $action)
    {
        $this->authorized('sales');
        if (!in_array($action,['send','accept'],true)) abort(404);
        return $this->idempotent($r,'quote-'.$action.'-'.$revision,function () use ($revision,$action) {
            $rev=$this->lock('sales_quote_revisions',$revision);
            $quote=Quote::lockForUpdate()->findOrFail($rev->quote_id);
            if ((int)$quote->revision_no!==(int)$rev->revision_no) $this->error('Une révision plus récente existe.');
            if ($rev->valid_until<now()->toDateString()) $this->error('Devis expiré : renouveler la validité.');
            $from=$action==='send'?'draft':'sent';$to=$action==='send'?'sent':'accepted';
            if ($rev->status!==$from) $this->error('Transition interdite.');
            if ($action==='send' && $rev->discount_bps>0) {
                $approval=$this->rows('sales_discount_approvals')->where('revision_id',$revision)->first();
                if (!$approval || $approval->status!=='approved') $this->error('Approbation de remise requise.');
            }
            $record=$this->updateRow('sales_quote_revisions',$revision,['status'=>$to,$action==='send'?'sent_at':'accepted_at'=>now()]);
            $quote->update(['status'=>$to,'document_version'=>((int)$quote->document_version)+1,
                $action==='send'?'sent_at':'accepted_at'=>now()]);
            $this->audit('quote.'.$to,'sales_quote_revisions',$revision);
            return $record;
        });
    }

    public function convertQuote(Request $r,int $revision)
    {
        $this->authorized('sales');
        return $this->idempotent($r,'convert-revision-'.$revision,function () use ($r,$revision) {
            $rev=$this->lock('sales_quote_revisions',$revision);
            $q=Quote::lockForUpdate()->findOrFail($rev->quote_id);
            if ($rev->status!=='accepted'||$q->status!=='accepted'||(int)$rev->revision_no!==(int)$q->revision_no) $this->error('Seule la révision acceptée courante peut être convertie.');
            if ($rev->valid_until<now()->toDateString()) $this->error('Révision expirée.');
            if (Order::where('quote_id',$q->id)->exists()) $this->error('Devis déjà converti.');
            $snapshot=json_decode($rev->snapshot,true,512,JSON_THROW_ON_ERROR);
            $order=Order::create(['number'=>DocumentNumber::next('CMD'),'customer_id'=>$q->customer_id,
                'quote_id'=>$q->id,'created_by'=>$r->user()->id,'notes'=>$q->notes,
                'fulfillment_mode'=>'reservation_v2','status'=>'draft','subtotal_cents'=>$rev->subtotal_cents,
                'tax_cents'=>$rev->tax_cents,'total_cents'=>$rev->total_cents]);
            $order->items()->createMany($snapshot['items']);
            $this->updateRow('sales_quote_revisions',$revision,['status'=>'converted']);
            $q->update(['status'=>'converted','document_version'=>((int)$q->document_version)+1]);
            $this->audit('quote.converted','quotes',$q->id,['order_id'=>$order->id]);
            return $order->load('items','customer')->toArray();
        });
    }

    public function reserveOrder(Request $r,int $order)
    {
        $this->authorized('sales');
        return $this->idempotent($r,'reserve-order-'.$order,function () use ($order) {
            $doc=Order::lockForUpdate()->findOrFail($order);
            if ($doc->fulfillment_mode!=='reservation_v2'||$doc->status!=='draft') $this->error('Commande non réservable via ce parcours.');
            $items=$doc->items->sortBy('product_id')->values();
            $products=Product::whereIn('id',$items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $p=$products[$item->product_id]??abort(404);
                $reserved=(int)$this->rows('sales_reservations')->where('product_id',$p->id)
                    ->selectRaw('COALESCE(SUM(quantity_reserved - quantity_shipped - quantity_released),0) AS qty')->value('qty');
                if ($p->stock-$reserved<$item->quantity) $this->error('Stock disponible insuffisant : '.$p->reference);
            }
            foreach ($items as $item) $this->inserted('sales_reservations',[
                'order_id'=>$order,'order_item_id'=>$item->id,'product_id'=>$item->product_id,
                'quantity_reserved'=>$item->quantity
            ]);
            $doc->update(['status'=>'reserved','document_version'=>((int)$doc->document_version)+1]);
            $this->audit('order.reserved','orders',$order);
            return $doc->fresh()->toArray();
        });
    }

    public function ship(Request $r,int $order)
    {
        $this->authorized('sales');
        $v=$r->validate([
            'recipient'=>'required|string|max:180','carrier'=>'nullable|string|max:120',
            'lines'=>'required|array|min:1|max:100',
            'lines.*.order_item_id'=>'required|integer|distinct',
            'lines.*.quantity'=>'required|integer|min:1|max:100000'
        ]);
        return $this->idempotent($r,'ship-order-'.$order,function () use ($r,$order,$v) {
            $doc=Order::lockForUpdate()->findOrFail($order);
            if ($doc->fulfillment_mode!=='reservation_v2'||!in_array($doc->status,['reserved','partially_shipped'],true)) $this->error('Commande non expédiable.');
            $ids=array_column($v['lines'],'order_item_id');
            $reservations=$this->rows('sales_reservations')->where('order_id',$order)->whereIn('order_item_id',$ids)->orderBy('product_id')->lockForUpdate()->get()->keyBy('order_item_id');
            if ($reservations->count()!==count($ids)) $this->error('Ligne hors commande.');
            $products=Product::whereIn('id',$reservations->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines=[];
            foreach ($v['lines'] as $line) {
                $res=$reservations[$line['order_item_id']];
                $remaining=$res->quantity_reserved-$res->quantity_shipped-$res->quantity_released;
                if ($line['quantity']>$remaining) $this->error('Quantité supérieure au reliquat.');
                $item=$doc->items->firstWhere('id',$res->order_item_id);
                $lines[]=['order_item_id'=>$item->id,'product_id'=>$item->product_id,
                    'quantity'=>$line['quantity'],'price_cents'=>$item->price_cents,'vat_bps'=>$item->vat_bps];
            }
            $delivery=$this->inserted('sales_deliveries',[
                'order_id'=>$order,'number'=>'BL-'.strtoupper(bin2hex(random_bytes(7))),
                'status'=>'issued','recipient'=>$v['recipient'],'carrier'=>$v['carrier']??null,
                'created_by'=>$r->user()->id,'issued_at'=>now()
            ]);
            foreach ($lines as $line) {
                $res=$reservations[$line['order_item_id']];
                // Reduce the outstanding reservation first so StockService can
                // enforce physical stock >= other outstanding reservations.
                $this->updateRow('sales_reservations',$res->id,['quantity_shipped'=>$res->quantity_shipped+$line['quantity']]);
                app(StockService::class)->move($products[$res->product_id],-$line['quantity'],'Expédition '.$delivery->number,$order);
                $this->inserted('sales_delivery_lines',array_merge(['delivery_id'=>$delivery->id],$line));
            }
            $open=(int)$this->rows('sales_reservations')->where('order_id',$order)
                ->selectRaw('COALESCE(SUM(quantity_reserved-quantity_shipped-quantity_released),0) AS qty')->value('qty');
            $doc->update(['status'=>$open>0?'partially_shipped':'shipped','document_version'=>((int)$doc->document_version)+1]);
            $this->audit('delivery.issued','sales_deliveries',$delivery->id,['order_id'=>$order]);
            return $delivery;
        });
    }

    public function acknowledge(Request $r,int $id)
    {
        $this->authorized('sales');
        $v=$r->validate(['proof_reference'=>'required|string|max:2000']);
        return $this->idempotent($r,'delivery-received-'.$id,function () use ($r,$id,$v) {
            $d=$this->lock('sales_deliveries',$id);
            if ($d->status!=='issued') $this->error('Bon non réceptionnable.');
            $record=$this->updateRow('sales_deliveries',$id,['status'=>'received',
                'received_by'=>$r->user()->id,'received_at'=>now(),'proof_reference'=>$v['proof_reference']]);
            $this->audit('delivery.received','sales_deliveries',$id);
            return $record;
        });
    }

    public function cancelOrder(Request $r,int $id)
    {
        $this->authorized('sales');
        return $this->idempotent($r,'cancel-order-'.$id,function () use ($id) {
            $doc=Order::lockForUpdate()->findOrFail($id);
            if ($doc->fulfillment_mode!=='reservation_v2'||!in_array($doc->status,['draft','reserved','partially_shipped'],true)) $this->error('Commande non annulable.');
            foreach ($this->rows('sales_reservations')->where('order_id',$id)->lockForUpdate()->get() as $res) {
                $left=$res->quantity_reserved-$res->quantity_shipped-$res->quantity_released;
                if ($left>0) $this->updateRow('sales_reservations',$res->id,['quantity_released'=>$res->quantity_released+$left]);
            }
            $doc->update(['status'=>'cancelled','document_version'=>((int)$doc->document_version)+1]);
            $this->audit('order.cancelled','orders',$id);
            return $doc->toArray();
        });
    }

    public function createContract(Request $r)
    {
        $this->authorized('sales');
        $v=$r->validate(['customer_id'=>'required|integer','establishment_id'=>'nullable|integer','title'=>'required|string|max:180',
            'currency'=>'required|in:EUR,USD,GBP','effective_on'=>'required|date',
            'expires_on'=>'required|date|after_or_equal:effective_on','terms'=>'nullable|string|max:8000',
            'renewal_rule'=>'nullable|string|max:100']);
        Customer::findOrFail($v['customer_id']);
        if (!empty($v['establishment_id']) && !$this->rows('establishments')->where('id',$v['establishment_id'])->exists()) abort(404);
        return $this->idempotent($r,'create-contract',function () use ($r,$v) {
            $record=$this->inserted('sales_contracts',array_merge($v,['number'=>'CTR-'.strtoupper(bin2hex(random_bytes(7))),
                'status'=>'draft','created_by'=>$r->user()->id]));
            $this->audit('contract.created','sales_contracts',$record->id);
            return $record;
        });
    }

    public function contractTransition(Request $r,int $id,string $action)
    {
        $this->authorized('sales');
        if (!in_array($action,['publish','amend','terminate'],true)) abort(404);
        $v=$action==='amend'?$r->validate(['effective_on'=>'required|date|after_or_equal:today',
            'expires_on'=>'required|date|after_or_equal:effective_on','terms'=>'required|string|max:8000']):[];
        return $this->idempotent($r,'contract-'.$action.'-'.$id,function () use ($r,$id,$action,$v) {
            $contract=$this->lock('sales_contracts',$id);
            if ($action==='publish' && $contract->status!=='draft') $this->error('Contrat déjà publié.');
            if ($action==='amend' && $contract->status!=='active') $this->error('Contrat inactif.');
            if ($action==='terminate' && $contract->status!=='active') $this->error('Contrat non résiliable.');
            if ($action==='terminate') $changes=['status'=>'terminated','terminated_at'=>now()];
            elseif ($action==='publish') $changes=['status'=>'active','published_at'=>now()];
            else $changes=array_merge($v,['version_no'=>$contract->version_no+1]);
            $updated=$this->updateRow('sales_contracts',$id,$changes);
            if ($action!=='terminate') $this->inserted('sales_contract_versions',[
                'contract_id'=>$id,'version_no'=>$updated->version_no,'effective_on'=>$updated->effective_on,
                'snapshot'=>json_encode($updated,JSON_THROW_ON_ERROR),'created_by'=>$r->user()->id,'published_at'=>now()
            ]);
            $this->audit('contract.'.$action,'sales_contracts',$id);
            return $updated;
        });
    }

    public function createPricing(Request $r)
    {
        $this->authorized('sales');
        $v=$r->validate(['product_id'=>'required|integer','establishment_id'=>'nullable|integer',
            'title'=>'required|string|max:180','currency'=>'required|in:EUR,USD,GBP',
            'price_cents'=>'required|integer|min:0|max:1000000000',
            'vat_bps'=>'required|integer|min:0|max:10000','discount_bps'=>'required|integer|min:0|max:10000',
            'priority'=>'required|integer|min:0|max:100000',
            'starts_on'=>'required|date','ends_on'=>'required|date|after_or_equal:starts_on']);
        Product::findOrFail($v['product_id']);
        if (!empty($v['establishment_id']) && !$this->rows('establishments')->where('id',$v['establishment_id'])->exists()) abort(404);
        return $this->idempotent($r,'create-pricing',function () use ($r,$v) {
            $record=$this->inserted('sales_price_rules',array_merge($v,['status'=>'draft','created_by'=>$r->user()->id]));
            $this->audit('pricing.created','sales_price_rules',$record->id);
            return $record;
        });
    }

    public function publishPricing(Request $r,int $id)
    {
        $this->authorized('admin');
        return $this->idempotent($r,'pricing-publish-'.$id,function () use ($r,$id) {
            $rule=$this->lock('sales_price_rules',$id);
            if ($rule->status!=='draft') $this->error('Grille déjà publiée.');
            // Lock every relevant price-row by tenant/product to avoid concurrent publication.
            Product::whereKey($rule->product_id)->lockForUpdate()->firstOrFail();
            $overlap=$this->rows('sales_price_rules')->where('product_id',$rule->product_id)
                ->where('currency',$rule->currency)->where('priority',$rule->priority)
                ->where('status','published')->where('starts_on','<=',$rule->ends_on)
                ->where('ends_on','>=',$rule->starts_on)
                ->where(function ($q) use ($rule) {
                    $rule->establishment_id===null?$q->whereNull('establishment_id'):$q->where('establishment_id',$rule->establishment_id);
                })->exists();
            if ($overlap) $this->error('Chevauchement de grilles exclusives.');
            $record=$this->updateRow('sales_price_rules',$id,['status'=>'published','published_by'=>$r->user()->id,'published_at'=>now()]);
            $this->audit('pricing.published','sales_price_rules',$id);
            return $record;
        });
    }

    public function createReturn(Request $r)
    {
        $this->authorized('sales');
        $v=$r->validate(['delivery_id'=>'required|integer','reason'=>'required|string|min:5|max:2000',
            'lines'=>'required|array|min:1|max:100',
            'lines.*.delivery_line_id'=>'required|integer|distinct',
            'lines.*.quantity'=>'required|integer|min:1|max:100000']);
        return $this->idempotent($r,'create-return',function () use ($r,$v) {
            $delivery=$this->lock('sales_deliveries',$v['delivery_id']);
            if (!in_array($delivery->status,['issued','received'],true)) $this->error('Livraison non éligible.');
            $ids=array_column($v['lines'],'delivery_line_id');
            $lines=$this->rows('sales_delivery_lines')->where('delivery_id',$delivery->id)->whereIn('id',$ids)->get()->keyBy('id');
            if (count($lines)!==count($ids)) $this->error('Ligne non livrée.');
            foreach ($v['lines'] as $line) {
                $already=(int)$this->rows('sales_return_lines')->where('delivery_line_id',$line['delivery_line_id'])->sum('quantity_received');
                if ($already+$line['quantity']>$lines[$line['delivery_line_id']]->quantity) $this->error('Quantité retournée supérieure à la quantité livrée.');
            }
            $ret=$this->inserted('sales_returns',['delivery_id'=>$delivery->id,'number'=>'RET-'.strtoupper(bin2hex(random_bytes(7))),
                'status'=>'quarantine','reason'=>$v['reason'],'created_by'=>$r->user()->id]);
            foreach ($v['lines'] as $line) $this->inserted('sales_return_lines',[
                'return_id'=>$ret->id,'delivery_line_id'=>$line['delivery_line_id'],
                'product_id'=>$lines[$line['delivery_line_id']]->product_id,
                'quantity_received'=>$line['quantity'],'quantity_credit_eligible'=>0,'quality_status'=>'quarantine']);
            $this->audit('return.quarantined','sales_returns',$ret->id);
            return $ret;
        });
    }

    public function inspectReturn(Request $r,int $id)
    {
        $this->authorized('admin');
        $v=$r->validate(['decision'=>'required|in:sellable,defective',
            'credit_eligible'=>'required|boolean']);
        return $this->idempotent($r,'inspect-return-'.$id,function () use ($r,$id,$v) {
            $ret=$this->lock('sales_returns',$id);
            if ($ret->status!=='quarantine') $this->error('Contrôle qualité déjà effectué.');
            $lines=$this->rows('sales_return_lines')->where('return_id',$id)->orderBy('product_id')->lockForUpdate()->get();
            $products=Product::whereIn('id',$lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                if ($v['decision']==='sellable') {
                    app(StockService::class)->move($products[$line->product_id],$line->quantity_received,'Retour contrôlé '.$ret->number);
                }
                $this->updateRow('sales_return_lines',$line->id,['quality_status'=>$v['decision'],
                    'quantity_credit_eligible'=>$v['credit_eligible']?$line->quantity_received:0]);
            }
            $record=$this->updateRow('sales_returns',$id,['status'=>'inspected','inspected_by'=>$r->user()->id,'inspected_at'=>now()]);
            $this->audit('return.inspected','sales_returns',$id);
            return $record;
        });
    }

    public function creditReturn(Request $r,int $id)
    {
        $this->authorized('admin');
        return $this->idempotent($r,'credit-return-'.$id,function () use ($r,$id) {
            $ret=$this->lock('sales_returns',$id);
            if ($ret->status!=='inspected') $this->error('Retour non contrôlé.');
            if ($this->rows('sales_credit_notes')->where('return_id',$id)->exists()) $this->error('Avoir déjà créé.');
            $delivery=$this->one('sales_deliveries',$ret->delivery_id);
            $invoice=Invoice::where('order_id',$delivery->order_id)->lockForUpdate()->firstOrFail();
            if (!in_array($invoice->status,['issued','paid'],true)) $this->error('Facture non émise.');
            $sum=0;$snapshot=[];
            $lines=$this->rows('sales_return_lines')->where('return_id',$id)->get();
            foreach ($lines as $line) {
                if (!$line->quantity_credit_eligible) continue;
                $source=$this->one('sales_delivery_lines',$line->delivery_line_id);
                $amount=Money::line($source->price_cents,$line->quantity_credit_eligible,$source->vat_bps);
                $sum+=$amount['total_cents'];
                $snapshot[]=array_merge((array)$line,['amounts'=>$amount]);
            }
            if ($sum<=0) $this->error('Aucune quantité autorisée à créditer.');
            $used=(int)$this->rows('sales_credit_notes')->where('invoice_id',$invoice->id)->sum('total_cents');
            if ($used+$sum>$invoice->total_cents) $this->error('Plafond de la facture dépassé.');
            $note=$this->inserted('sales_credit_notes',['return_id'=>$id,'invoice_id'=>$invoice->id,
                'number'=>'AV-'.strtoupper(bin2hex(random_bytes(7))),'total_cents'=>$sum,
                'snapshot'=>json_encode($snapshot,JSON_THROW_ON_ERROR),'issued_by'=>$r->user()->id,'issued_at'=>now()]);
            $this->updateRow('sales_returns',$id,['status'=>'credited']);
            $this->audit('credit.issued','sales_credit_notes',$note->id);
            return $note;
        });
    }
}
