<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Services\HotelMinibarReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class HotelMinibarReportController extends Controller
{
    public function daily(Request $request,int $hotel,HotelMinibarReport $report)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $day = $request->validate(['business_date'=>'required|date_format:Y-m-d'])['business_date'];
        $lines = $report->lines($property,$day,$day);
        return response()->json(['hotel_id'=>$hotel,'business_date'=>$day,'currency'=>'EUR',
            'lines'=>$lines,'total_cents'=>(int)$lines->sum('total_cents'),
            'by_room'=>$lines->groupBy('room_number')->map(fn($rows)=>$rows->sum('total_cents'))->all(),
            'by_sku'=>$lines->groupBy('sku')->map(fn($rows)=>$rows->sum('total_cents'))->all()]);
    }

    public function export(Request $request,int $hotel,HotelMinibarReport $report)
    {
        Gate::authorize('admin');
        $property = HotelProperty::query()->findOrFail($hotel);
        $day = $request->validate(['business_date'=>'required|date_format:Y-m-d'])['business_date'];
        $lines = $report->lines($property,$day,$day);
        return $report->csv("minibar-$hotel-$day.csv",
            ['business_date','room_number','sku','product','unit_cents','quantity','total_cents'],
            $lines->map(fn($r)=>[$r->business_date,$r->room_number,$r->sku,$r->name,
                (int)$r->unit_cents,(int)$r->quantity,(int)$r->total_cents]));
    }
}
