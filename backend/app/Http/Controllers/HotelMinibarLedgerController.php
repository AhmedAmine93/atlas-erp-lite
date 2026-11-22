<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Models\HotelRoom;
use App\Models\MinibarProduct;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class HotelMinibarLedgerController extends Controller
{
    public function store(Request $request,int $hotel)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $data = $request->validate([
            'room_id'=>'required|integer|min:1','product_id'=>'required|integer|min:1',
            'quantity'=>'required|integer|between:1,999','business_date'=>'required|date_format:Y-m-d',
            'request_id'=>'required|uuid','unit_cents'=>'prohibited','total_cents'=>'prohibited',
            'price_id'=>'prohibited','created_by'=>'prohibited','company_id'=>'prohibited','hotel_id'=>'prohibited',
        ]);
        return DB::transaction(function () use ($data,$property,$request) {
            $key = ['company_id'=>$property->company_id,'hotel_id'=>$property->id,'request_id'=>$data['request_id']];
            $lookup = DB::table('minibar_consumptions')->where($key);
            $payload = ['room_id'=>(int)$data['room_id'],'product_id'=>(int)$data['product_id'],
                'quantity'=>(int)$data['quantity'],'business_date'=>$data['business_date']];
            $existing = $lookup->first();
            if ($existing) {
                return $this->replay($existing,$payload);
            }
            HotelRoom::query()->where('hotel_id',$property->id)->findOrFail($payload['room_id']);
            $product = MinibarProduct::query()->where('hotel_id',$property->id)
                ->sharedLock()->findOrFail($payload['product_id']);
            abort_unless($product->active,409,'Article désactivé.');
            $price = DB::table('minibar_prices')->where('company_id',$property->company_id)
                ->where('hotel_id',$property->id)->where('product_id',$product->id)
                ->where('effective_on','<=',$payload['business_date'])->orderByDesc('effective_on')->first();
            abort_unless($price,409,'Aucun tarif pour cette journée.');
            $written = DB::table('minibar_consumptions')->insertOrIgnore($key + $payload + [
                'price_id'=>$price->id, 'unit_cents'=>$price->unit_cents, 'created_by'=>$request->user()->id,
            ]);
            $entry = $lookup->firstOrFail();
            if (!$written) {
                return $this->replay($entry,$payload);
            }
            Audit::record('hotel.minibar.consumption.created',$product,['entry_id'=>$entry->id,'room_id'=>$entry->room_id,
                'business_date'=>$entry->business_date,'quantity'=>$entry->quantity,'total_cents'=>$entry->total_cents]);
            return response()->json($entry,201);
        },3);
    }

    private function replay(object $entry,array $payload)
    {
        foreach ($payload as $field=>$value) {
            abort_unless((string)$entry->$field === (string)$value,409,'Clé de saisie déjà utilisée avec un contenu différent.');
        }
        return response()->json($entry);
    }
}
