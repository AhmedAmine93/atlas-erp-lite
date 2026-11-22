<?php
namespace App\Http\Controllers;

use App\Models\InventoryTransfer;
use App\Models\InventoryTransferReceipt;
use App\Models\Product;
use App\Services\Audit;
use App\Services\InventoryStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Receives damaged, still physical, goods into a non-sellable quarantine bin. */
final class InventoryDamageReceiptController extends Controller
{
    public function store(Request $r,int $id,InventoryStockService $stock)
    {
        Gate::authorize('stock');
        $v=$r->validate([
            'reference'=>'required|string|max:80',
            'quantity'=>'required|integer|min:1|max:1000000',
            'damage_location_id'=>'required|integer|min:1',
            'reason'=>'required|string|min:5|max:500',
            'operation_key'=>'required|uuid','company_id'=>'prohibited',
        ]);
        $hash=hash('sha256',json_encode([$id,$v],JSON_THROW_ON_ERROR));
        return DB::transaction(function() use($id,$stock,$v,$hash) {
            $pre=InventoryTransfer::query()->findOrFail($id);
            $p=Product::query()->lockForUpdate()->findOrFail($pre->product_id);
            $transfer=InventoryTransfer::query()->lockForUpdate()->findOrFail($id);
            $previous=InventoryTransferReceipt::query()
                ->where('operation_key',$v['operation_key'])->first();
            if($previous) {
                abort_unless(hash_equals((string)$previous->operation_hash,$hash),409,
                    'Clé de rejeu utilisée pour une autre réception.');
                return response()->json($transfer);
            }
            abort_unless($transfer->status==='in_transit',409,'Aucune marchandise en transit.');
            $pending=(int)$transfer->quantity_shipped-(int)$transfer->quantity_received-(int)$transfer->quantity_lost;
            abort_unless($v['quantity']<=$pending,409,'Quantité supérieure au transit.');
            $target=$stock->location($transfer->target_location_id,true);
            $quarantine=$stock->location($v['damage_location_id'],true);
            abort_unless($quarantine->condition_code==='quarantine'
                && (int)$quarantine->warehouse_id===(int)$target->warehouse_id,409,
                'Un emplacement de quarantaine du dépôt destination est requis.');
            $lot=$stock->lot($p,$transfer->lot_id);
            $stock->change($stock->balance($p,$quarantine,$lot),$v['quantity'],
                'transfer_damaged',$v['reference'],$v['reason']);
            InventoryTransferReceipt::create([
                'transfer_id'=>$transfer->id,'reference'=>$v['reference'],
                'quantity'=>$v['quantity'],'operation_key'=>$v['operation_key'],
                'operation_hash'=>$hash,'received_by'=>auth()->id(),
                'damaged'=>true,'damage_location_id'=>$quarantine->id,'damage_reason'=>$v['reason'],
            ]);
            $transfer->quantity_received+=(int)$v['quantity'];
            $transfer->status=$transfer->quantity_received+$transfer->quantity_lost===$transfer->quantity_shipped
                ?'received':'in_transit';
            $transfer->save();
            Audit::record('inventory.transfer.damaged_receipt',$transfer,[
                'quantity'=>$v['quantity'],'quarantine_location_id'=>$quarantine->id,'reason'=>$v['reason'],
            ]);
            return response()->json($transfer);
        },3);
    }
}
