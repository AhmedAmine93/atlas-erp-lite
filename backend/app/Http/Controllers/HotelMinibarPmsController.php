<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Services\Audit;
use App\Services\MinibarPmsCsv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class HotelMinibarPmsController extends Controller
{
    public function mapping(int $hotel)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $value = DB::table('minibar_pms_imports')->where('company_id',$property->company_id)
            ->where('hotel_id',$hotel)->orderByDesc('id')->value('mapping');
        return response()->json(['mapping'=>$value ? json_decode($value,true,512,JSON_THROW_ON_ERROR) : null]);
    }

    public function store(Request $request,int $hotel,MinibarPmsCsv $parser)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $data = $request->validate([
            'file'=>'required|file|max:1024','synthetic'=>'required|accepted','business_date'=>'required|date_format:Y-m-d',
            'delimiter'=>['required',\Illuminate\Validation\Rule::in([',',';'])],
            'mode'=>'required|in:room,room_product','amount_unit'=>'required|in:cents,euros',
            'date_header'=>'required|string|max:80','room_header'=>'required|string|max:80','amount_header'=>'required|string|max:80',
            'sku_header'=>'nullable|required_if:mode,room_product|string|max:80','company_id'=>'prohibited','hotel_id'=>'prohibited',
        ]);
        $map = \Illuminate\Support\Arr::only($data,['delimiter','mode','amount_unit','date_header','room_header','amount_header','sku_header']);
        if ($map['mode'] === 'room') { unset($map['sku_header']); }
        $bytes = $request->file('file')->get();
        $rows = $parser->parse($bytes,$map,$data['business_date']);
        $mapping = json_encode($map,JSON_THROW_ON_ERROR);
        $key = ['company_id'=>$property->company_id,'hotel_id'=>$hotel,'business_date'=>$data['business_date'],
            'digest'=>hash('sha256',$mapping."\0".$bytes)];
        return DB::transaction(function () use ($key,$mapping,$rows,$request,$property) {
            $inserted = DB::table('minibar_pms_imports')->insertOrIgnore($key+[
                'mapping'=>$mapping,'data_rows'=>json_encode($rows,JSON_THROW_ON_ERROR),'created_by'=>$request->user()->id,
            ]);
            $id = DB::table('minibar_pms_imports')->where($key)->value('id');
            if ($inserted) {
                Audit::record('hotel.minibar.pms.imported',$property,['import_id'=>$id,'row_count'=>count($rows)]);
            }
            return response()->json(['id'=>$id,'row_count'=>count($rows)],$inserted ? 201 : 200);
        });
    }
}
