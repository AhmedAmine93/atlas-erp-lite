<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Models\HotelRoom;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class HotelRegistryController extends Controller {
    public function hotels() {
        Gate::authorize('admin');
        return HotelProperty::query()->orderBy('name')->paginate(20);
    }
    public function rooms() {
        Gate::authorize('admin');
        return HotelRoom::query()->orderBy('hotel_id')->orderBy('room_number')->paginate(30);
    }
    public function createHotel(Request $request) {
        Gate::authorize('admin');
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'code'=>['required','string','max:40',Rule::unique('hotel_properties')->where('company_id',$company)],
            'name'=>'required|string|max:180',
            'timezone'=>['required','timezone'],
            'active'=>'sometimes|boolean',
            'company_id'=>'prohibited',
        ]);
        return DB::transaction(function () use ($data) {
            $hotel = HotelProperty::create($data);
            Audit::record('hotel.created',$hotel);
            return response()->json($hotel,201);
        });
    }
    public function createRoom(Request $request) {
        Gate::authorize('admin');
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'hotel_id'=>'required|integer|min:1',
            'room_number'=>'required|string|max:30',
            'floor_code'=>'nullable|string|max:30',
            'category'=>'required|string|max:80',
            'room_status'=>'prohibited',
            'company_id'=>'prohibited',
        ]);
        HotelProperty::query()->findOrFail($data['hotel_id']);
        $request->validate([
            'room_number'=>['required',Rule::unique('hotel_rooms')->where('hotel_id',$data['hotel_id'])],
        ]);
        return DB::transaction(function () use ($data) {
            $room = HotelRoom::create(array_merge($data,['room_status'=>'available']));
            Audit::record('hotel.room.created',$room);
            return response()->json($room,201);
        });
    }
}
