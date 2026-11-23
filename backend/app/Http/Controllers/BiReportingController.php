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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * M13: allowlisted BI analytics on canonical ERP documents. No free-form SQL.
 * Deliberately unavailable: bank payments, GL entries, credit notes, supplier
 * receipt punctuality, historical stock opening balances and site-attributed sales.
 */
final class BiReportingController extends Controller
{
    private const DATASETS = [
        'sales' => ['number', 'status', 'subtotal_cents', 'total_cents', 'created_at'],
        'finance' => ['number', 'status', 'total_cents', 'issued_at', 'paid_at'],
        'stock' => ['reference', 'name', 'category', 'stock', 'alert_threshold'],
        'suppliers' => ['name', 'email'],
    ];

    private function role(Request $r): string
    {
        Gate::authorize('dashboardRead');
        return app(TenantRoles::class)->role($r->user())?->name
            ?? abort(403, 'Rôle entreprise inexistant.');
    }

    private function authorizeDataset(Request $r, string $dataset, bool $write = false): string
    {
        abort_unless(array_key_exists($dataset, self::DATASETS), 404);
        $role = $this->role($r);
        $allowed = match ($dataset) {
            'sales', 'finance' => ['admin', 'commercial', 'reader'],
            'stock', 'suppliers' => ['admin', 'stock', 'reader'],
        };
        if ($dataset === 'suppliers') $allowed = ['admin', 'commercial', 'reader'];
        abort_unless(in_array($role, $allowed, true), 403);
        if ($write) abort_unless($role === 'admin', 403);

        return $role;
    }

    private function dates(Request $r): array
    {
        $d = $r->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'establishment_id' => 'prohibited',
            'company_id' => 'prohibited',
        ]);
        $from = CarbonImmutable::parse($d['from'] ?? now()->startOfMonth()->toDateString());
        $to = CarbonImmutable::parse($d['to'] ?? now()->toDateString());
        abort_if($from->greaterThan($to) || $from->diffInDays($to) > 366, 422, 'Période limitée à 366 jours.');

        return [$from->toDateString(), $to->toDateString()];
    }

    private function documentInvoices(string $from, string $to)
    {
        return Invoice::query()->whereIn('status', ['issued', 'paid'])
            ->whereDate('issued_at', '>=', $from)->whereDate('issued_at', '<=', $to);
    }

    /**
     * Only fields in DATASETS are returned. All native models have mandatory
     * tenant global scopes; also bind explicit company on raw query-builder SQL.
     */
    private function dataset(Request $r, string $name, string $from, string $to): array
    {
        $role = $this->authorizeDataset($r, $name);
        $company = app(TenantContext::class)->requireId();
        $notes = ['Périmètre : entreprise active, dates civiles et devise native EUR du modèle actuel.',
            'Les axes établissement et taux de change ne sont pas disponibles sans source canonique.'];
        $cards = [];
        $rows = [];
        if ($name === 'sales') {
            $issued = $this->documentInvoices($from, $to);
            $cards = [
                'quotes_count' => Quote::whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->count(),
                'validated_orders_count' => Order::where('status', 'validated')->whereDate('validated_at', '>=', $from)->whereDate('validated_at', '<=', $to)->count(),
                'issued_invoices_count' => (clone $issued)->count(),
                'revenue_ht_cents' => (int) (clone $issued)->sum('subtotal_cents'),
                'revenue_ttc_cents' => (int) (clone $issued)->sum('total_cents'),
            ];
            $rows = (clone $issued)->orderBy('id')->limit(200)
                ->get(['number', 'status', 'subtotal_cents', 'total_cents', 'created_at'])->toArray();
            $notes[] = 'Revenu réalisé : exclusivement factures émises/payées, jamais ajoutées aux commandes.';
        } elseif ($name === 'finance') {
            $issued = $this->documentInvoices($from, $to);
            $cards = [
                'gross_invoiced_ttc_cents' => (int) (clone $issued)->sum('total_cents'),
                'outstanding_declared_ttc_cents' => (int) (clone $issued)->where('status', 'issued')->sum('total_cents'),
                'marked_paid_ttc_cents' => (int) (clone $issued)->where('status', 'paid')->sum('total_cents'),
                'credit_notes_ttc_cents' => null,
                'net_revenue_ttc_cents' => null,
                'actual_cash_ttc_cents' => null,
                'margin_cents' => null,
            ];
            $rows = (clone $issued)->orderBy('id')->limit(200)
                ->get(['number', 'status', 'total_cents', 'issued_at', 'paid_at'])->toArray();
            $notes[] = 'Avoirs, paiements bancaires, écritures de grand livre, coût des ventes et marge : non disponibles. Payée est un état déclaratif, pas un rapprochement bancaire.';
        } elseif ($name === 'stock') {
            $cards = [
                'products_count' => Product::count(),
                'units_on_hand' => (int) Product::sum('stock'),
                'low_stock_count' => Product::whereColumn('stock', '<=', 'alert_threshold')->count(),
                'movement_count_period' => StockMovement::whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->count(),
                'historical_stock_balance' => null,
            ];
            $rows = Product::orderBy('id')->limit(200)
                ->get(['reference', 'name', 'category', 'stock', 'alert_threshold'])->toArray();
            $notes[] = 'Stock courant uniquement. Reconstitution historique indisponible sans soldes d’ouverture datés et réservations.';
        } else {
            $cards = [
                'suppliers_count' => DB::table('suppliers')->where('company_id', $company)->count(),
                'on_time_delivery_rate' => null,
                'quality_acceptance_rate' => null,
            ];
            // Never disclose supplier personal contact details in analytics.
            $rows = DB::table('suppliers')->where('company_id', $company)
                ->orderBy('id')->limit(200)->get(['name'])->map(fn ($s) => (array) $s)->all();
            $notes[] = 'Réceptions et dates promises non modélisées : ponctualité et qualité non calculables.';
        }

        return [
            'dataset' => $name, 'from' => $from, 'to' => $to, 'company_id' => $company,
            'cards' => $cards, 'columns' => $this->columnsFor($name, $role),
            'rows' => $rows, 'notes' => $notes, 'row_limit' => 200,
            'cutoff_at' => now()->toIso8601String(), 'classification' => 'INTERNAL',
        ];
    }

    private function columnsFor(string $dataset, string $role): array
    {
        $cols = self::DATASETS[$dataset];
        // Data minimisation: all supplier contacts are private, for every role.
        return $dataset === 'suppliers' ? ['name'] : $cols;
    }

    public function show(Request $r, string $dataset)
    {
        [$from, $to] = $this->dates($r);
        return response()->json($this->dataset($r, $dataset, $from, $to))
            ->header('Cache-Control', 'no-store');
    }

    public function catalog(Request $r)
    {
        $this->role($r);
        return response()->json(collect(array_keys(self::DATASETS))
            ->filter(function ($dataset) use ($r) {
                try { $this->authorizeDataset($r, $dataset); return true; }
                catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { return false; }
            })->map(fn ($name) => ['dataset' => $name, 'columns' => $this->columnsFor($name, '')])->values()->all())
            ->header('Cache-Control', 'no-store');
    }

    public function definitions(Request $r)
    {
        abort_unless($this->role($r) === 'admin', 403);
        $company = app(TenantContext::class)->requireId();
        return DB::table('bi_report_definitions')->where('company_id', $company)
            ->select('id','report_uid','title','version','dataset','columns','filters','created_at')
            ->orderByDesc('id')->paginate(20);
    }

    public function createDefinition(Request $r)
    {
        abort_unless($this->role($r) === 'admin', 403);
        $d = $r->validate([
            'title' => 'required|string|min:3|max:150',
            'dataset' => ['required', Rule::in(array_keys(self::DATASETS))],
            'columns' => 'required|array|min:1|max:10',
            'columns.*' => 'required|string|max:80|distinct',
            'filters' => 'nullable|array',
            'filters.from' => 'nullable|date_format:Y-m-d',
            'filters.to' => 'nullable|date_format:Y-m-d|after_or_equal:filters.from',
            'report_uid' => 'nullable|uuid',
            'version' => 'prohibited',
            'company_id' => 'prohibited',
            'idempotency_key' => 'required|string|min:8|max:128',
        ]);
        $this->authorizeDataset($r, $d['dataset'], true);
        abort_unless(count(array_diff($d['columns'], $this->columnsFor($d['dataset'], 'admin'))) === 0, 422, 'Colonne non publiée.');
        $filters = $d['filters'] ?? [];
        abort_unless(count(array_diff(array_keys($filters), ['from','to'])) === 0, 422, 'Filtre non publié.');
        $company = app(TenantContext::class)->requireId();
        $payload = ['title'=>$d['title'], 'dataset'=>$d['dataset'], 'columns'=>$d['columns'], 'filters'=>$filters, 'report_uid'=>$d['report_uid'] ?? null];
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($r, $d, $company, $payload, $hash) {
            $q = DB::table('bi_report_definitions')->where('company_id',$company);
            $existing = (clone $q)->where('idempotency_key',$d['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Clé idempotente déjà utilisée avec un contenu différent.');
                return response()->json(['id'=>$existing->id,'report_uid'=>$existing->report_uid,'version'=>$existing->version,'replayed'=>true]);
            }
            $uid = $d['report_uid'] ?? (string) Str::uuid();
            $previous = (clone $q)->where('report_uid', $uid)->orderByDesc('version')->lockForUpdate()->first();
            if ($previous) abort_unless($previous->dataset === $d['dataset'], 409, 'Modification de dataset interdite entre révisions.');
            $version = $previous ? $previous->version + 1 : 1;
            $id = DB::table('bi_report_definitions')->insertGetId([
                'company_id'=>$company,'report_uid'=>$uid,'version'=>$version,
                'title'=>$d['title'],'dataset'=>$d['dataset'],
                'columns'=>json_encode($d['columns'], JSON_THROW_ON_ERROR),
                'filters'=>json_encode($payload['filters'], JSON_THROW_ON_ERROR),
                'created_by'=>$r->user()->id, 'idempotency_key'=>$d['idempotency_key'],
                'request_hash'=>$hash, 'created_at'=>now(),'updated_at'=>now(),
            ]);
            Audit::record('bi.definition.created',null,['definition_id'=>$id,'version'=>$version]);
            return response()->json(['id'=>$id,'report_uid'=>$uid,'version'=>$version], 201);
        }, 3);
    }

    private function definition(Request $r, int $id): object
    {
        abort_unless($this->role($r) === 'admin', 403);
        $d = DB::table('bi_report_definitions')
            ->where('company_id', app(TenantContext::class)->requireId())->where('id', $id)->first();
        abort_unless($d, 404);
        $this->authorizeDataset($r,$d->dataset,true);
        return $d;
    }

    public function run(Request $r, int $id)
    {
        $d = $this->definition($r, $id);
        $filters = json_decode($d->filters, true, 512, JSON_THROW_ON_ERROR);
        $request = Request::create('/api/analytics/'.$d->dataset, 'GET', $filters);
        $request->setUserResolver(fn () => $r->user());
        [$from,$to] = $this->dates($request);
        $result = $this->dataset($r,$d->dataset,$from,$to);
        $columns = json_decode($d->columns,true,512,JSON_THROW_ON_ERROR);
        $result['rows'] = array_map(fn ($row) => array_intersect_key($row,array_flip($columns)), $result['rows']);
        $result['columns'] = $columns;
        $result['report_uid'] = $d->report_uid;
        $result['report_version'] = $d->version;
        DB::transaction(function () use ($r, $id, $result) {
            DB::table('bi_report_runs')->insert([
                'company_id'=>app(TenantContext::class)->requireId(), 'definition_id'=>$id,
                'executed_by'=>$r->user()->id,'row_count'=>count($result['rows']),
                'cutoff_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            Audit::record('bi.report.executed',null,['definition_id'=>$id,'row_count'=>count($result['rows'])]);
        });
        return response()->json($result)->header('Cache-Control','no-store');
    }

    public function exports(Request $r)
    {
        abort_unless($this->role($r) === 'admin', 403);
        return DB::table('bi_report_exports')
            ->where('company_id',app(TenantContext::class)->requireId())
            ->where('created_by',$r->user()->id)
            ->select('id','dataset','classification','row_count','sha256','period_from','period_to','expires_at','created_at')
            ->orderByDesc('id')->paginate(20);
    }

    public function createExport(Request $r)
    {
        abort_unless($this->role($r) === 'admin', 403);
        $d = $r->validate([
            'dataset'=>['required',Rule::in(array_keys(self::DATASETS))],
            'from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from',
            'definition_id'=>'nullable|integer|min:1',
            'company_id'=>'prohibited',
        ]);
        [$from,$to] = $this->dates($r);
        $this->authorizeDataset($r,$d['dataset'],true);
        $definition = null;
        if (!empty($d['definition_id'])) {
            $definition=$this->definition($r,(int)$d['definition_id']);
            abort_unless($definition->dataset===$d['dataset'],422,'Dataset incompatible.');
        }
        $data = $this->dataset($r,$d['dataset'],$from,$to);
        $cols = $definition ? json_decode($definition->columns,true,512,JSON_THROW_ON_ERROR) : $data['columns'];
        $rows = array_map(fn ($row) => array_intersect_key($row,array_flip($cols)),$data['rows']);
        $blob = json_encode(['columns'=>$cols,'rows'=>$rows,'cards'=>$data['cards']],JSON_THROW_ON_ERROR);
        $company=app(TenantContext::class)->requireId();
        $id=DB::transaction(function () use ($r,$d,$cols,$from,$to,$rows,$blob,$company,$definition) {
            $id=DB::table('bi_report_exports')->insertGetId([
                'company_id'=>$company,'created_by'=>$r->user()->id,
                'dataset'=>$d['dataset'],'classification'=>'INTERNAL',
                'period_from'=>$from,'period_to'=>$to,
                'definition_id'=>$definition?->id,
                'columns'=>json_encode($cols,JSON_THROW_ON_ERROR),
                'snapshot'=>$blob, 'row_count'=>count($rows),
                'sha256'=>hash('sha256',$blob),'cutoff_at'=>now(),
                'expires_at'=>now()->addHour(),'created_at'=>now(),'updated_at'=>now(),
            ]);
            Audit::record('bi.export.created',null,['export_id'=>$id,'dataset'=>$d['dataset'],'row_count'=>count($rows)]);
            return $id;
        },3);
        return response()->json(['id'=>$id,'row_count'=>count($rows),'sha256'=>hash('sha256',$blob),'expires_in_seconds'=>3600],201);
    }

    public function download(Request $r, int $id)
    {
        abort_unless($this->role($r)==='admin',403);
        $tenant=app(TenantContext::class)->requireId();
        $e=DB::table('bi_report_exports')->where('company_id',$tenant)->where('created_by',$r->user()->id)->where('id',$id)->first();
        abort_unless($e,404);
        abort_if(now()->greaterThanOrEqualTo($e->expires_at),410,'Export expiré.');
        $this->authorizeDataset($r,$e->dataset,true);
        abort_unless(hash_equals($e->sha256,hash('sha256',$e->snapshot)),409,'Intégrité export invalide.');
        $payload=json_decode($e->snapshot,true,512,JSON_THROW_ON_ERROR);
        $csv=fopen('php://temp','w+');
        fputcsv($csv,$payload['columns']);
        foreach ($payload['rows'] as $row) {
            $line=[];
            foreach ($payload['columns'] as $column) {
                $value=(string)($row[$column]??'');
                // Spreadsheet formula injection defense, preserving textual values.
                if (preg_match('/^[\\s]*[=+@\\-\\t\\r]/u',$value)) $value="'".$value;
                $line[]=$value;
            }
            fputcsv($csv,$line);
        }
        rewind($csv);
        $body=stream_get_contents($csv);
        fclose($csv);
        Audit::record('bi.export.downloaded',null,['export_id'=>$id]);
        return response($body)->header('Content-Type','text/csv; charset=utf-8')
            ->header('Content-Disposition','attachment; filename="atlas-bi-'.$id.'.csv"')
            ->header('Cache-Control','private, no-store');
    }
}
