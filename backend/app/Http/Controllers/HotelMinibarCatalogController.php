<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Models\MinibarProduct;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class HotelMinibarCatalogController extends Controller
{
    public function index(Request $request, int $hotel)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $data = $request->validate(['business_date' => 'required|date_format:Y-m-d']);
        $price = DB::table('minibar_prices as p')->select('p.unit_cents')
            ->whereColumn('p.company_id','minibar_products.company_id')
            ->whereColumn('p.hotel_id','minibar_products.hotel_id')
            ->whereColumn('p.product_id','minibar_products.id')
            ->where('p.effective_on','<=',$data['business_date'])->orderByDesc('p.effective_on')->limit(1);
        return MinibarProduct::query()->where('hotel_id',$property->id)->select('minibar_products.*')
            ->selectSub($price,'unit_cents')->orderBy('sku')->get();
    }

    public function store(Request $request, int $hotel)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $data = $request->validate([
            'sku' => ['required','regex:/^[A-Z0-9][A-Z0-9._-]{0,39}$/D', Rule::unique('minibar_products')
                ->where('company_id',$property->company_id)->where('hotel_id',$hotel)],
            'name' => 'required|string|max:120', 'company_id' => 'prohibited', 'hotel_id' => 'prohibited',
            'effective_on' => 'required|date_format:Y-m-d', 'unit_cents' => 'required|integer|between:0,1000000',
        ]);
        return DB::transaction(function () use ($data,$property) {
            $product = MinibarProduct::create(['hotel_id' => $property->id, 'sku' => $data['sku'], 'name' => $data['name']]);
            $this->insertPrice($product,$data);
            Audit::record('hotel.minibar.product.created',$product);
            return response()->json($product,201);
        });
    }

    public function update(Request $request, int $hotel, int $id)
    {
        Gate::authorize('admin');
        $product = MinibarProduct::query()->where('hotel_id',$hotel)->findOrFail($id);
        $data = $request->validate(['name' => 'sometimes|required|string|max:120', 'active' => 'sometimes|boolean',
            'company_id' => 'prohibited','hotel_id' => 'prohibited','sku' => 'prohibited']);
        return DB::transaction(function () use ($product,$data) {
            $product->update($data);
            Audit::record('hotel.minibar.product.updated',$product);
            return $product;
        });
    }

    public function price(Request $request, int $hotel, int $id)
    {
        Gate::authorize('admin');
        $data = $request->validate(['effective_on' => 'required|date_format:Y-m-d',
            'unit_cents' => 'required|integer|between:0,1000000', 'company_id' => 'prohibited']);
        return DB::transaction(function () use ($hotel,$id,$data) {
            $product = MinibarProduct::query()->where('hotel_id',$hotel)->lockForUpdate()->findOrFail($id);
            $latest = DB::table('minibar_prices')->where('company_id',$product->company_id)
                ->where('hotel_id',$hotel)->where('product_id',$id)->max('effective_on');
            abort_if($latest !== null && $data['effective_on'] <= $latest, 409, 'Date de tarif déjà couverte.');
            $this->insertPrice($product,$data);
            return response()->json(['effective_on' => $data['effective_on'],'unit_cents' => (int)$data['unit_cents']],201);
        });
    }

    private function insertPrice(MinibarProduct $product,array $data): void
    {
        DB::table('minibar_prices')->insert(['company_id' => $product->company_id, 'hotel_id' => $product->hotel_id,
            'product_id' => $product->id, 'effective_on' => $data['effective_on'], 'unit_cents' => $data['unit_cents']]);
        Audit::record('hotel.minibar.price.created',$product,['effective_on' => $data['effective_on'],'unit_cents' => $data['unit_cents']]);
    }
}
