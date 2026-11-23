<?php
namespace App\Http\Controllers;

use App\Models\InventoryLot;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Services\Audit;
use App\Services\InventoryStockService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class InventoryTraceabilityController extends Controller
{
    public function lots(Request $r)
    {
        Gate::authorize('movementsRead');
        $v = $r->validate(['product_id'=>'sometimes|integer|min:1','page'=>'sometimes|integer|min:1']);
        if (isset($v['product_id'])) Product::findOrFail($v['product_id']);
        return InventoryLot::query()->when($v['product_id'] ?? null, fn($q,$id)=>$q->where('product_id',$id))
            ->orderByDesc('id')->paginate(20);
    }

    public function createLot(Request $r)
    {
        Gate::authorize('stock');
        $v=$r->validate([
            'product_id'=>'required|integer|min:1','code'=>['required','string','max:80',
                Rule::unique('inventory_lots','code')->where('company_id',app(TenantContext::class)->requireId())
                    ->where('product_id',$r->input('product_id'))],
            'expires_on'=>'nullable|date_format:Y-m-d','company_id'=>'prohibited','status'=>'prohibited',
        ]);
        Product::findOrFail($v['product_id']);
        abort_if(InventoryLot::query()->where('product_id', $v['product_id'])
            ->where('code', $v['code'])->exists(), 409, 'Code lot déjà utilisé pour ce produit.');
        return DB::transaction(function() use($v) {
            $lot=InventoryLot::create($v+['status'=>'quarantine']);
            Audit::record('inventory.lot.created',$lot);
            return response()->json($lot,201);
        },3);
    }

    public function decideLot(Request $r,int $id)
    {
        Gate::authorize('admin');
        $v=$r->validate([
            'status'=>['required',Rule::in(['available','quarantine','blocked','recalled'])],
            'reason'=>'required|string|min:5|max:500','company_id'=>'prohibited',
        ]);
        return DB::transaction(function() use($id,$v) {
            $lot=InventoryLot::query()->lockForUpdate()->findOrFail($id);
            if($v['status']==='available' && $lot->expires_on && $lot->expires_on < now()->toDateString())
                abort(409,'Lot périmé : libération interdite.');
            $lot->update(['status'=>$v['status'],'decision_reason'=>$v['reason'],'decided_by'=>auth()->id()]);
            Audit::record('inventory.lot.decided',$lot,['status'=>$v['status'],'reason'=>$v['reason']]);
            return response()->json($lot);
        },3);
    }

    public function serials(Request $r)
    {
        Gate::authorize('movementsRead');
        $v=$r->validate(['product_id'=>'sometimes|integer|min:1','page'=>'sometimes|integer|min:1']);
        if(isset($v['product_id'])) Product::findOrFail($v['product_id']);
        return InventorySerial::query()
            ->when(!Gate::allows('admin'), fn ($q) => $q->whereIn('location_id', \App\Models\InventoryLocation::query()->where('condition_code', '!=', 'regulated')->select('id')))
            ->when($v['product_id'] ?? null,fn($q,$id)=>$q->where('product_id',$id))
            ->orderByDesc('id')->paginate(20);
    }

    public function createSerial(Request $r,InventoryStockService $service)
    {
        Gate::authorize('stock');
        $v=$r->validate([
            'product_id'=>'required|integer|min:1','location_id'=>'required|integer|min:1',
            'lot_id'=>'nullable|integer|min:1','serial_number'=>['required','string','max:120',
                Rule::unique('inventory_serials','serial_number')
                    ->where('company_id',app(TenantContext::class)->requireId())
                    ->where('product_id',$r->input('product_id'))],
            'company_id'=>'prohibited','status'=>'prohibited',
        ]);
        return DB::transaction(function() use($v,$service) {
            $product=Product::query()->lockForUpdate()->findOrFail($v['product_id']);
            $location=$service->location($v['location_id'],true);
            $lot=$service->lot($product,$v['lot_id']??null);
            $balance=$service->balance($product,$location,$lot);
            $active=InventorySerial::query()->where('product_id',$product->id)
                ->where('location_id',$location->id)->where('lot_id',$lot?->id)
                ->whereIn('status',['available','quarantine'])->count();
            abort_unless($active < (int)$balance->quantity,409,
                'Aucune unité physique restante à associer à ce numéro de série.');
            if($location->condition_code==='regulated') Gate::authorize('admin');
            $record=InventorySerial::create($v+[
                'status'=>$lot && $lot->status!=='available'?'quarantine':'available',
            ]);
            Audit::record('inventory.serial.created',$record);
            return response()->json($record,201);
        },3);
    }
}
