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
            // Company sales invoices carry no hotel_id: never allocate their
            // value to a property. Hotel room-nights are occupancy only.
            $hotels = DB::table('hotel_properties')->where('company_id', $company)
                ->select('id', 'code', 'name')->orderBy('name')->limit(100)->get();
            $hotelIds = $hotels->pluck('id')->all();
            $roomCounts = DB::table('hotel_rooms')->where('company_id',$company)
                ->whereIn('hotel_id',$hotelIds)->selectRaw('hotel_id, count(*) AS total')
                ->groupBy('hotel_id')->pluck('total','hotel_id');
            $toExclusive = CarbonImmutable::parse($to)->addDay()->toDateString();
            $hotelBookings = DB::table('hotel_bookings')->where('company_id',$company)
                ->whereIn('hotel_id',$hotelIds)
                ->whereIn('status',['confirmed','checked_in','checked_out'])
                ->where('arrival_date','<',$toExclusive)
                ->where('departure_date','>',$from)
                ->select('hotel_id','arrival_date','departure_date','checked_out_on')
                ->limit(50001)->get();
            $hotelDataComplete = $hotelBookings->count() <= 50000;
            $nightsByHotel = [];
            $bookingsByHotel = [];
            if ($hotelDataComplete) {
                foreach ($hotelBookings as $booking) {
                    $hotelId = (int) $booking->hotel_id;
                    $end = $booking->checked_out_on && $booking->checked_out_on < $booking->departure_date
                        ? $booking->checked_out_on : $booking->departure_date;
                    $start = max($from, $booking->arrival_date);
                    $end = min($toExclusive, $end);
                    $nights = $start < $end ? (int) CarbonImmutable::parse($start)
                        ->diffInDays(CarbonImmutable::parse($end)) : 0;
                    $nightsByHotel[$hotelId] = ($nightsByHotel[$hotelId] ?? 0) + $nights;
                    $bookingsByHotel[$hotelId] = ($bookingsByHotel[$hotelId] ?? 0) + 1;
                }
            }
            $days = (int) CarbonImmutable::parse($from)
                ->diffInDays(CarbonImmutable::parse($toExclusive));
            $rows = $hotels->map(function ($hotel) use ($roomCounts,$nightsByHotel,$bookingsByHotel,$days,$hotelDataComplete) {
                $capacity = ((int) ($roomCounts[$hotel->id] ?? 0)) * $days;
                $nights = $hotelDataComplete ? ($nightsByHotel[$hotel->id] ?? 0) : null;
                return [
                    'id'=>$hotel->id,'code'=>$hotel->code,'name'=>$hotel->name,
                    'bookings_in_period'=>$hotelDataComplete ? ($bookingsByHotel[$hotel->id] ?? 0) : null,
                    'rooms_current'=>(int) ($roomCounts[$hotel->id] ?? 0),
                    'theoretical_room_nights'=>$capacity,
                    'occupied_room_nights'=>$nights,
                    'indicative_occupancy_pct'=>$capacity > 0 && $nights !== null
                        ? round(100 * $nights / $capacity,1) : null,
                    'adr_cents'=>null,'revpar_cents'=>null,
                ];
            })->all();
            $notes[] = 'Occupation indicative : nuits des réservations confirmées/en cours/terminées divisées par chambres actuellement référencées × jours. Les indisponibilités historiques et inventaires de chambres anciens ne sont pas encore reconstituables.';
            if (!$hotelDataComplete) $notes[] = 'Détail hôtel incomplet au-delà de 50 000 réservations ; nuitées et occupation volontairement indisponibles.';
            $notes[] = 'Les ventes facturées restent au niveau société : aucune allocation aux hôtels sans clé de rattachement sur les pièces.';
            $notes[] = 'ADR, RevPAR, pickup et prévisions indisponibles sans revenu hôtelier, instantanés de réservation et historique tarifaire fiables.';
            $previousFrom = CarbonImmutable::parse($from)->subYear()->toDateString();
            $previousTo = CarbonImmutable::parse($to)->subYear()->toDateString();
            $previous = $this->invoiceQuery($previousFrom, $previousTo)->whereIn('status', ['issued','paid']);
            $cards[] = ['key' => 'prior_year_invoices_ht_cents', 'value' => (int) $previous->sum('subtotal_cents')];
            $target = DB::table('pilotage_target_versions')->where('company_id',$company)
                ->where('metric','issued_ht_cents')
                ->where('period_from',$from)->where('period_to',$to)
                ->orderByDesc('version')->first();
            if ($target) {
                $actual = (int) (clone $invoices)->sum('subtotal_cents');
                $cards[] = ['key'=>'target_issued_ht_cents','value'=>(int) $target->target_value];
                $cards[] = ['key'=>'target_issued_ht_delta_cents',
                    'value'=>$actual-(int) $target->target_value];
                $cards[] = ['key'=>'target_version','value'=>(int) $target->version];
            } else $notes[] = 'Aucun objectif publié pour les dates exactes sélectionnées : comparaison budgétaire indisponible.';

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
                ->when($role === 'stock',fn ($q) => $q->where('metric','low_stock'))
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
                ->whereIn('rule_id',$rules->pluck('id')->all())->limit(10000)->get()
                ->keyBy(fn ($a) => $a->rule_id.':'.$a->entity_type.':'.$a->entity_id);
            $events = DB::table('pilotage_alert_events')->where('company_id',$company)
                ->whereIn('rule_id',$rules->pluck('id')->all())->limit(20000)->get()
                ->groupBy(fn ($e) => $e->rule_id.':'.$e->entity_type.':'.$e->entity_id);
            $rowsByKey = [];
            foreach ($list as $row) {
                $key = $row['rule_id'].':'.$row['entity_type'].':'.$row['entity_id'];
                $row['acknowledged_at'] = $acks->get($key)?->acknowledged_at;
                $row['is_active'] = true;
                $row['escalated_at'] = $events->get($key)?->firstWhere('action','escalated')?->created_at;
                $row['resolved_at'] = $events->get($key)?->firstWhere('action','resolved')?->created_at;
                $rowsByKey[$key] = $row;
            }
            // Keep acknowledged but no-longer-anomalous entries visible until
            // a human records the resolution. An ack is never a resolution.
            foreach ($acks as $key => $ack) {
                if (isset($rowsByKey[$key]) || $events->get($key)?->firstWhere('action','resolved')) continue;
                $rule = $rules->firstWhere('id',$ack->rule_id);
                if (!$rule) continue;
                $entity = $ack->entity_type==='product'
                    ? Product::whereKey($ack->entity_id)->select('id','name')->first()
                    : Invoice::whereKey($ack->entity_id)->select('id','number')->first();
                if (!$entity) continue;
                $rowsByKey[$key] = [
                    'rule_id'=>$ack->rule_id,'rule'=>$rule->name,
                    'entity_type'=>$ack->entity_type,'entity_id'=>$ack->entity_id,
                    'label'=>$ack->entity_type==='product' ? $entity->name : $entity->number,
                    'observed'=>null,'threshold'=>$rule->threshold,'acknowledged_at'=>$ack->acknowledged_at,
                    'is_active'=>false,'escalated_at'=>$events->get($key)?->firstWhere('action','escalated')?->created_at,
                    'resolved_at'=>null,
                ];
            }
            $rows = array_slice(array_values($rowsByKey),0,200);
            $cards = $this->cards([
                'rules_count'=>$rules->count(), 'alerts_count'=>count($rows),
                'unacknowledged_count'=>count(array_filter($rows,fn ($r)=>!$r['acknowledged_at'])),
                'pending_resolution_count'=>count(array_filter($rows,fn ($r)=>$r['acknowledged_at'] && !$r['is_active'] && !$r['resolved_at'])),
            ]);
            $notes[] = 'Alertes calculées à la date courante, indépendamment du filtre de période ; un accusé de réception ne clôture pas le défaut sous-jacent.';
            $notes[] = 'Chaque règle est datée ; l’escalade automatique et les objectifs budgétaires sont non disponibles.';
        }
        return response()->json([
            'section'=>$section, 'from'=>$from, 'to'=>$to,
            'scope'=>'Entreprise active uniquement', 'cards'=>$cards, 'rows'=>$rows,
            'notes'=>$notes, 'row_limit'=>($section === 'alerts' ? 200 : 100),
            'generated_at'=>now()->toIso8601String(), 'currency'=>'EUR', 'data_status'=>'live',
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

    /** Same authorized tenant, role and period as on-screen; never accepts tenant/site IDs. */
    public function export(Request $request, string $section)
    {
        $response = $this->show($request, $section);
        $payload = $response->getData(true);
        $company = app(TenantContext::class)->requireId();
        $filename = "atlas-m01-{$section}-{$payload['from']}-{$payload['to']}.csv";
        $rows = $payload['rows'];
        $cards = $payload['cards'];
        // CSV-formula injection protection, including whitespace/control prefixes.
        $safe = static function ($value): string {
            if ($value === null) return 'Non disponible';
            $text = is_scalar($value) ? (string) $value : '';
            if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $text)) return "'".$text;
            return $text;
        };
        Audit::record('pilotage.export',null,['section'=>$section,'from'=>$payload['from'],'to'=>$payload['to']]);
        return response()->streamDownload(static function () use ($rows,$cards,$company,$payload,$safe) {
            $handle = fopen('php://output','w');
            fwrite($handle,"\xEF\xBB\xBF");
            fputcsv($handle,['ATLAS M01','Entreprise',$company,'Période',$payload['from'],$payload['to']],';');
            fputcsv($handle,['Données','EUR cents si suffixe _cents','Extrait le',$payload['generated_at']],';');
            fputcsv($handle,['Indicateur','Valeur'],' ; '[1]);
            foreach ($cards as $card) fputcsv($handle,[$safe($card['key']),$safe($card['value'])],';');
            fputcsv($handle,[],';');
            if ($rows) {
                $columns = array_keys($rows[0]);
                fputcsv($handle,$columns,';');
                foreach ($rows as $row) {
                    fputcsv($handle,array_map(fn ($column)=>$safe($row[$column] ?? null),$columns),';');
                }
            }
            fclose($handle);
        },$filename,['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store']);
    }

    /**
     * Append-only escalation and resolution history. Resolution is forbidden
     * while an alert's underlying anomaly is still present.
     */
    public function transitionAlert(Request $request)
    {
        $this->allowed($request,'alerts');
        abort_unless($this->role($request)==='admin',403);
        $d=$request->validate([
            'rule_id'=>'required|integer|min:1',
            'entity_type'=>['required',Rule::in(['product','invoice'])],
            'entity_id'=>'required|integer|min:1',
            'action'=>['required',Rule::in(['escalated','resolved'])],
            'reason'=>'required|string|min:10|max:500',
        ]);
        $company=app(TenantContext::class)->requireId();
        return DB::transaction(function () use ($d,$company,$request) {
            $rule=DB::table('pilotage_alert_rules')->where('company_id',$company)
                ->where('id',$d['rule_id'])->lockForUpdate()->first();
            abort_unless($rule,404);
            abort_unless(($rule->metric==='low_stock' && $d['entity_type']==='product')
                || ($rule->metric==='unpaid_invoice_age_days' && $d['entity_type']==='invoice'),422);
            $entity = $d['entity_type']==='product'
                ? Product::whereKey($d['entity_id'])->firstOrFail()
                : Invoice::whereKey($d['entity_id'])->firstOrFail();
            $anomaly = $d['entity_type']==='product'
                ? $entity->stock <= $rule->threshold
                : ($entity->status === 'issued' && $entity->issued_at !== null
                    && $entity->issued_at <= now()->subDays($rule->threshold));
            // Same action and entity is replay-safe. A different reason is a conflict.
            $existing = DB::table('pilotage_alert_events')->where('company_id',$company)
                ->where('rule_id',$rule->id)->where('entity_type',$d['entity_type'])
                ->where('entity_id',$d['entity_id'])->where('action',$d['action'])->first();
            if ($existing) {
                abort_if($existing->reason !== $d['reason'],409,'Événement déjà historisé avec un motif différent.');
                return response()->json(['id'=>$existing->id,'replayed'=>true]);
            }
            if ($d['action']==='escalated') {
                abort_unless($anomaly && $rule->active && $rule->effective_from <= now()->toDateString()
                    && ($rule->effective_to===null || $rule->effective_to >= now()->toDateString()),409,
                    'L’alerte doit être active pour une escalade.');
            } else {
                abort_if($anomaly,409,'L’anomalie est toujours présente : clôture refusée.');
                abort_unless(DB::table('pilotage_alert_acknowledgements')
                    ->where('company_id',$company)->where('rule_id',$rule->id)
                    ->where('entity_type',$d['entity_type'])->where('entity_id',$d['entity_id'])->exists(),
                    409,'Acquittement requis avant résolution.');
            }
            $id=DB::table('pilotage_alert_events')->insertGetId([
                'company_id'=>$company,'rule_id'=>$rule->id,
                'entity_type'=>$d['entity_type'],'entity_id'=>$d['entity_id'],
                'product_id'=>$d['entity_type']==='product' ? $d['entity_id'] : null,
                'invoice_id'=>$d['entity_type']==='invoice' ? $d['entity_id'] : null,
                'action'=>$d['action'],'reason'=>$d['reason'],
                'actor_id'=>$request->user()->id,'created_at'=>now(),
            ]);
            Audit::record('pilotage.alert.'.$d['action'],null,['rule_id'=>$rule->id,'event_id'=>$id]);
            return response()->json(['id'=>$id,'replayed'=>false],201);
        },3);
    }

}
