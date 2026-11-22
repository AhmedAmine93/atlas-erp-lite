<?php
namespace App\Http\Controllers;

use App\Models\InventoryReplenishment;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Audit;
use App\Services\InventoryStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class InventoryReplenishmentController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('movementsRead');
        $r->validate(['page'=>'sometimes|integer|min:1']);
        return InventoryReplenishment::query()->latest('id')->paginate(20);
    }
    public function propose(Request $r)
    {
        Gate::authorize('stock');
        $v=$r->validate([
            'product_id'=>'required|integer|min:1','supplier_id'=>'nullable|integer|min:1',
            'safety_quantity'=>'required|integer|min:0|max:100000000',
            'reserved_quantity'=>'sometimes|integer|min:0|max:100000000',
            'incoming_quantity'=>'sometimes|integer|min:0|max:100000000',
            'operation_key'=>'required|uuid','company_id'=>'prohibited',
            'suggested_quantity'=>'prohibited','status'=>'prohibited',
        ]);
        $hash=hash('sha256',json_encode($v,JSON_THROW_ON_ERROR));
        return DB::transaction(function() use($v,$hash) {
            $p=Product::query()->lockForUpdate()->findOrFail($v['product_id']);
            if(isset($v['supplier_id'])) Supplier::findOrFail($v['supplier_id']);
            $previous=InventoryReplenishment::query()->where('operation_key',$v['operation_key'])->first();
            if($previous) {
                abort_unless(hash_equals((string)$previous->operation_hash,$hash),409,'Clé réutilisée avec un autre contenu.');
                return response()->json($previous);
            }
            $reserved=$v['reserved_quantity']??0;
            $incoming=$v['incoming_quantity']??0;
            $available=max(0,app(InventoryStockService::class)->sellable($p)-$reserved);
            $required=max(0,(int)$v['safety_quantity']-$available-$incoming);
            $record=InventoryReplenishment::create([
                'product_id'=>$p->id,'supplier_id'=>$v['supplier_id']??null,
                'safety_quantity'=>$v['safety_quantity'],'available_quantity'=>$available,
                'reserved_quantity'=>$reserved,'incoming_quantity'=>$incoming,
                'suggested_quantity'=>$required,'status'=>'proposed',
                'operation_key'=>$v['operation_key'],'operation_hash'=>$hash,
                'created_by'=>auth()->id(),
            ]);
            Audit::record('inventory.replenishment.proposed',$record,['suggested_quantity'=>$required]);
            return response()->json($record,201);
        },3);
    }
    public function decide(Request $r,int $id)
    {
        Gate::authorize('admin');
        $v=$r->validate(['decision'=>'required|in:approved,rejected','company_id'=>'prohibited']);
        return DB::transaction(function() use($id,$v) {
            $record=InventoryReplenishment::query()->lockForUpdate()->findOrFail($id);
            abort_unless($record->status==='proposed',409,'Proposition déjà décidée.');
            abort_if((int)$record->created_by===(int)auth()->id(),403,'Validation à quatre yeux obligatoire.');
            $record->update(['status'=>$v['decision'],'approved_by'=>auth()->id(),
                'approved_at'=>$v['decision']==='approved'?now():null]);
            Audit::record('inventory.replenishment.decided',$record,['decision'=>$v['decision']]);
            // Approval is NOT a purchase order. M04 remains the only purchasing authority.
            return response()->json($record);
        },3);
    }
}
