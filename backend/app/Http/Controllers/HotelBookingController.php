<?php

namespace App\Http\Controllers;

use App\Models\HotelRoom;
use App\Models\HotelBooking;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class HotelBookingController extends Controller {
    public function index() {
        Gate::authorize('admin');
        return HotelBooking::query()->latest('id')->paginate(20);
    }
    public function store(Request $request) {
        Gate::authorize('admin');
        $data=$request->validate([
            'room_id'=>'required|integer|min:1',
            'reference'=>'required|string|max:60',
            'guest_display_name'=>'required|string|max:180',
            'arrival_date'=>'required|date_format:Y-m-d',
            'departure_date'=>'required|date_format:Y-m-d|after:arrival_date',
            'hotel_id'=>'prohibited',
            'status'=>'prohibited',
            'created_by'=>'prohibited',
            'company_id'=>'prohibited',
        ]);
        // Draft allocation only: booking confirmation needs a serializable
        // occupancy exclusion rule and a dedicated follow-up phase.
        $room=HotelRoom::query()->findOrFail($data['room_id']);
        abort_unless($room->room_status==='available',409,'Chambre indisponible.');
        $request->validate(['reference'=>[
            'required',
            Rule::unique('hotel_bookings')->where('company_id', $room->company_id)
                ->where('hotel_id', $room->hotel_id),
        ]]);
        return DB::transaction(function () use($request,$data,$room){
            $item=HotelBooking::create([
                'hotel_id'=>$room->hotel_id,
                'room_id'=>$room->id,
                'reference'=>$data['reference'],
                'guest_display_name'=>$data['guest_display_name'],
                'arrival_date'=>$data['arrival_date'],
                'departure_date'=>$data['departure_date'],
                'status'=>'draft',
                'created_by'=>$request->user()->id,
            ]);
            Audit::record('hotel.booking.draft.created',$item);
            return response()->json($item,201);
        });
    }
}
