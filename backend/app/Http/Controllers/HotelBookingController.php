<?php

namespace App\Http\Controllers;

use App\Models\HotelBooking;
use App\Models\HotelRoom;
use App\Models\HotelProperty;
use App\Models\HotelHousekeepingTask;
use App\Services\HotelRoomInventory;
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
                $hotel = HotelProperty::query()->findOrFail($room->hotel_id);
                $reasons = app(HotelRoomInventory::class)->reasons(
                    $hotel, $room, $booking->arrival_date, $booking->departure_date, $booking->id
                );
                abort_if($reasons !== [], 409, 'Chambre indisponible : '.implode(', ', $reasons));
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

    /**
     * A confirmed booking is not a check-in authorization by itself.
     * Inspection must match the actual hotel's business date, and the room
     * is locked before checking maintenance/hold windows and changing state.
     * Historical confirmed nights belong to THIS reservation and are excluded
     * from the conflict query, while competing reservations remain blocked.
     */
    public function checkin(int $id)
    {
        Gate::authorize('admin');

        return DB::transaction(function () use ($id) {
            $booking = HotelBooking::query()->lockForUpdate()->findOrFail($id);
            abort_unless($booking->status === 'confirmed', 409, 'Seule une réservation confirmée peut être enregistrée à l’arrivée.');

            $room = HotelRoom::query()->lockForUpdate()->findOrFail($booking->room_id);
            abort_unless($room->hotel_id === $booking->hotel_id, 409, 'La chambre ne correspond pas à l’hôtel.');

            $hotel = HotelProperty::query()->findOrFail($booking->hotel_id);
            $today = \Carbon\CarbonImmutable::now($hotel->timezone)->toDateString();
            abort_unless($booking->arrival_date <= $today && $today < $booking->departure_date,
                409, 'Check-in hors de la période du séjour.');

            $inspection = HotelHousekeepingTask::query()
                ->where('hotel_id', $hotel->id)
                ->where('room_id', $room->id)
                ->whereDate('business_date', $today)
                ->where('status', 'inspected')
                ->whereNotNull('inspected_at')
                ->first();
            abort_unless($inspection !== null, 409, 'Inspection housekeeping requise pour cette journée.');

            $reasons = app(HotelRoomInventory::class)->reasons(
                $hotel, $room, $today, $booking->departure_date, $booking->id
            );
            abort_if($reasons !== [], 409, 'Check-in interdit : '.implode(', ', $reasons));

            $booking->checked_in_on = $today;
            $booking->status = 'checked_in';
            $booking->save();
            Audit::record('hotel.booking.checked_in', $booking, [
                'room_id' => $room->id,
                'business_date' => $today,
                'housekeeping_task_id' => $inspection->id,
            ]);

            return response()->json($booking);
        }, 3);
    }

    public function checkout(Request $request, int $id)
    {
        Gate::authorize('admin');

        return DB::transaction(function () use ($request, $id) {
            $booking = HotelBooking::query()->lockForUpdate()->findOrFail($id);
            abort_unless($booking->status === 'checked_in', 409, 'Le séjour doit être en cours.');
            $room = HotelRoom::query()->where('hotel_id', $booking->hotel_id)
                ->lockForUpdate()->findOrFail($booking->room_id);
            $hotel = HotelProperty::query()->findOrFail($room->hotel_id);
            $today = \Carbon\CarbonImmutable::now($hotel->timezone)->toDateString();
            abort_if($today < ($booking->checked_in_on ?? $booking->arrival_date),
                409, 'Départ antérieur à l’arrivée effective.');
            $early = $today < $booking->departure_date;
            $data = $request->validate([
                'reason' => [$early ? 'required' : 'nullable', 'string', 'max:300'],
                'company_id' => 'prohibited', 'room_id' => 'prohibited', 'hotel_id' => 'prohibited',
                'status' => 'prohibited', 'business_date' => 'prohibited', 'checked_out_on' => 'prohibited',
            ]);
            $booking->checked_out_on = $today;
            $booking->status = 'checked_out';
            $booking->save();
            Audit::record('hotel.booking.checked_out', $booking, [
                'room_id' => $room->id, 'business_date' => $today,
                'scheduled_departure' => $booking->departure_date, 'early' => $early,
                'reason' => $data['reason'] ?? null,
            ]);
            return response()->json($booking);
        }, 3);
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
