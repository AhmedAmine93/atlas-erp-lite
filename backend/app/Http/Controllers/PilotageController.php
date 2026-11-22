<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\StockMovement;
use App\Services\Audit;
use App\Services\TenantContext;
use App\Services\TenantRoles;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class PilotageController extends Controller
{
    private const SECTIONS = ['direction', 'sales', 'finance', 'logistics', 'alerts'];

    private function role(Request $request): string
    {
        return app(TenantRoles::class)->role($request->user())?->name
            ?? abort(403, 'Aucun rôle autorisé.');
    }

    private function allowed(Request $request, string $section): string
    {
        Gate::authorize('dashboardRead');
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        $role = $this->role($request);
        $roles = match ($section) {
            'direction', 'finance' => ['admin', 'commercial', 'reader'],
            'sales' => ['admin', 'commercial', 'reader'],
            'logistics' => ['admin', 'stock', 'reader'],
            'alerts' => ['admin', 'commercial', 'stock', 'reader'],
        };
        abort_unless(in_array($role, $roles, true), 403);

        return $role;
    }

    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);
        $from = CarbonImmutable::parse($data['from'] ?? now()->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($data['to'] ?? now()->toDateString());
        abort_if($from->diffInDays($to) > 366, 422, 'Période supérieure à 366 jours.');

        return [$from->toDateString(), $to->toDateString()];
    }

    private function invoiceQuery(string $from, string $to)
    {
        return Invoice::query()->whereDate('issued_at', '>=', $from)
            ->whereDate('issued_at', '<=', $to);
    }

    private function cards(array $values): array
    {
        return collect($values)->map(fn ($value, $key) => [
            'key' => $key, 'value' => $value,
        ])->values()->all();
    }

    public function show(Request $request, string $section)
    {
        $role = $this->allowed($request, $section);
        [$from, $to] = $this->period($request);
        $company = app(TenantContext::class)->requireId();
        $notes = [];
        $rows = [];
        $cards = [];

        if ($section === 'direction') {
            $invoices = $this->invoiceQuery($from, $to)->whereIn('status', ['issued','paid']);
            $cards = $this->cards([
                'issued_invoices_ht_cents' => (int) (clone $invoices)->sum('subtotal_cents'),
                'issued_invoices_ttc_cents' => (int) (clone $invoices)->sum('total_cents'),
                'issued_invoices_count' => (clone $invoices)->count(),
                'validated_orders_count' => Order::where('status', 'validated')
                    ->whereDate('validated_at', '>=', $from)->whereDate('validated_at', '<=', $to)->count(),
            ]);
            // Sales documents have no establishment_id in the current schema.
            // Do NOT allocate company revenue to hotels or invent ADR/RevPAR.
            $rows = DB::table('hotel_properties')->where('company_id', $company)
                ->select('id', 'code', 'name')->orderBy('name')->limit(100)->get()->map(function ($hotel) use ($company, $from, $to) {
                    $bookings = DB::table('hotel_bookings')->where('company_id', $company)
                        ->where('hotel_id', $hotel->id)->whereNotIn('status', ['draft', 'cancelled', 'no_show'])
                        ->where('arrival_date', '<=', $to)->where('departure_date', '>', $from);
                    return [
                        'id' => $hotel->id, 'code' => $hotel->code, 'name' => $hotel->name,
                        'bookings_in_period' => (clone $bookings)->count(),
                        'adr_cents' => null, 'revpar_cents' => null,
                    ];
                })->all();
            $notes[] = 'Agrégats financiers au niveau entreprise : aucune allocation aux établissements sans clé métier.';
            $notes[] = 'ADR, RevPAR, pickup et prévisions indisponibles : recettes et historique tarifaire hôtel absents.';
            $previousFrom = CarbonImmutable::parse($from)->subYear()->toDateString();
            $previousTo = CarbonImmutable::parse($to)->subYear()->toDateString();
            $previous = $this->invoiceQuery($previousFrom, $previousTo)->whereIn('status', ['issued','paid']);
            $cards[] = ['key' => 'prior_year_invoices_ht_cents', 'value' => (int) $previous->sum('subtotal_cents')];
        } elseif ($section === 'sales') {
            $quotes = Quote::query()->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
            $orders = Order::query()->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to);
            $issued = $this->invoiceQuery($from, $to)->whereIn('status', ['issued', 'paid']);
            $cards = $this->cards([
                'quotes_created' => (clone $quotes)->count(),
                'quotes_converted' => (clone $quotes)->where('status', 'converted')->count(),
                'orders_created' => (clone $orders)->count(),
                'orders_validated' => (clone $orders)->where('status', 'validated')->count(),
                'invoices_issued' => (clone $issued)->count(),
                'invoices_ht_cents' => (clone $issued)->sum('subtotal_cents'),
            ]);
            $rows = (clone $orders)->select('id','number','status','subtotal_cents','total_cents','created_at')
                ->latest('id')->limit(50)->get()->toArray();
            $notes[] = 'Entonnoir par cohortes de création et émission : les volumes ne sont pas des conversions individuelles reconstituées.';
            $notes[] = 'Le modèle actuel ne stocke pas les prospects ni les échéances commerciales.';
        } elseif ($section === 'finance') {
            $invoices = $this->invoiceQuery($from, $to);
            $cards = $this->cards([
                'issued_ht_cents' => (clone $invoices)->whereIn('status', ['issued','paid'])->sum('subtotal_cents'),
                'issued_ttc_cents' => (clone $invoices)->whereIn('status', ['issued','paid'])->sum('total_cents'),
                'unpaid_ttc_cents' => (clone $invoices)->where('status','issued')->sum('total_cents'),
                'paid_ttc_cents' => (clone $invoices)->where('status','paid')->sum('total_cents'),
                'cancelled_count' => (clone $invoices)->where('status','cancelled')->count(),
            ]);
            $rows = (clone $invoices)->select('id','number','status','total_cents','issued_at','paid_at')
                ->latest('id')->limit(50)->get()->toArray();
            $notes[] = 'Les factures émises sur la période sont filtrées par date d’émission ; aucun montant de commande ne compte comme paiement.';
            $notes[] = 'DSO, échéances contractuelles, mouvements bancaires et écritures comptables indisponibles dans le schéma actuel.';
        } elseif ($section === 'logistics') {
            $cards = $this->cards([
                'product_count' => Product::count(),
                'physical_units' => (int) Product::sum('stock'),
                'low_stock_count' => Product::whereColumn('stock','<=','alert_threshold')->count(),
                'movements_in_period' => StockMovement::whereDate('created_at','>=',$from)
                    ->whereDate('created_at','<=',$to)->count(),
            ]);
            $rows = Product::whereColumn('stock','<=','alert_threshold')
                ->select('id','reference','name','stock','alert_threshold')->orderBy('stock')->limit(100)->get()->toArray();
            $notes[] = 'Physique calculé depuis le stock courant : les réservations et disponibles ATP ne sont pas persistés ; aucune estimation.';
            $notes[] = 'Les mouvements de stock sont une mesure de flux distincte des factures.';
        } else {
            $rules = DB::table('pilotage_alert_rules')->where('company_id',$company)
                ->where('active',true)->whereDate('effective_from','<=',now()->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to','>=',now()->toDateString()))
                ->orderBy('id')->limit(100)->get();
            $list = [];
            foreach ($rules as $rule) {
                if ($rule->metric === 'low_stock' && in_array($role,['admin','stock','reader'],true)) {
                    $entities = Product::where('stock','<=',$rule->threshold)
                        ->select('id','reference','name','stock')->limit(100)->get();
                    foreach ($entities as $p) $list[] = [
                        'rule_id'=>$rule->id,'rule'=>$rule->name,'entity_type'=>'product',
                        'entity_id'=>$p->id,'label'=>$p->name.' · '.$p->reference,
                        'observed'=>$p->stock,'threshold'=>$rule->threshold,
                    ];
                }
                if ($rule->metric === 'unpaid_invoice_age_days' && in_array($role,['admin','commercial','reader'],true)) {
                    $cutoff = now()->subDays($rule->threshold);
                    $entities = Invoice::where('status','issued')->whereNotNull('issued_at')
                        ->where('issued_at','<=',$cutoff)->select('id','number','issued_at')->limit(100)->get();
                    foreach ($entities as $invoice) $list[] = [
                        'rule_id'=>$rule->id,'rule'=>$rule->name,'entity_type'=>'invoice',
                        'entity_id'=>$invoice->id,'label'=>$invoice->number,
                        'observed'=>$invoice->issued_at?->diffInDays(now()),'threshold'=>$rule->threshold,
                    ];
                }
            }
            $acks = DB::table('pilotage_alert_acknowledgements')->where('company_id',$company)
                ->whereIn('rule_id',$rules->pluck('id')->all())->get()
                ->keyBy(fn ($a) => $a->rule_id.':'.$a->entity_type.':'.$a->entity_id);
            $rows = array_slice(array_map(function ($r) use ($acks) {
                $key = $r['rule_id'].':'.$r['entity_type'].':'.$r['entity_id'];
                $r['acknowledged_at'] = $acks->get($key)?->acknowledged_at;
                return $r;
            },$list),0,200);
            $cards = $this->cards([
                'rules_count'=>$rules->count(), 'alerts_count'=>count($rows),
                'unacknowledged_count'=>count(array_filter($rows,fn ($r)=>!$r['acknowledged_at'])),
            ]);
            $notes[] = 'Alertes calculées à la date courante, indépendamment du filtre de période ; un accusé de réception ne clôture pas le défaut sous-jacent.';
            $notes[] = 'Chaque règle est datée ; l’escalade automatique et les objectifs budgétaires sont non disponibles.';
        }
        return response()->json([
            'section'=>$section, 'from'=>$from, 'to'=>$to,
            'scope'=>'Entreprise active uniquement', 'cards'=>$cards, 'rows'=>$rows,
            'notes'=>$notes, 'row_limit'=>($section === 'alerts' ? 200 : 100),
        ])->header('Cache-Control','no-store');
    }

    public function rules(Request $request)
    {
        $this->allowed($request,'alerts');
        abort_unless($this->role($request)==='admin',403);
        return DB::table('pilotage_alert_rules')->where('company_id',app(TenantContext::class)->requireId())
            ->orderByDesc('id')->limit(100)->get();
    }

    public function createRule(Request $request)
    {
        $this->allowed($request,'alerts');
        abort_unless($this->role($request)==='admin',403);
        $company=app(TenantContext::class)->requireId();
        $d=$request->validate([
            'name'=>'required|string|min:3|max:120',
            'metric'=>['required',Rule::in(['low_stock','unpaid_invoice_age_days'])],
            'threshold'=>'required|integer|min:0|max:1000000',
            'effective_from'=>'required|date_format:Y-m-d',
            'effective_to'=>'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'owner_id'=>['required','integer',Rule::exists('company_user','user_id')->where('company_id',$company)],
        ]);
        return DB::transaction(function () use ($d,$company,$request) {
            $id=DB::table('pilotage_alert_rules')->insertGetId([
                ...$d,'company_id'=>$company,'active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            Audit::record('pilotage.alert_rule_created',null,['rule_id'=>$id,'metric'=>$d['metric']]);
            return response()->json(['id'=>$id],201);
        },3);
    }

    public function acknowledge(Request $request)
    {
        $this->allowed($request,'alerts');
        abort_unless($this->role($request)==='admin',403);
        $d=$request->validate([
            'rule_id'=>'required|integer|min:1',
            'entity_type'=>['required',Rule::in(['product','invoice'])],
            'entity_id'=>'required|integer|min:1',
            'comment'=>'required|string|min:5|max:500',
        ]);
        $company=app(TenantContext::class)->requireId();
        return DB::transaction(function () use ($d,$company,$request) {
            $rule=DB::table('pilotage_alert_rules')->where('company_id',$company)
                ->where('id',$d['rule_id'])->where('active',true)->lockForUpdate()->first();
            abort_unless($rule,404);
            abort_unless($rule->effective_from <= now()->toDateString()
                && ($rule->effective_to === null || $rule->effective_to >= now()->toDateString()),409);
            abort_unless(($rule->metric==='low_stock' && $d['entity_type']==='product')
                || ($rule->metric==='unpaid_invoice_age_days' && $d['entity_type']==='invoice'),422);
            if ($d['entity_type']==='product') {
                abort_unless(Product::whereKey($d['entity_id'])->where('stock','<=',$rule->threshold)->exists(),404);
            } else {
                abort_unless(Invoice::whereKey($d['entity_id'])->where('status','issued')
                    ->where('issued_at','<=',now()->subDays($rule->threshold))->exists(),404);
            }
            $inserted=DB::table('pilotage_alert_acknowledgements')->insertOrIgnore([
                'company_id'=>$company,'rule_id'=>$rule->id,
                'entity_type'=>$d['entity_type'],'entity_id'=>$d['entity_id'],
                'product_id'=>$d['entity_type']==='product' ? $d['entity_id'] : null,
                'invoice_id'=>$d['entity_type']==='invoice' ? $d['entity_id'] : null,
                'acknowledged_by'=>$request->user()->id,'acknowledged_at'=>now(),
                'comment'=>$d['comment'],
            ]);
            if ($inserted) Audit::record('pilotage.alert_acknowledged',null,[
                'rule_id'=>$rule->id,'entity_type'=>$d['entity_type'],'entity_id'=>$d['entity_id'],
            ]);
            return response()->json(['acknowledged'=>true,'already_acknowledged'=>!$inserted]);
        },3);
    }
}
