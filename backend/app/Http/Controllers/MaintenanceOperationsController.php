<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceAsset;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceSite;
use App\Models\MaintenanceWorkOrder;
use App\Models\Product;
use App\Services\Audit;
use App\Services\StockService;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * M07 operational endpoints. Query builder statements are ALWAYS explicitly company-scoped.
 * The tenant middleware also sets PostgreSQL RLS at the transaction boundary.
 */
final class MaintenanceOperationsController extends Controller
{
    private function company(): int
    {
        Gate::authorize('admin');
        return app(TenantContext::class)->requireId();
    }

    private function rows(string $table)
    {
        return DB::table($table)->where('company_id', $this->company());
    }

    private function row(string $table, int $id): object
    {
        return $this->rows($table)->where('id', $id)->first() ?? abort(404);
    }

    private function key(Request $request, array $extra = []): array
    {
        return $request->validate(array_merge([
            'operation_key' => 'required|string|min:8|max:100|regex:/^[A-Za-z0-9._:-]+$/',
            'company_id' => 'prohibited',
        ], $extra));
    }

    private function insert(string $table, array $attributes): object
    {
        $id = DB::table($table)->insertGetId(array_merge($attributes, [
            'company_id' => $this->company(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        return $this->row($table, $id);
    }

    private function reference(string $table, int $id, string $column): void
    {
        abort_unless($this->rows($table)->where('id', $id)->exists(), 404);
    }

    public function dashboard(): JsonResponse
    {
        $this->company();
        $open = MaintenanceWorkOrder::query()->where('status', '<>', 'completed');
        $closed = MaintenanceWorkOrder::query()->where('status', 'completed');
        $times = $this->rows('maintenance_work_order_time_entries')->sum('cost_cents');
        $spares = $this->rows('maintenance_spare_consumptions')->sum('total_cents');
        $downtime = $this->rows('maintenance_asset_downtimes');
        $due = $this->rows('maintenance_preventive_occurrences')->count();
        $done = $this->rows('maintenance_preventive_occurrences')->whereIn('work_order_id',
            MaintenanceWorkOrder::query()->where('status','completed')->select('id'))->count();
        $completed = (clone $closed)->whereNotNull('completed_at')->get(['created_at', 'completed_at']);
        $mttr = $completed->count() ? round($completed->avg(fn ($o) =>
            CarbonImmutable::parse($o->created_at)->diffInMinutes(CarbonImmutable::parse($o->completed_at))) / 60, 2) : null;
        return response()->json([
            'backlog_requests' => MaintenanceRequest::query()->whereIn('status',['new','qualified'])->count(),
            'open_work_orders' => $open->count(),
            'critical_assets_out_of_service' => MaintenanceAsset::query()->where('criticality','critical')
                ->where('status','out_of_service')->count(),
            'open_downtimes' => $downtime->whereNull('ended_at')->count(),
            'overdue_work_orders' => MaintenanceWorkOrder::query()->whereNotIn('status',['completed'])
                ->where('due_at','<',now())->count(),
            'preventive_due_count' => $due,
            'preventive_completed_count' => $done,
            'preventive_completion_percent' => $due ? round(100*$done/$due,2) : null,
            'mttr_hours_proxy' => $mttr,
            'labor_cost_cents' => (int)$times,
            'spare_cost_cents' => (int)$spares,
            'total_cost_cents' => (int)$times + (int)$spares,
            'calculation_notes' => [
                'MTTR proxy = BT creation-to-closure (not measured asset restoration); excluded from certified SLA reporting.',
                'Preventive completion = all recorded occurrences, not a filtered rolling window.',
                'MTBF not computed without complete event history and observation window.',
            ],
        ]);
    }

    public function locations(Request $request)
    {
        $data = $request->validate(['site_id'=>'nullable|integer|min:1']);
        return $this->rows('maintenance_locations')->when(isset($data['site_id']),
            fn ($q)=>$q->where('site_id',$data['site_id']))->orderBy('code')->paginate(50);
    }

    public function createLocation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'site_id'=>'required|integer|min:1', 'parent_id'=>'nullable|integer|min:1',
            'code'=>'required|string|max:60', 'name'=>'required|string|max:180',
            'company_id'=>'prohibited',
        ]);
        return DB::transaction(function () use ($data) {
            $site=MaintenanceSite::query()->findOrFail($data['site_id']);
            abort_unless($site->active,409,'Site désactivé.');
            if (isset($data['parent_id'])) {
                $parent=$this->row('maintenance_locations',$data['parent_id']);
                abort_unless((int)$parent->site_id === (int)$site->id, 422, 'Parent sur un autre site.');
            }
            abort_if($this->rows('maintenance_locations')->where('site_id',$site->id)
                ->where('code',$data['code'])->exists(),409,'Code emplacement existant.');
            $item=$this->insert('maintenance_locations',$data);
            Audit::record('maintenance.location.created',null,['id'=>$item->id]);
            return response()->json($item,201);
        },3);
    }

    public function moveAsset(Request $request,int $id): JsonResponse
    {
        $data=$request->validate([
            'location_id'=>'required|integer|min:1','reason'=>'required|string|min:10|max:500',
            'company_id'=>'prohibited','site_id'=>'prohibited',
        ]);
        return DB::transaction(function () use ($request,$id,$data) {
            $asset=MaintenanceAsset::query()->lockForUpdate()->findOrFail($id);
            abort_if($asset->status === 'retired',409,'Actif retiré.');
            $location=$this->row('maintenance_locations',$data['location_id']);
            abort_unless($location->active && (int)$location->site_id === (int)$asset->site_id,
                409,'Emplacement désactivé ou appartenant à un autre site.');
            abort_if($asset->location_id === (int)$location->id,409,'Aucun déplacement.');
            $previous=$asset->location_id;
            $asset->location_id=$location->id;
            $asset->save();
            $event=$this->insert('maintenance_asset_location_events',[
                'asset_id'=>$id,'from_location_id'=>$previous,'to_location_id'=>$location->id,
                'changed_by'=>$request->user()->id,'moved_at'=>now(),'reason'=>$data['reason'],
            ]);
            Audit::record('maintenance.asset.moved',$asset,['event_id'=>$event->id]);
            return response()->json($asset);
        },3);
    }

    public function stopAsset(Request $request,int $id): JsonResponse
    {
        $data=$request->validate(['reason'=>'required|string|min:10|max:500']);
        return DB::transaction(function () use ($request,$id,$data) {
            $asset=MaintenanceAsset::query()->lockForUpdate()->findOrFail($id);
            abort_if($asset->status === 'retired',409,'Actif retiré.');
            abort_if($asset->status === 'out_of_service',409,'Actif déjà immobilisé.');
            $down=$this->insert('maintenance_asset_downtimes',[
                'asset_id'=>$id,'started_at'=>now(),'reason'=>$data['reason'],
                'created_by'=>$request->user()->id,
            ]);
            $asset->status='out_of_service';
            $asset->save();
            Audit::record('maintenance.asset.stopped',$asset,['downtime_id'=>$down->id]);
            return response()->json($down,201);
        },3);
    }

    public function restoreAsset(Request $request,int $id): JsonResponse
    {
        $data=$request->validate([
            'inspection_id'=>'required|integer|min:1',
            'reason'=>'required|string|min:10|max:500',
        ]);
        return DB::transaction(function () use ($id,$data,$request) {
            $asset=MaintenanceAsset::query()->lockForUpdate()->findOrFail($id);
            abort_unless($asset->status === 'out_of_service',409,'Actif non immobilisé.');
            $inspection=$this->row('maintenance_inspections',$data['inspection_id']);
            abort_unless($inspection->passed,409,'Contrôle final refusé.');
            $wo=MaintenanceWorkOrder::query()->findOrFail($inspection->work_order_id);
            abort_unless((int)$wo->asset_id === $id,409,'Contrôle sur un autre actif.');
            abort_if((int)$inspection->reviewed_by === (int)$request->user()->id,403,
                'Le contrôle et la remise en service exigent deux personnes distinctes.');
            $down=$this->rows('maintenance_asset_downtimes')
                ->where('asset_id',$id)->whereNull('ended_at')->lockForUpdate()->first();
            abort_unless($down,409,'Arrêt sans enregistrement actif.');
            $this->rows('maintenance_asset_downtimes')->where('id',$down->id)
                ->update(['ended_at'=>now(),'verified_by'=>$request->user()->id,'updated_at'=>now()]);
            $asset->status='in_service'; $asset->save();
            Audit::record('maintenance.asset.restored',$asset,['inspection_id'=>$inspection->id,'reason'=>$data['reason']]);
            return response()->json($asset);
        },3);
    }

    public function rejectRequest(Request $request,int $id): JsonResponse
    {
        $data=$request->validate(['reason'=>'required|string|min:10|max:1000']);
        return DB::transaction(function () use ($request,$id,$data) {
            $di=MaintenanceRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless($di->status === 'new',409,'DI déjà traitée.');
            $di->status='rejected'; $di->rejection_reason=$data['reason'];
            $di->rejected_at=now(); $di->qualified_by=$request->user()->id; $di->save();
            Audit::record('maintenance.request.rejected',$di,['reason'=>$data['reason']]);
            return response()->json($di);
        },3);
    }

    public function labor(Request $request,int $id): JsonResponse
    {
        $data=$this->key($request,[
            'minutes'=>'required|integer|between:1,1440',
            'hourly_rate_cents'=>'required|integer|between:0,10000000',
        ]);
        return DB::transaction(function () use ($request,$id,$data) {
            $order=MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($id);
            $previous=$this->rows('maintenance_work_order_time_entries')->where('operation_key',$data['operation_key'])->first();
            if ($previous) {
                abort_unless((int)$previous->work_order_id === $id,409,'Clé utilisée pour un autre BT.');
                return response()->json($previous);
            }
            abort_unless($order->status === 'in_progress',409,'BT non démarré.');
            $cost=intdiv($data['minutes']*$data['hourly_rate_cents']+59,60);
            $entry=$this->insert('maintenance_work_order_time_entries',[
                'work_order_id'=>$id,'minutes'=>$data['minutes'],'hourly_rate_cents'=>$data['hourly_rate_cents'],
                'cost_cents'=>$cost,'operation_key'=>$data['operation_key'],'user_id'=>$request->user()->id,
            ]);
            Audit::record('maintenance.work_order.labor',$order,['entry_id'=>$entry->id,'cost_cents'=>$cost]);
            return response()->json($entry,201);
        },3);
    }

    public function reservations()
    {
        $this->company();
        return $this->rows('maintenance_spare_reservations')->latest('id')->paginate(50);
    }

    public function reserve(Request $request,int $id): JsonResponse
    {
        $data=$this->key($request,[
            'product_id'=>'required|integer|min:1','quantity'=>'required|integer|between:1,1000000',
        ]);
        return DB::transaction(function () use ($request,$id,$data) {
            $order=MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($id);
            $previous=$this->rows('maintenance_spare_reservations')->where('operation_key',$data['operation_key'])->first();
            if ($previous) {
                abort_unless((int)$previous->work_order_id === $id
                    && (int)$previous->product_id === (int)$data['product_id'],409,'Clé déjà utilisée.');
                return response()->json($previous);
            }
            abort_if($order->status === 'completed',409,'BT clos.');
            $product=Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $reserved=(int)$this->rows('maintenance_spare_reservations')->where('product_id',$product->id)
                ->selectRaw('COALESCE(SUM(reserved_quantity-consumed_quantity-released_quantity),0) AS balance')
                ->value('balance');
            abort_if($product->stock - $reserved < $data['quantity'],409,'Stock disponible insuffisant.');
            $item=$this->insert('maintenance_spare_reservations',[
                'work_order_id'=>$id,'product_id'=>$product->id,'reserved_quantity'=>$data['quantity'],
                'operation_key'=>$data['operation_key'],'created_by'=>$request->user()->id,
            ]);
            Audit::record('maintenance.spare.reserved',$order,['reservation_id'=>$item->id]);
            return response()->json($item,201);
        },3);
    }

    public function consume(Request $request,int $id,StockService $stock): JsonResponse
    {
        $data=$this->key($request,['quantity'=>'required|integer|between:1,1000000']);
        return DB::transaction(function () use ($request,$id,$data,$stock) {
            $reservation=$this->rows('maintenance_spare_reservations')->where('id',$id)->lockForUpdate()->first();
            abort_unless($reservation,404);
            $previous=$this->rows('maintenance_spare_consumptions')->where('operation_key',$data['operation_key'])->first();
            if ($previous) {
                abort_unless((int)$previous->reservation_id === $id,409,'Clé déjà utilisée.');
                return response()->json($previous);
            }
            $order=MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($reservation->work_order_id);
            abort_unless($order->status === 'in_progress',409,'BT non démarré.');
            abort_if($reservation->consumed_quantity+$reservation->released_quantity+$data['quantity'] >
                $reservation->reserved_quantity,409,'Quantité non réservée.');
            $product=Product::query()->lockForUpdate()->findOrFail($reservation->product_id);
            $stock->move($product,-$data['quantity'],'Maintenance BT #'.$order->id);
            $total=$data['quantity']*$product->price_cents;
            $consumption=$this->insert('maintenance_spare_consumptions',[
                'reservation_id'=>$id,'quantity'=>$data['quantity'],
                'unit_price_cents'=>$product->price_cents,'total_cents'=>$total,
                'operation_key'=>$data['operation_key'],'performed_by'=>$request->user()->id,
            ]);
            $this->rows('maintenance_spare_reservations')->where('id',$id)->update([
                'consumed_quantity'=>$reservation->consumed_quantity+$data['quantity'],'updated_at'=>now(),
            ]);
            Audit::record('maintenance.spare.consumed',$order,['consumption_id'=>$consumption->id,'total_cents'=>$total]);
            return response()->json($consumption,201);
        },3);
    }

    public function release(Request $request,int $id): JsonResponse
    {
        $data=$request->validate(['quantity'=>'required|integer|between:1,1000000','reason'=>'required|string|min:8|max:500']);
        return DB::transaction(function () use ($id,$data) {
            $reservation=$this->rows('maintenance_spare_reservations')->where('id',$id)->lockForUpdate()->first();
            abort_unless($reservation,404);
            $remaining=$reservation->reserved_quantity-$reservation->consumed_quantity-$reservation->released_quantity;
            abort_if($data['quantity']>$remaining,409,'Libération excessive.');
            $this->rows('maintenance_spare_reservations')->where('id',$id)
                ->update(['released_quantity'=>$reservation->released_quantity+$data['quantity'],'updated_at'=>now()]);
            Audit::record('maintenance.spare.released',null,['reservation_id'=>$id,'reason'=>$data['reason']]);
            return response()->json($this->row('maintenance_spare_reservations',$id));
        },3);
    }

    public function contracts()
    {
        return $this->rows('maintenance_contracts')->orderBy('code')->paginate(50);
    }

    public function contract(Request $request): JsonResponse
    {
        $data=$request->validate([
            'code'=>'required|string|max:60','name'=>'required|string|max:180',
            'site_id'=>'required|integer|min:1','response_hours'=>'required|integer|between:1,8760',
            'starts_on'=>'required|date_format:Y-m-d',
            'ends_on'=>'required|date_format:Y-m-d|after_or_equal:starts_on',
            'calendar_timezone'=>'required|timezone',
            'exclusions'=>'nullable|string|max:5000','company_id'=>'prohibited','status'=>'prohibited',
        ]);
        return DB::transaction(function () use ($data) {
            MaintenanceSite::query()->findOrFail($data['site_id']);
            abort_if($this->rows('maintenance_contracts')->where('code',$data['code'])->exists(),409,'Contrat existant.');
            $contract=$this->insert('maintenance_contracts',$data);
            Audit::record('maintenance.contract.created',null,['id'=>$contract->id]);
            return response()->json($contract,201);
        },3);
    }

    public function slaEvents(int $id)
    {
        $this->row('maintenance_contracts',$id);
        return $this->rows('maintenance_sla_events')->where('contract_id',$id)
            ->orderByDesc('occurred_at')->paginate(50);
    }

    public function slaEvent(Request $request,int $id): JsonResponse
    {
        $data=$request->validate([
            'work_order_id'=>'required|integer|min:1',
            'event'=>['required',Rule::in(['started','paused','resumed','breached','escalated','achieved'])],
            'reason'=>'required|string|min:8|max:500',
            'company_id'=>'prohibited','actor_id'=>'prohibited',
        ]);
        return DB::transaction(function () use ($request,$id,$data) {
            $contract=$this->row('maintenance_contracts',$id);
            abort_unless($contract->status === 'active',409,'Contrat inactif.');
            $work=MaintenanceWorkOrder::query()->findOrFail($data['work_order_id']);
            $asset=MaintenanceAsset::query()->findOrFail($work->asset_id);
            abort_unless((int)$asset->site_id === (int)$contract->site_id,422,'BT hors périmètre contrat.');
            $event=$this->insert('maintenance_sla_events',array_merge($data,[
                'contract_id'=>$id,'occurred_at'=>now(),'actor_id'=>$request->user()->id,
            ]));
            Audit::record('maintenance.sla.event',$work,['event_id'=>$event->id]);
            return response()->json($event,201);
        },3);
    }

    public function fieldEvents(int $id)
    {
        MaintenanceWorkOrder::query()->findOrFail($id);
        return $this->rows('maintenance_field_events')->where('work_order_id',$id)
            ->orderByDesc('id')->paginate(50);
    }

    public function fieldEvent(Request $request,int $id): JsonResponse
    {
        $data=$this->key($request,[
            'kind'=>['required',Rule::in(['note','arrival','departure','checklist'])],
            'note'=>'nullable|string|max:2000',
            'base_version'=>'required|integer|min:1',
        ]);
        return DB::transaction(function () use ($request,$id,$data) {
            $wo=MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($id);
            $previous=$this->rows('maintenance_field_events')->where('operation_key',$data['operation_key'])->first();
            if ($previous) {
                abort_unless((int)$previous->work_order_id === $id,409,'Clé déjà utilisée.');
                return response()->json($previous);
            }
            abort_if((int)$wo->field_version !== (int)$data['base_version'],409,'Version obsolète : synchronisation requise.');
            abort_if($wo->status === 'completed',409,'BT clos.');
            $event=$this->insert('maintenance_field_events',[
                'work_order_id'=>$id,'kind'=>$data['kind'],'note'=>$data['note']??null,
                'operation_key'=>$data['operation_key'],'base_version'=>$wo->field_version,
                'result_version'=>$wo->field_version+1,'actor_id'=>$request->user()->id,
            ]);
            $wo->field_version++;$wo->save();
            Audit::record('maintenance.field.synced',$wo,['event_id'=>$event->id]);
            return response()->json($event,201);
        },3);
    }

    public function inspect(Request $request,int $id): JsonResponse
    {
        $data=$request->validate(['passed'=>'required|boolean','note'=>'required|string|min:10|max:1000']);
        return DB::transaction(function () use ($request,$id,$data) {
            $wo=MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($id);
            abort_unless($wo->status === 'in_progress',409,'BT non contrôlable.');
            $inspection=$this->insert('maintenance_inspections',[
                'work_order_id'=>$id,'passed'=>$data['passed'],
                'note'=>$data['note'],'reviewed_by'=>$request->user()->id,
            ]);
            Audit::record('maintenance.work_order.inspected',$wo,['inspection_id'=>$inspection->id,'passed'=>$data['passed']]);
            return response()->json($inspection,201);
        },3);
    }

    public function costs(int $id): JsonResponse
    {
        MaintenanceWorkOrder::query()->findOrFail($id);
        $labor=(int)$this->rows('maintenance_work_order_time_entries')
            ->where('work_order_id',$id)->sum('cost_cents');
        $parts=(int)$this->rows('maintenance_spare_consumptions')
            ->whereIn('reservation_id',$this->rows('maintenance_spare_reservations')
                ->where('work_order_id',$id)->select('id'))->sum('total_cents');
        return response()->json(['labor_cost_cents'=>$labor,'spares_cost_cents'=>$parts,'total_cost_cents'=>$labor+$parts]);
    }
}
