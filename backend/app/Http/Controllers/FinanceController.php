<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * M06 financial API. All object lookups include the authoritative session tenant.
 * Money is always expressed as nonnegative integer cents; no client-supplied totals.
 */
final class FinanceController extends Controller
{
    private function tenant(): int { return app(TenantContext::class)->requireId(); }
    private function read(): void { Gate::authorize('invoicesRead'); }
    private function write(): void { Gate::authorize('admin'); }
    private function query(string $table) { return DB::table('finance_'.$table)->where('company_id', $this->tenant()); }
    private function record(string $table, int $id, bool $lock = false): object
    {
        $q = $this->query($table)->where('id', $id);
        return ($lock ? $q->lockForUpdate() : $q)->first() ?? abort(404, 'Enregistrement inaccessible.');
    }
    private function invoice(int $id, bool $lock = false): Invoice
    {
        $q = Invoice::query()->whereKey($id);
        return ($lock ? $q->lockForUpdate() : $q)->firstOrFail();
    }
    private function moneyRules(): array { return ['required','integer','min:1','max:100000000000']; }

    private function idempotent(string $operation, array $data, callable $perform): array
    {
        $key = $data['request_key'] ?? null;
        abort_unless(is_string($key) && preg_match('/^[A-Za-z0-9_-]{8,120}$/D', $key), 422, 'Clé de requête obligatoire (8 à 120 caractères).');
        unset($data['request_key']);
        $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return DB::transaction(function () use ($operation, $key, $digest, $perform) {
            // PostgreSQL serializes simultaneous calls sharing the same key.
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$this->tenant().':'.$operation.':'.$key]);
            }
            $existing = $this->query('idempotency')->where('operation', $operation)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_sha256, $digest), 409, 'Clé déjà utilisée avec un contenu différent.');
                return ['id' => (int)$existing->entity_id, 'replayed' => true];
            }
            $id = (int)$perform();
            DB::table('finance_idempotency')->insert([
                'company_id' => $this->tenant(), 'operation' => $operation,
                'request_key' => $key, 'payload_sha256' => $digest,
                'entity_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['id' => $id, 'replayed' => false];
        }, 3);
    }
    private function event(string $name, string $aggregate, int $id, array $payload = []): void
    {
        DB::table('finance_outbox')->insert([
            'company_id'=>$this->tenant(), 'event_name'=>$name, 'aggregate'=>$aggregate,
            'aggregate_id'=>$id, 'payload'=>json_encode($payload, JSON_THROW_ON_ERROR),
            'status'=>'pending','created_at'=>now(),'updated_at'=>now(),
        ]);
        Audit::record($name, null, ['aggregate'=>$aggregate,'id'=>$id] + $payload);
    }
    private function balance(Invoice $invoice): array
    {
        $credits = (int)$this->query('credit_notes')->where('invoice_id',$invoice->id)->sum('amount_cents');
        $paid = (int)$this->query('receipt_allocations')->where('invoice_id',$invoice->id)->sum('amount_cents');
        $total = (int)$invoice->total_cents;
        $outstanding = in_array($invoice->status, ['cancelled','paid'], true) ? 0 : max(0,$total-$credits-$paid);
        return ['invoice_id'=>$invoice->id,'total_cents'=>$total,
            'credit_cents'=>$credits,'allocated_cents'=>$paid,'outstanding_cents'=>$outstanding,
            'currency'=>$invoice->currency ?? 'EUR','status'=>$invoice->status];
    }
    public function balances(Request $request)
    {
        $this->read();
        $request->validate(['status'=>'nullable|in:issued,paid,cancelled,draft','page'=>'sometimes|integer|min:1']);
        $rows = Invoice::query()->when($request->status,fn($q)=>$q->where('status',$request->status))
            ->latest('id')->paginate(20);
        $rows->getCollection()->transform(fn($inv)=>$this->balance($inv));
        return $rows;
    }
    public function invoiceBalance(int $id) { $this->read(); return $this->balance($this->invoice($id)); }

    public function index(Request $r, string $kind)
    {
        $this->read();
        $map = ['credit-notes'=>'credit_notes','collections'=>'receipts',
            'allocations'=>'receipt_allocations','payment-reminders'=>'reminders',
            'disputes'=>'disputes','expenses'=>'expenses','cost-centers'=>'cost_centers',
            'budgets'=>'budget_versions','budget-movements'=>'budget_movements',
            'accounts'=>'gl_accounts','periods'=>'gl_periods','journals'=>'gl_journals',
            'entries'=>'gl_entries','tax-rates'=>'tax_versions','fx-rates'=>'fx_rates',
            'accounting-exports'=>'export_batches'];
        abort_unless(isset($map[$kind]),404);
        $d=$r->validate(['page'=>'sometimes|integer|min:1','status'=>'nullable|string|max:30',
            'from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d',
            'cost_center_id'=>'nullable|integer|min:1','invoice_id'=>'nullable|integer|min:1']);
        $q=$this->query($map[$kind]);
        if(isset($d['status']) && in_array($kind,['payment-reminders','disputes','expenses','budgets','periods','entries'],true))
            $q->where('status',$d['status']);
        if(isset($d['invoice_id']) && in_array($kind,['credit-notes','allocations','payment-reminders','disputes'],true))
            $q->where('invoice_id',$d['invoice_id']);
        if(isset($d['cost_center_id']) && in_array($kind,['expenses','budgets'],true))
            $q->where('cost_center_id',$d['cost_center_id']);
        if(isset($d['from']) && isset($d['to']) && $d['from']>$d['to']) abort(422,'Dates inversées.');
        if(isset($d['from']) && $kind==='expenses') $q->where('expense_date','>=',$d['from']);
        if(isset($d['to']) && $kind==='expenses') $q->where('expense_date','<=',$d['to']);
        return $q->orderByDesc('id')->paginate(20);
    }

    public function credit(Request $r)
    {
        $this->write();
        $d=$r->validate(['invoice_id'=>'required|integer|min:1','amount_cents'=>$this->moneyRules(),
            'reason'=>'required|string|min:8|max:2000',
            'original_lines'=>'nullable|array|max:100',
            'request_key'=>'required|string|min:8|max:120']);
        $result=$this->idempotent('credit', $d, function () use($d,$r) {
            $invoice=$this->invoice($d['invoice_id'],true);
            abort_unless(in_array($invoice->status,['issued','paid'],true),409,'Facture non émise.');
            $b=$this->balance($invoice);
            abort_unless($d['amount_cents'] <= $b['outstanding_cents'],409,'Avoir supérieur au solde éligible.');
            $number=$this->number('AV');
            $id=DB::table('finance_credit_notes')->insertGetId([
                'company_id'=>$this->tenant(),'invoice_id'=>$invoice->id,'amount_cents'=>$d['amount_cents'],
                'number'=>$number,'reason'=>$d['reason'],'currency'=>$b['currency'],
                'original_lines'=>isset($d['original_lines'])?json_encode($d['original_lines']):null,
                'issued_by'=>$r->user()->id,'issued_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->markPaidIfZero($invoice);
            $this->event('finance.credit.issued','credit_note',$id,['invoice_id'=>$invoice->id]);
            return $id;
        });
        return response()->json($this->record('credit_notes',$result['id']),$result['replayed']?200:201);
    }
    private function number(string $prefix): string
    {
        // Separate tenant-specific finance sequence; do not collide with legacy FAC series.
        $key='FIN-'.$this->tenant().'-'.$prefix.'-'.now()->format('Y');
        DB::table('document_sequences')->insertOrIgnore(['key'=>$key,'value'=>0]);
        $sequence=DB::table('document_sequences')->where('key',$key)->lockForUpdate()->first();
        DB::table('document_sequences')->where('key',$key)->update(['value'=>$sequence->value+1]);
        return $prefix.'-'.now()->format('Y').'-'.str_pad((string)($sequence->value+1),6,'0',STR_PAD_LEFT);
    }
    private function markPaidIfZero(Invoice $invoice): void
    {
        $b=$this->balance($invoice);
        if($invoice->status==='issued' && $b['total_cents'] > 0 &&
            $b['credit_cents']+$b['allocated_cents']===$b['total_cents']) {
            $invoice->update(['status'=>'paid','paid_at'=>now()]);
        }
    }
    public function receipt(Request $r)
    {
        $this->write();
        $d=$r->validate(['amount_cents'=>$this->moneyRules(),
            'currency'=>['sometimes','string',Rule::in(['EUR','USD','GBP'])],
            'received_on'=>'required|date_format:Y-m-d','method'=>'required|in:bank,cash,card,transfer',
            'reference'=>'nullable|string|max:120','external_event_id'=>'nullable|string|max:140',
            'request_key'=>'required|string|min:8|max:120']);
        $result=$this->idempotent('receipt',$d,function()use($d,$r){
            if (isset($d['external_event_id']) && $this->query('receipts')->where('external_event_id',$d['external_event_id'])->exists()) {
                abort(409,'Identifiant externe déjà enregistré.');
            }
            $id=DB::table('finance_receipts')->insertGetId([
                'company_id'=>$this->tenant(),'amount_cents'=>$d['amount_cents'],
                'currency'=>$d['currency']??'EUR','received_on'=>$d['received_on'],
                'method'=>$d['method'],'reference'=>$d['reference']??null,
                'external_event_id'=>$d['external_event_id']??null,
                'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event('finance.receipt.recorded','receipt',$id);
            return $id;
        });
        return response()->json($this->record('receipts',$result['id']),$result['replayed']?200:201);
    }
    public function allocate(Request $r, int $receipt)
    {
        $this->write();
        $d=$r->validate(['invoice_id'=>'required|integer|min:1','amount_cents'=>$this->moneyRules(),
            'request_key'=>'required|string|min:8|max:120']);
        $result=$this->idempotent('allocate:'.$receipt,$d,function()use($d,$r,$receipt){
            $row=$this->record('receipts',$receipt,true);
            $invoice=$this->invoice($d['invoice_id'],true);
            abort_unless($invoice->status==='issued',409,'Facture non encaissable.');
            abort_unless($row->currency===($invoice->currency??'EUR'),409,'Conversion nécessaire avant affectation.');
            $allocated=(int)$this->query('receipt_allocations')->where('receipt_id',$receipt)->sum('amount_cents');
            abort_unless($allocated+$d['amount_cents'] <= $row->amount_cents,409,'Versement déjà affecté.');
            $b=$this->balance($invoice);
            abort_unless($d['amount_cents'] <= $b['outstanding_cents'],409,'Paiement supérieur au solde.');
            $id=DB::table('finance_receipt_allocations')->insertGetId([
                'company_id'=>$this->tenant(),'receipt_id'=>$receipt,'invoice_id'=>$invoice->id,
                'amount_cents'=>$d['amount_cents'],'allocated_by'=>$r->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->markPaidIfZero($invoice);
            $this->event('finance.receipt.allocated','allocation',$id,['invoice_id'=>$invoice->id]);
            return $id;
        });
        return response()->json($this->record('receipt_allocations',$result['id']),$result['replayed']?200:201);
    }

    public function dispute(Request $r)
    {
        $this->write();
        $d=$r->validate(['invoice_id'=>'required|integer|min:1','reason'=>'required|string|min:8|max:1000']);
        $this->invoice($d['invoice_id']);
        return DB::transaction(function()use($d,$r){
            $id=DB::table('finance_disputes')->insertGetId([
                'company_id'=>$this->tenant(),'invoice_id'=>$d['invoice_id'],
                'status'=>'pending','reason'=>$d['reason'],'requested_by'=>$r->user()->id,
                'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.dispute.opened','dispute',$id);
            return response()->json($this->record('disputes',$id),201);
        });
    }
    public function decideDispute(Request $r,int $id)
    {
        $this->write();
        $d=$r->validate(['decision'=>'required|in:approved,rejected,resolved']);
        return DB::transaction(function()use($d,$id,$r){
            $item=$this->record('disputes',$id,true);
            abort_unless(in_array([$item->status,$d['decision']],[
                ['pending','approved'],['pending','rejected'],['approved','resolved']
            ],true),409,'Transition du litige interdite.');
            abort_if((int)$item->requested_by===(int)$r->user()->id,403,'Validation par un second intervenant obligatoire.');
            $this->query('disputes')->where('id',$id)->update(['status'=>$d['decision'],'decided_by'=>$r->user()->id,'decided_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.dispute.'.$d['decision'],'dispute',$id);
            return $this->record('disputes',$id);
        });
    }
    public function policy(Request $r)
    {
        $this->read();
        return $this->query('reminder_policies')->first() ?? ['enabled'=>false,'cadence_days'=>null];
    }
    public function setPolicy(Request $r)
    {
        $this->write();
        $d=$r->validate(['cadence_days'=>'required|integer|min:1|max:365',
            'template_version'=>'required|string|max:50','enabled'=>'required|boolean']);
        return DB::transaction(function()use($d,$r){
            $this->query('reminder_policies')->updateOrInsert(['company_id'=>$this->tenant()],$d+[
                'updated_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()
            ]);
            $this->event('finance.reminder.policy.updated','reminder_policy',$this->tenant());
            return $this->policy($r);
        });
    }
    public function reminder(Request $r)
    {
        $this->write();
        $d=$r->validate(['invoice_id'=>'required|integer|min:1','channel'=>'required|in:email,letter,phone',
            'request_key'=>'required|string|min:8|max:120']);
        $result=$this->idempotent('reminder',$d,function()use($d,$r){
            $policy=$this->query('reminder_policies')->first();
            abort_unless($policy && $policy->enabled,409,'Politique de relance non activée.');
            $invoice=$this->invoice($d['invoice_id'],true);
            abort_unless($invoice->status==='issued' && $invoice->due_at && $invoice->due_at < today()->toDateString(),409,'Facture non échue.');
            abort_if($this->query('disputes')->where('invoice_id',$invoice->id)->where('status','approved')->exists(),409,'Relance suspendue : litige approuvé.');
            $b=$this->balance($invoice);
            abort_unless($b['outstanding_cents']>0,409,'Aucune somme échue.');
            $last=$this->query('reminders')->where('invoice_id',$invoice->id)->orderByDesc('reminder_date')->first();
            if($last)abort_unless(now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($last->reminder_date),false)<=-$policy->cadence_days,409,'Cadence de relance non respectée.');
            $digest=hash('sha256',join('|',[$invoice->id,$b['outstanding_cents'],$policy->template_version,$d['channel']]));
            $id=DB::table('finance_reminders')->insertGetId([
                'company_id'=>$this->tenant(),'invoice_id'=>$invoice->id,
                'outstanding_cents'=>$b['outstanding_cents'],'channel'=>$d['channel'],
                'template_version'=>$policy->template_version,'status'=>'prepared',
                'reminder_date'=>today()->toDateString(),'prepared_by'=>$r->user()->id,
                'content_digest'=>$digest,'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.reminder.prepared','reminder',$id);
            return $id;
        });
        return response()->json($this->record('reminders',$result['id']),$result['replayed']?200:201);
    }

    public function costCenter(Request $r)
    {
        $this->write();
        $d=$r->validate(['code'=>'required|alpha_dash|max:40','name'=>'required|string|max:160']);
        return DB::transaction(function()use($d){
            abort_if($this->query('cost_centers')->where('code',$d['code'])->exists(),409,'Centre de coûts existant.');
            $id=DB::table('finance_cost_centers')->insertGetId($d+['company_id'=>$this->tenant(),'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.cost_center.created','cost_center',$id);
            return response()->json($this->record('cost_centers',$id),201);
        });
    }
    public function expense(Request $r)
    {
        $this->write();
        $d=$r->validate(['cost_center_id'=>'required|integer|min:1','supplier_name'=>'required|string|max:200',
            'category'=>'required|string|max:100','receipt_reference'=>'required|string|max:160',
            'receipt_digest'=>'required|regex:/^[a-f0-9]{64}$/D',
            'expense_date'=>'required|date_format:Y-m-d','amount_cents'=>$this->moneyRules(),
            'currency'=>['required',Rule::in(['EUR','USD','GBP'])],
            'request_key'=>'required|string|min:8|max:120']);
        $result=$this->idempotent('expense',$d,function()use($d,$r){
            abort_unless($this->query('cost_centers')->where('id',$d['cost_center_id'])->where('active',true)->exists(),404,'Centre de coûts inaccessible.');
            abort_if($this->query('expenses')->where('receipt_digest',$d['receipt_digest'])->exists(),409,'Justificatif déjà présenté.');
            unset($d['request_key']);
            $id=DB::table('finance_expenses')->insertGetId($d+[
                'company_id'=>$this->tenant(),'status'=>'draft','requested_by'=>$r->user()->id,
                'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.expense.created','expense',$id);
            return $id;
        });
        return response()->json($this->record('expenses',$result['id']),$result['replayed']?200:201);
    }
    public function decideExpense(Request $r,int $id)
    {
        $this->write();
        $d=$r->validate(['decision'=>'required|in:approved,rejected','reason'=>'nullable|string|min:5|max:500']);
        return DB::transaction(function()use($d,$id,$r){
            $expense=$this->record('expenses',$id,true);
            abort_unless($expense->status==='draft',409,'Dépense déjà décidée.');
            abort_if((int)$expense->requested_by===(int)$r->user()->id,403,'Séparation des tâches obligatoire.');
            $this->query('expenses')->where('id',$id)->update([
                'status'=>$d['decision'],'approved_by'=>$r->user()->id,
                'approved_at'=>now(),'decision_reason'=>$d['reason']??null,'updated_at'=>now()]);
            if($d['decision']==='approved') {
                $budget=$this->query('budget_versions')->where('cost_center_id',$expense->cost_center_id)
                    ->where('fiscal_year',(int)substr($expense->expense_date,0,4))
                    ->where('currency',$expense->currency)->where('status','approved')
                    ->orderByDesc('revision')->lockForUpdate()->first();
                if($budget) {
                    $this->movement($budget->id,'consumed',(int)$expense->amount_cents,$r->user()->id,$id);
                }
            }
            $this->event('finance.expense.'.$d['decision'],'expense',$id);
            return $this->record('expenses',$id);
        });
    }
    private function movement(int $budgetId,string $kind,int $amount,int $userId,?int $expenseId=null,?string $reference=null): int
    {
        $budget=$this->record('budget_versions',$budgetId,true);
        abort_unless($budget->status==='approved',409,'Budget non approuvé.');
        $sum=(int)$this->query('budget_movements')->where('budget_version_id',$budgetId)
            ->whereIn('kind',['committed','consumed','reserved'])->sum('amount_cents');
        abort_unless($sum+$amount<=$budget->limit_cents,409,'Budget insuffisant.');
        return DB::table('finance_budget_movements')->insertGetId([
            'company_id'=>$this->tenant(),'budget_version_id'=>$budgetId,'kind'=>$kind,
            'amount_cents'=>$amount,'expense_id'=>$expenseId,'source_reference'=>$reference,
            'created_by'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function budget(Request $r)
    {
        $this->write();
        $d=$r->validate(['cost_center_id'=>'required|integer|min:1','fiscal_year'=>'required|integer|between:2000,2100',
            'limit_cents'=>$this->moneyRules(),'forecast_cents'=>'sometimes|integer|min:0',
            'currency'=>['required',Rule::in(['EUR','USD','GBP'])],
            'revision_reason'=>'nullable|string|max:500']);
        return DB::transaction(function()use($d,$r){
            abort_unless($this->query('cost_centers')->where('id',$d['cost_center_id'])->exists(),404);
            $previous=$this->query('budget_versions')->where('cost_center_id',$d['cost_center_id'])
                ->where('fiscal_year',$d['fiscal_year'])->orderByDesc('revision')->lockForUpdate()->first();
            if($previous) {
                abort_unless($previous->status==='approved',409,'Finaliser la version précédente.');
                abort_unless(strlen(trim($d['revision_reason']??''))>=5,422,'Motif de révision requis.');
            }
            $id=DB::table('finance_budget_versions')->insertGetId($d+[
                'company_id'=>$this->tenant(),'revision'=>$previous?($previous->revision+1):1,
                'status'=>'draft','created_by'=>$r->user()->id,
                'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.budget.drafted','budget',$id);
            return response()->json($this->record('budget_versions',$id),201);
        });
    }
    public function approveBudget(Request $r,int $id)
    {
        $this->write();
        return DB::transaction(function()use($r,$id){
            $item=$this->record('budget_versions',$id,true);
            abort_unless($item->status==='draft',409,'Budget non modifiable.');
            abort_if((int)$item->created_by===(int)$r->user()->id,403,'Approbation par un autre utilisateur requise.');
            $this->query('budget_versions')->where('id',$id)->update([
                'status'=>'approved','approved_by'=>$r->user()->id,'approved_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.budget.approved','budget',$id);
            return $this->record('budget_versions',$id);
        });
    }
    public function budgetMovement(Request $r,int $id)
    {
        $this->write();
        $d=$r->validate(['kind'=>'required|in:committed,reserved','amount_cents'=>$this->moneyRules(),
            'source_reference'=>'required|string|max:120','request_key'=>'required|string|min:8|max:120']);
        $result=$this->idempotent('budget-movement:'.$id,$d,function()use($d,$id,$r){
            $newId=$this->movement($id,$d['kind'],(int)$d['amount_cents'],$r->user()->id,null,$d['source_reference']);
            $this->event('finance.budget.'.$d['kind'],'budget_movement',$newId);
            return $newId;
        });
        return response()->json($this->record('budget_movements',$result['id']),$result['replayed']?200:201);
    }
    public function budgetSummary(int $id)
    {
        $this->read();
        $budget=$this->record('budget_versions',$id);
        $totals=$this->query('budget_movements')->where('budget_version_id',$id)->selectRaw('kind, SUM(amount_cents) AS amount')->groupBy('kind')->pluck('amount','kind');
        $used=(int)($totals['consumed']??0);$committed=(int)($totals['committed']??0);$reserved=(int)($totals['reserved']??0);
        return ['budget'=>$budget,'consumed_cents'=>$used,'committed_cents'=>$committed,
            'reserved_cents'=>$reserved,'available_cents'=>max(0,$budget->limit_cents-$used-$committed-$reserved)];
    }

    public function createAccount(Request $r){ return $this->createReference($r,'gl_accounts',['code'=>'required|alpha_dash|max:30','name'=>'required|string|max:180']); }
    public function createJournal(Request $r){ return $this->createReference($r,'gl_journals',['code'=>'required|alpha_dash|max:30','name'=>'required|string|max:150']); }
    public function createPeriod(Request $r)
    {
        $this->write();
        $d=$r->validate(['code'=>'required|alpha_dash|max:40','starts_on'=>'required|date_format:Y-m-d',
            'ends_on'=>'required|date_format:Y-m-d|after_or_equal:starts_on']);
        return DB::transaction(function()use($d){
            abort_if($this->query('gl_periods')->where('starts_on','<=',$d['ends_on'])->where('ends_on','>=',$d['starts_on'])->exists(),409,'Période chevauchante.');
            $id=DB::table('finance_gl_periods')->insertGetId($d+['company_id'=>$this->tenant(),'status'=>'open','created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.period.opened','period',$id);
            return response()->json($this->record('gl_periods',$id),201);
        });
    }
    private function createReference(Request $r,string $table,array $rules)
    {
        $this->write();
        $d=$r->validate($rules);
        return DB::transaction(function()use($table,$d){
            abort_if($this->query($table)->where('code',$d['code'])->exists(),409,'Code déjà utilisé.');
            $id=DB::table('finance_'.$table)->insertGetId($d+['company_id'=>$this->tenant(),'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.'.$table.'.created',$table,$id);
            return response()->json($this->record($table,$id),201);
        });
    }
    public function entry(Request $r)
    {
        $this->write();
        $d=$r->validate(['journal_id'=>'required|integer|min:1','period_id'=>'required|integer|min:1',
            'entry_date'=>'required|date_format:Y-m-d','reference'=>'required|string|max:100',
            'description'=>'required|string|max:500','lines'=>'required|array|min:2|max:100',
            'lines.*.account_id'=>'required|integer|min:1',
            'lines.*.debit_cents'=>'required|integer|min:0|max:100000000000',
            'lines.*.credit_cents'=>'required|integer|min:0|max:100000000000',
            'request_key'=>'required|string|min:8|max:120']);
        $result=$this->idempotent('gl-entry',$d,function()use($d,$r){
            $journal=$this->record('gl_journals',$d['journal_id']);
            $period=$this->record('gl_periods',$d['period_id'],true);
            abort_unless($period->status==='open' && $d['entry_date'] >= $period->starts_on && $d['entry_date'] <= $period->ends_on,409,'Période fermée ou date incorrecte.');
            $debit=0;$credit=0;
            foreach($d['lines'] as $line) {
                abort_unless($this->query('gl_accounts')->where('id',$line['account_id'])->where('active',true)->exists(),404,'Compte inaccessible.');
                abort_unless(($line['debit_cents']>0) !== ($line['credit_cents']>0),422,'Chaque ligne est soit débit soit crédit.');
                $debit+=$line['debit_cents'];$credit+=$line['credit_cents'];
            }
            abort_unless($debit===$credit && $debit>0,422,'Écriture non équilibrée.');
            $id=DB::table('finance_gl_entries')->insertGetId([
                'company_id'=>$this->tenant(),'journal_id'=>$journal->id,'period_id'=>$period->id,
                'entry_date'=>$d['entry_date'],'reference'=>$d['reference'],'description'=>$d['description'],
                'status'=>'draft','created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            foreach($d['lines'] as $line) DB::table('finance_gl_entry_lines')->insert([
                'company_id'=>$this->tenant(),'entry_id'=>$id,'account_id'=>$line['account_id'],
                'debit_cents'=>$line['debit_cents'],'credit_cents'=>$line['credit_cents'],
                'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.gl.drafted','gl_entry',$id);
            return $id;
        });
        return response()->json($this->record('gl_entries',$result['id']),$result['replayed']?200:201);
    }
    public function entryLines(int $id)
    {
        $this->read();$this->record('gl_entries',$id);
        return $this->query('gl_entry_lines')->where('entry_id',$id)->orderBy('id')->get();
    }
    public function postEntry(Request $r,int $id)
    {
        $this->write();
        return DB::transaction(function()use($r,$id){
            $entry=$this->record('gl_entries',$id,true);
            abort_unless($entry->status==='draft',409,'Écriture déjà validée.');
            abort_if((int)$entry->created_by===(int)$r->user()->id,403,'Validation par un deuxième utilisateur obligatoire.');
            $period=$this->record('gl_periods',$entry->period_id,true);
            abort_unless($period->status==='open',409,'Période fermée.');
            $totals=$this->query('gl_entry_lines')->where('entry_id',$id)
                ->selectRaw('SUM(debit_cents) AS debits, SUM(credit_cents) AS credits, COUNT(*) AS n')->first();
            abort_unless($totals->n>=2 && $totals->debits>0 && $totals->debits==$totals->credits,409,'Écriture non équilibrée.');
            $this->query('gl_entries')->where('id',$id)->update([
                'status'=>'posted','posted_by'=>$r->user()->id,'posted_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.gl.posted','gl_entry',$id);
            return $this->record('gl_entries',$id);
        });
    }
    public function closePeriod(Request $r,int $id)
    {
        $this->write();
        return DB::transaction(function()use($r,$id){
            $period=$this->record('gl_periods',$id,true);
            abort_unless($period->status==='open',409,'Période déjà fermée.');
            abort_if($this->query('gl_entries')->where('period_id',$id)->where('status','draft')->exists(),409,'Écritures brouillon en attente.');
            $this->query('gl_periods')->where('id',$id)->update(['status'=>'closed','closed_by'=>$r->user()->id,'closed_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.period.closed','period',$id);
            return $this->record('gl_periods',$id);
        });
    }
    public function taxRate(Request $r)
    {
        $this->write();
        $d=$r->validate(['jurisdiction'=>'required|string|max:80','tax_code'=>'required|alpha_dash|max:40',
            'rate_bps'=>'required|integer|between:0,10000','effective_from'=>'required|date_format:Y-m-d',
            'effective_to'=>'nullable|date_format:Y-m-d|after_or_equal:effective_from']);
        return DB::transaction(function()use($d,$r){
            abort_if($this->query('tax_versions')->where('jurisdiction',$d['jurisdiction'])
                ->where('tax_code',$d['tax_code'])->where('effective_from','<=',$d['effective_to']??'9999-12-31')
                ->where(fn($q)=>$q->whereNull('effective_to')->orWhere('effective_to','>=',$d['effective_from']))->exists(),409,'Période de taxe chevauchante.');
            $id=DB::table('finance_tax_versions')->insertGetId($d+['company_id'=>$this->tenant(),'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.tax.versioned','tax_version',$id);
            return response()->json($this->record('tax_versions',$id),201);
        });
    }
    public function fxRate(Request $r)
    {
        $this->write();
        $d=$r->validate(['base_currency'=>['required',Rule::in(['EUR','USD','GBP'])],
            'quote_currency'=>['required','different:base_currency',Rule::in(['EUR','USD','GBP'])],
            'rate'=>'required|numeric|gt:0|decimal:1,10','effective_on'=>'required|date_format:Y-m-d',
            'source'=>'required|string|max:100']);
        return DB::transaction(function()use($d,$r){
            abort_if($this->query('fx_rates')->where('base_currency',$d['base_currency'])
                ->where('quote_currency',$d['quote_currency'])->where('effective_on',$d['effective_on'])->exists(),409,'Taux déjà publié.');
            $id=DB::table('finance_fx_rates')->insertGetId($d+['company_id'=>$this->tenant(),'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.fx.versioned','fx_rate',$id);
            return response()->json($this->record('fx_rates',$id),201);
        });
    }

    public function export(Request $r)
    {
        $this->write();
        $d=$r->validate(['period_id'=>'required|integer|min:1','request_key'=>'required|string|min:8|max:120']);
        $result=$this->idempotent('export',$d,function()use($d,$r){
            $period=$this->record('gl_periods',$d['period_id'],true);
            abort_unless($period->status==='closed',409,'Fermer la période avant l’export.');
            $entries=$this->query('gl_entries')->where('period_id',$period->id)->where('status','posted')->orderBy('id')->pluck('id');
            abort_unless($entries->isNotEmpty(),409,'Aucune écriture comptabilisée.');
            $rows=DB::table('finance_gl_entry_lines as l')
                ->join('finance_gl_entries as e',function($j){$j->on('e.id','=','l.entry_id')->on('e.company_id','=','l.company_id');})
                ->join('finance_gl_accounts as a',function($j){$j->on('a.id','=','l.account_id')->on('a.company_id','=','l.company_id');})
                ->where('l.company_id',$this->tenant())->where('e.period_id',$period->id)->where('e.status','posted')
                ->orderBy('e.id')->orderBy('l.id')
                ->get(['e.entry_date','e.reference','a.code as account','l.debit_cents','l.credit_cents','e.id as entry_id','l.id as line_id']);
            $debit=0;$credit=0;
            $csv=fopen('php://temp','w+');
            fputcsv($csv,['entry_date','entry_reference','account','debit_cents','credit_cents','entry_id','line_id'],',','"','');
            // Defuse spreadsheet formulae in human-readable CSV fields.
            $safeCell = static function (string $text): string {
                return preg_match('/^[\\s]*[=+@-]/u', $text) ? "'".$text : $text;
            };
            foreach($rows as $row){
                abort_unless($row->account,409,'Compte comptable non mappé.');
                $debit+=(int)$row->debit_cents;$credit+=(int)$row->credit_cents;
                fputcsv($csv,[(string)$row->entry_date,$safeCell((string)$row->reference),$safeCell((string)$row->account),$row->debit_cents,$row->credit_cents,$row->entry_id,$row->line_id],',','"','');
            }
            abort_unless($debit===$credit && $debit>0,409,'Export comptable déséquilibré.');
            rewind($csv);$snapshot=stream_get_contents($csv);fclose($csv);
            $checksum=hash('sha256',$snapshot);
            $id=DB::table('finance_export_batches')->insertGetId([
                'company_id'=>$this->tenant(),'period_id'=>$period->id,'format'=>'atlas-gl-v1',
                'checksum'=>$checksum,'row_count'=>count($rows),'debit_cents'=>$debit,'credit_cents'=>$credit,
                'csv_snapshot'=>$snapshot,'created_by'=>$r->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            $this->event('finance.export.prepared','export_batch',$id,['checksum'=>$checksum]);
            return $id;
        });
        $row=$this->record('export_batches',$result['id']);
        return response()->json(collect($row)->except('csv_snapshot')->all(),$result['replayed']?200:201);
    }
    public function download(int $id)
    {
        $this->read();
        $batch=$this->record('export_batches',$id);
        return response($batch->csv_snapshot,200,[
            'Content-Type'=>'text/csv; charset=UTF-8','X-Content-Type-Options'=>'nosniff',
            'Content-Disposition'=>'attachment; filename="atlas-finance-'.$batch->id.'.csv"'
        ]);
    }
}
