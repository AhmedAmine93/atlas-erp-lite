<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Services\Audit;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class IndustrialJetController extends Controller
{
    private function scope(Request $request): int
    {
        // The existing GMAO uses the tenant's admin role until dedicated
        // maintenance roles and a separate OIDC contract have been reviewed.
        Gate::authorize('admin');
        return app(TenantContext::class)->requireId();
    }

    private function pgOnly(): void
    {
        abort_unless(DB::getDriverName() === 'pgsql', 503,
            'Le module industriel exige PostgreSQL.');
    }

    private function fail(QueryException $exception): never
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if ($state === 'P0002') abort(404, 'Équipement ou modèle introuvable.');
        if ($state === '23505') abort(409, 'Conflit de clé d’idempotence.');
        if ($state === '23514') abort(409, 'Règle métier industrielle non respectée.');
        if ($state === '22023') abort(422, 'Valeur industrielle invalide.');
        if ($state === '42501') abort(403, 'Contexte entreprise non autorisé.');
        throw $exception;
    }

    public function overview(Request $request)
    {
        $company=$this->scope($request);
        $this->pgOnly();
        $start=CarbonImmutable::now()->subDays(30);
        $response=[
            'company_id'=>$company,
            'generated_at'=>now()->toIso8601String(),
            'range'=>['from'=>$start->toDateString(),'to'=>now()->toDateString()],
            'assets_by_status'=>DB::table('maintenance_assets')->where('company_id',$company)
                ->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')
                ->pluck('total','status')->map(fn ($n)=>(int) $n),
            'requests_by_priority'=>DB::table('maintenance_requests')->where('company_id',$company)
                ->whereIn('status',['new','qualified'])->select('priority')
                ->selectRaw('COUNT(*) AS total')->groupBy('priority')->pluck('total','priority')
                ->map(fn ($n)=>(int) $n),
            'work_orders_by_status'=>DB::table('maintenance_work_orders')->where('company_id',$company)
                ->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')
                ->pluck('total','status')->map(fn ($n)=>(int) $n),
            'meter_readings_30d'=>(int) DB::table('industrial_meter_readings')
                ->where('company_id',$company)->where('recorded_at','>=',$start)->count(),
            'failed_inspections_30d'=>(int) DB::table('industrial_inspection_runs')
                ->where('company_id',$company)->where('inspected_at','>=',$start)
                ->where('result','fail')->count(),
            'pending_preventive'=>(int) DB::table('maintenance_preventive_plans')
                ->where('company_id',$company)->where('active',true)
                ->where('next_due_on','<=',now()->toDateString())->count(),
        ];
        return response()->json($response)->header('Cache-Control','private, no-store');
    }

    public function meters(Request $request)
    {
        $company=$this->scope($request);
        $this->pgOnly();
        $input=$request->validate([
            'asset_id'=>'nullable|integer|min:1',
            'meter_code'=>['nullable',Rule::in(['HOURS','CYCLES','DISTANCE','ENERGY'])],
            'company_id'=>'prohibited','page'=>'nullable|integer|min:1|max:1000',
        ]);
        return DB::table('industrial_meter_readings')->where('company_id',$company)
            ->when(isset($input['asset_id']),fn ($q)=>$q->where('asset_id',$input['asset_id']))
            ->when(isset($input['meter_code']),fn ($q)=>$q->where('meter_code',$input['meter_code']))
            ->select('id','asset_id','meter_code','reading_value','unit','recorded_at')
            ->orderByDesc('id')->paginate(20);
    }

    public function recordMeter(Request $request)
    {
        $company=$this->scope($request);
        $this->pgOnly();
        $data=$request->validate([
            'asset_id'=>'required|integer|min:1',
            'meter_code'=>['required',Rule::in(['HOURS','CYCLES','DISTANCE','ENERGY'])],
            'reading_value'=>['required','numeric','min:0','max:999999999999.999'],
            'idempotency_key'=>['required','string','regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'company_id'=>'prohibited','recorded_by'=>'prohibited','unit'=>'prohibited',
        ]);
        try {
            return DB::transaction(function () use ($request,$company,$data) {
                $result=DB::selectOne(
                    'SELECT * FROM atlas_industrial.record_meter(?,?,?,?,?,?)',
                    [$company,$data['asset_id'],$data['meter_code'],$data['reading_value'],
                        $data['idempotency_key'],$request->user()->id],
                );
                if (! $result->replayed) {
                    Audit::record('industrial.meter.recorded',null,[
                        'reading_id'=>(int) $result->reading_id,
                        'asset_id'=>(int) $data['asset_id'],
                        'meter_code'=>$data['meter_code'],
                    ]);
                }
                return response()->json(['id'=>(int) $result->reading_id,'replayed'=>(bool) $result->replayed],
                    $result->replayed ? 200 : 201);
            },3);
        } catch (QueryException $exception) {
            $this->fail($exception);
        }
    }

    public function templates(Request $request)
    {
        $company=$this->scope($request);
        $this->pgOnly();
        return DB::table('industrial_inspection_templates')->where('company_id',$company)
            ->select('id','code','title','description','active','created_at')
            ->orderBy('code')->paginate(30);
    }

    public function createTemplate(Request $request)
    {
        $company=$this->scope($request);
        $this->pgOnly();
        $data=$request->validate([
            'code'=>['required','string','min:2','max:40','regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('industrial_inspection_templates','code')->where('company_id',$company)],
            'title'=>'required|string|min:5|max:160',
            'description'=>'nullable|string|max:500',
            'company_id'=>'prohibited','active'=>'prohibited','created_by'=>'prohibited',
        ]);
        return DB::transaction(function () use ($company,$request,$data) {
            $id=DB::table('industrial_inspection_templates')->insertGetId([
                ...$data,'company_id'=>$company,'active'=>true,
                'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            Audit::record('industrial.inspection.template.created',null,['template_id'=>$id]);
            return response()->json(['id'=>$id],201);
        },3);
    }

    public function inspections(Request $request)
    {
        $company=$this->scope($request);
        $this->pgOnly();
        $data=$request->validate([
            'asset_id'=>'nullable|integer|min:1',
            'result'=>['nullable',Rule::in(['pass','fail','observation'])],
            'company_id'=>'prohibited',
        ]);
        return DB::table('industrial_inspection_runs')->where('company_id',$company)
            ->when(isset($data['asset_id']),fn ($q)=>$q->where('asset_id',$data['asset_id']))
            ->when(isset($data['result']),fn ($q)=>$q->where('result',$data['result']))
            ->select('id','asset_id','template_id','result','observations','inspected_at')
            ->orderByDesc('id')->paginate(20);
    }

    public function recordInspection(Request $request)
    {
        $company=$this->scope($request);
        $this->pgOnly();
        $data=$request->validate([
            'asset_id'=>'required|integer|min:1','template_id'=>'required|integer|min:1',
            'result'=>['required',Rule::in(['pass','fail','observation'])],
            'observations'=>'required|string|min:10|max:2000',
            'idempotency_key'=>['required','string','regex:/^[A-Za-z0-9_-]{8,64}$/'],
            'company_id'=>'prohibited','inspected_by'=>'prohibited',
        ]);
        try {
            return DB::transaction(function () use ($company,$request,$data) {
                $row=DB::selectOne('SELECT * FROM atlas_industrial.record_inspection(?,?,?,?,?,?,?)',[
                    $company,$data['asset_id'],$data['template_id'],$data['result'],
                    $data['observations'],$data['idempotency_key'],$request->user()->id,
                ]);
                $di=null;$order=null;
                if (! $row->replayed) {
                    if ($data['result']==='fail') {
                        // A failed inspection atomically creates ONE corrective DI and BT.
                        // The immutable inspection ID is also the deterministic DI reference.
                        $di=MaintenanceRequest::create([
                            'asset_id'=>$data['asset_id'],
                            'reference'=>'INS-'.(int) $row->inspection_id,
                            'title'=>'Contrôle non conforme #'.(int) $row->inspection_id,
                            'description'=>$data['observations'],
                            'priority'=>'high','status'=>'qualified',
                            'created_by'=>$request->user()->id,
                        ]);
                        $order=MaintenanceWorkOrder::create([
                            'maintenance_request_id'=>$di->id,'asset_id'=>$data['asset_id'],
                            'priority'=>'high','status'=>'open',
                            'summary'=>'Action corrective liée au contrôle #'.(int) $row->inspection_id,
                        ]);
                        Audit::record('industrial.inspection.corrective_created',$order,[
                            'inspection_id'=>(int) $row->inspection_id,'request_id'=>$di->id,
                        ]);
                    }
                    Audit::record('industrial.inspection.recorded',null,[
                        'inspection_id'=>(int) $row->inspection_id,'result'=>$data['result'],
                    ]);
                }
                if ($row->replayed && $data['result']==='fail') {
                    $di=MaintenanceRequest::query()->where('reference','INS-'.(int) $row->inspection_id)->first();
                    $order=$di?->id ? MaintenanceWorkOrder::query()
                        ->where('maintenance_request_id',$di->id)->first() : null;
                }
                return response()->json([
                    'id'=>(int) $row->inspection_id,'replayed'=>(bool) $row->replayed,
                    'corrective_request_id'=>$di?->id,'corrective_work_order_id'=>$order?->id,
                ],$row->replayed ? 200 : 201);
            },3);
        } catch (QueryException $exception) {
            $this->fail($exception);
        }
    }
}
