<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use App\Services\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * M01 management-only references. Targets represent management objectives,
 * not accounting entries, financial transactions or booked revenue.
 */
final class PilotageManagementController extends Controller
{
    private const METRICS = [
        'issued_ht_cents' => 'EUR_CENTS',
        'issued_invoice_count' => 'COUNT',
        'validated_order_count' => 'COUNT',
        'low_stock_count' => 'COUNT',
    ];

    private function authorizeRead(Request $request): string
    {
        Gate::authorize('dashboardRead');
        $role = app(TenantRoles::class)->role($request->user())?->name;
        abort_unless(in_array($role, ['admin', 'commercial', 'reader', 'stock'], true), 403);
        return $role;
    }

    private function authorizeWrite(Request $request): void
    {
        $this->authorizeRead($request);
        Gate::authorize('admin');
    }

    public function centers(Request $request)
    {
        $role=$this->authorizeRead($request);
        abort_unless(in_array($role,['admin','commercial','reader'],true),403);
        $company = app(TenantContext::class)->requireId();
        return response()->json(
            DB::table('pilotage_cost_centers')->where('company_id', $company)
                ->select('id','code','name','active','created_at')
                ->orderBy('code')->limit(200)->get()
        )->header('Cache-Control','no-store');
    }

    public function createCenter(Request $request)
    {
        $this->authorizeWrite($request);
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'code' => ['required','string','min:2','max:40','regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('pilotage_cost_centers')->where('company_id',$company)],
            'name' => 'required|string|min:3|max:160',
            'company_id' => 'prohibited', 'created_by' => 'prohibited',
            'site_id' => 'prohibited', 'hotel_id'=>'prohibited',
        ]);
        return DB::transaction(function () use ($request, $company, $data) {
            $id = DB::table('pilotage_cost_centers')->insertGetId([
                'company_id'=>$company, 'code'=>$data['code'], 'name'=>$data['name'],
                'active'=>true, 'created_by'=>$request->user()->id,
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
            Audit::record('pilotage.cost_center.created',null,['center_id'=>$id]);
            return response()->json(['id'=>$id],201);
        },3);
    }

    public function targets(Request $request)
    {
        $role = $this->authorizeRead($request);
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'from'=>'nullable|date_format:Y-m-d',
            'to'=>'nullable|date_format:Y-m-d|after_or_equal:from',
            'company_id'=>'prohibited', 'hotel_id'=>'prohibited', 'site_id'=>'prohibited',
        ]);
        $query = DB::table('pilotage_target_versions')->where('company_id',$company);
        if (!in_array($role,['admin','commercial','reader'],true)) {
            $query->where('metric','low_stock_count');
        }
        if (isset($data['from'])) $query->where('period_to','>=',$data['from']);
        if (isset($data['to'])) $query->where('period_from','<=',$data['to']);
        return response()->json($query->select('id','metric','unit','period_from','period_to',
                'target_value','version','reason','created_at')
            ->orderByDesc('id')->limit(200)->get())
            ->header('Cache-Control','no-store');
    }

    public function createTarget(Request $request)
    {
        $this->authorizeWrite($request);
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'metric'=>['required',Rule::in(array_keys(self::METRICS))],
            'period_from'=>'required|date_format:Y-m-d',
            'period_to'=>'required|date_format:Y-m-d|after_or_equal:period_from',
            'target_value'=>'required|integer|min:0|max:9000000000000000',
            'reason'=>'required|string|min:10|max:300',
            'company_id'=>'prohibited','version'=>'prohibited','unit'=>'prohibited','created_by'=>'prohibited',
            'site_id'=>'prohibited','hotel_id'=>'prohibited','currency'=>'prohibited',
        ]);
        abort_if(\Carbon\CarbonImmutable::parse($data['period_from'])
            ->diffInDays(\Carbon\CarbonImmutable::parse($data['period_to'])) > 366,
            422, 'Période supérieure à 366 jours.');
        return DB::transaction(function () use ($company,$data,$request) {
            // Serializes version allocation across users and DB workers.
            if (DB::getDriverName() === 'pgsql') {
                // PostgreSQL advisory lock avoids UPDATE privileges on companies.
                DB::selectOne('SELECT pg_advisory_xact_lock(?, ?) AS locked', [11701, $company]);
            } else {
                DB::table('companies')->where('id',$company)->lockForUpdate()->first();
            }
            $query = DB::table('pilotage_target_versions')->where('company_id',$company)
                ->where('metric',$data['metric'])
                ->where('period_from',$data['period_from'])->where('period_to',$data['period_to']);
            $latest = (clone $query)->orderByDesc('version')->first();
            // Identical retries cannot silently create additional versions.
            if ($latest && (int) $latest->target_value === (int) $data['target_value']
                && $latest->reason === $data['reason']) {
                return response()->json(['id'=>$latest->id,'version'=>$latest->version,'replayed'=>true]);
            }
            $next = ((int) ($latest?->version ?? 0)) + 1;
            $id = DB::table('pilotage_target_versions')->insertGetId([
                'company_id'=>$company,
                'metric'=>$data['metric'],
                'unit'=>self::METRICS[$data['metric']],
                'period_from'=>$data['period_from'],
                'period_to'=>$data['period_to'],
                'target_value'=>$data['target_value'],
                'version'=>$next,
                'reason'=>$data['reason'],
                'created_by'=>$request->user()->id,
                'created_at'=>now(),
            ]);
            Audit::record('pilotage.target.version.created',null,['target_id'=>$id,'version'=>$next]);
            return response()->json(['id'=>$id,'version'=>$next],201);
        },3);
    }
}
