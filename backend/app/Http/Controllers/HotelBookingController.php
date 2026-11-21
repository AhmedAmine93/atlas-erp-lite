<?php

namespace App\Http\Controllers;

use App\Models\HotelBooking;
use App\Models\HotelRoom;
use App\Services\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class HotelBookingController extends Controller
{
    public function index()
    {
        Gate::authorize('admin');
        return HotelBooking::query()->latest('id')->paginate(20);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'room_id' => 'required|integer|min:1',
            'reference' => 'required|string|max:60',
            'guest_display_name' => 'required|string|max:180',
            'arrival_date' => 'required|date_format:Y-m-d',
            'departure_date' => 'required|date_format:Y-m-d|after:arrival_date',
            'hotel_id' => 'prohibited',
            'status' => 'prohibited',
            'created_by' => 'prohibited',
            'company_id' => 'prohibited',
        ]);
        $room = HotelRoom::query()->findOrFail($data['room_id']);
        abort_unless($room->room_status === 'available', 409, 'Chambre indisponible.');
        $request->validate(['reference' => [
            'required',
            Rule::unique('hotel_bookings')->where('company_id', $room->company_id)
                ->where('hotel_id', $room->hotel_id),
        ]]);
        return DB::transaction(function () use ($request, $data, $room) {
            $item = HotelBooking::create([
                'hotel_id' => $room->hotel_id,
                'room_id' => $room->id,
                'reference' => $data['reference'],
                'guest_display_name' => $data['guest_display_name'],
                'arrival_date' => $data['arrival_date'],
                'departure_date' => $data['departure_date'],
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
            Audit::record('hotel.booking.draft.created', $item);
            return response()->json($item, 201);
        });
    }

    /**
     * Serialize confirmations for the same room. PostgreSQL also enforces a
     * GiST exclusion constraint, including writes outside this controller.
     * Dates are business dates in the property's configured timezone; [) means
     * same-day departure and arrival do not overlap.
     */
    public function confirm(int $id)
    {
        Gate::authorize('admin');
        try {
            return DB::transaction(function () use ($id) {
                $booking = HotelBooking::query()->lockForUpdate()->findOrFail($id);
                abort_unless($booking->status === 'draft', 409, 'Réservation non confirmable.');
                $room = HotelRoom::query()->lockForUpdate()->findOrFail($booking->room_id);
                abort_unless($room->hotel_id === $booking->hotel_id
                    && $room->room_status === 'available', 409, 'Chambre indisponible.');
                $overlap = HotelBooking::query()
                    ->where('hotel_id', $booking->hotel_id)
                    ->where('room_id', $room->id)
                    ->where('id', '!=', $booking->id)
                    ->whereIn('status', ['confirmed', 'checked_in'])
                    ->where('arrival_date', '<', $booking->departure_date)
                    ->where('departure_date', '>', $booking->arrival_date)
                    ->exists();
                abort_if($overlap, 409, 'Chambre déjà réservée sur ces dates.');
                $booking->status = 'confirmed';
                $booking->save();
                Audit::record('hotel.booking.confirmed', $booking);
                return response()->json($booking);
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) === '23P01') {
                abort(409, 'Chambre déjà réservée sur ces dates.');
            }
            throw $e;
        }
    }

    public function cancel(int $id)
    {
        Gate::authorize('admin');
        return DB::transaction(function () use ($id) {
            $booking = HotelBooking::query()->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($booking->status, ['draft', 'confirmed'], true),
                409, 'Annulation non autorisée à ce stade.');
            $booking->status = 'cancelled';
            $booking->save();
            Audit::record('hotel.booking.cancelled', $booking);
            return response()->json($booking);
        });
    }
}
