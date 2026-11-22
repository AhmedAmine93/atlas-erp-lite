<?php
namespace App\Http\Controllers;

use App\Models\InventoryCount;
use App\Models\Product;
use App\Services\Audit;
use App\Services\InventoryStockService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class InventoryCountController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('movementsRead');
        $r->validate(['page'=>'sometimes|integer|min:1']);
        return InventoryCount::query()->latest('id')->paginate(20);
    }
    public function store(Request $r,InventoryStockService $stock)
    {
        Gate::authorize('stock');
        $v=$r->validate([
            'reference'=>'required|string|max:80','product_id'=>'required|integer|min:1',
            'location_id'=>'required|integer|min:1','lot_id'=>'nullable|integer|min:1',
            'company_id'=>'prohibited','status'=>'prohibited',
        ]);
        return DB::transaction(function() use($v,$stock) {
            $p=Product::query()->lockForUpdate()->findOrFail($v['product_id']);
            $location=$stock->location($v['location_id']);
            $lot=$stock->lot($p,$v['lot_id']??null);
            $existing=InventoryCount::query()->where('product_id',$p->id)
                ->where('location_id',$location->id)->where('lot_id',$lot?->id)
                ->whereIn('status',['draft','counted','recounted'])->exists();
            abort_if($existing,409,'Un inventaire est déjà ouvert pour ce périmètre.');
            $balance=$stock->balance($p,$location,$lot);
            $count=InventoryCount::create($v+[
                'snapshot_quantity'=>$balance->quantity,'status'=>'draft','created_by'=>auth()->id(),
            ]);
            Audit::record('inventory.count.opened',$count);
            return response()->json($count,201);
        },3);
    }
    public function record(Request $r,int $id)
    {
        Gate::authorize('stock');
        $v=$r->validate(['quantity'=>'required|integer|min:0|max:100000000','company_id'=>'prohibited']);
        return DB::transaction(function() use($id,$v) {
            $c=InventoryCount::query()->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($c->status,['draft','counted'],true),409,'Comptage terminé.');
            if($c->status==='counted') {
                abort_if((int)$c->counted_by===(int)auth()->id(),403,'Recomptage par une autre personne requis.');
                $c->second_count=$v['quantity'];
                $c->status='recounted';
            } else {
                $c->first_count=$v['quantity'];
                $c->counted_by=auth()->id();
                $c->status='counted';
            }
            $c->save();
            Audit::record('inventory.count.recorded',$c,['phase'=>$c->status]);
            return response()->json($c);
        },3);
    }
    public function approve(Request $r,int $id,InventoryStockService $stock)
    {
        Gate::authorize('admin');
        $v=$r->validate(['reason'=>'required|string|min:5|max:500','company_id'=>'prohibited']);
        return DB::transaction(function() use($id,$stock,$v) {
            $pre=InventoryCount::query()->findOrFail($id);
            $product=Product::query()->lockForUpdate()->findOrFail($pre->product_id);
            $c=InventoryCount::query()->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($c->status,['counted','recounted'],true),409,'Inventaire non compté.');
            abort_if(in_array(auth()->id(),[(int)$c->created_by,(int)$c->counted_by],true),
                403,'Approbation indépendante obligatoire.');
            if($c->first_count!==null && (int)$c->first_count!==(int)$c->snapshot_quantity)
                abort_unless($c->status==='recounted',409,'Écart nécessitant un recomptage indépendant.');
            if($c->status==='recounted' && (int)$c->first_count!==(int)$c->second_count)
                abort(409,'Deux comptages divergent : arbitrage métier requis.');
            $location=$stock->location($c->location_id,true);
            $lot=$stock->lot($product,$c->lot_id);
            $balance=$stock->balance($product,$location,$lot);
            abort_unless((int)$balance->quantity===(int)$c->snapshot_quantity,409,'Solde modifié après instantané : relancer inventaire.');
            $counted=$c->status==='recounted'?(int)$c->second_count:(int)$c->first_count;
            $delta=$counted-(int)$balance->quantity;
            if($delta!==0) {
                $stock->change($balance,$delta,'count_adjust',$c->reference,$v['reason']);
                app(StockService::class)->move($product,$delta,'Inventaire approuvé '.$c->reference,null,false);
            }
            $c->update(['status'=>'approved','reason'=>$v['reason'],'approved_by'=>auth()->id(),'approved_at'=>now()]);
            Audit::record('inventory.count.approved',$c,['delta'=>$delta]);
            return response()->json($c);
        },3);
    }
    public function cancel(int $id)
    {
        Gate::authorize('admin');
        return DB::transaction(function() use($id) {
            $c=InventoryCount::query()->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($c->status,['draft','counted','recounted'],true),409);
            $c->update(['status'=>'cancelled']);
            Audit::record('inventory.count.cancelled',$c);
            return response()->json($c);
        },3);
    }
}
