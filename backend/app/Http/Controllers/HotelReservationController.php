<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Models\HotelReservation;
use App\Models\HotelRoom;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class HotelReservationController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('admin');
        $request->validate(['page' => 'sometimes|integer|min:1']);
        return HotelReservation::query()->orderByDesc('id')->paginate(20);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'hotel_id' => ['required', 'integer', 'min:1'],
            'room_id' => ['required', 'integer', 'min:1'],
            'guest_name' => ['required', 'string', 'max:160'],
            'arrival_on' => ['required', 'date_format:Y-m-d'],
            'departure_on' => ['required', 'date_format:Y-m-d', 'after:arrival_on'],
            'reference' => ['prohibited'],
            'status' => ['prohibited'],
            'channel' => ['prohibited'],
            'created_by' => ['prohibited'],
            'company_id' => ['prohibited'],
        ]);
        return DB::transaction(function () use ($request, $data) {
            $room = $this->lockAssignableRoom((int) $data['hotel_id'], (int) $data['room_id']);
            $this->assertAvailable($room->id, $data['arrival_on'], $data['departure_on']);
            $record = HotelReservation::create(array_merge($data, [
                'reference' => 'RSV-'.(string) Str::ulid(),
                'channel' => 'direct', 'status' => 'confirmed',
                'created_by' => $request->user()->id,
            ]));
            Audit::record('hotel.reservation.confirmed', $record);
            return response()->json($record, 201);
        });
    }

    /** Only a confirmed reservation may be rescheduled. */
    public function changeDates(Request $request, int $id)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'arrival_on' => ['required', 'date_format:Y-m-d'],
            'departure_on' => ['required', 'date_format:Y-m-d', 'after:arrival_on'],
            'company_id' => ['prohibited'],
            'hotel_id' => ['prohibited'],
            'room_id' => ['prohibited'],
            'status' => ['prohibited'],
        ]);
        return DB::transaction(function () use ($id, $data) {
            $snapshot = HotelReservation::query()->findOrFail($id);
            // Acquire locks in the same order as store() for each room.
            $room = $this->lockAssignableRoom($snapshot->hotel_id, $snapshot->room_id);
            $record = HotelReservation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'confirmed', 409, 'Réservation déjà clôturée.');
            $this->assertAvailable($room->id, $data['arrival_on'], $data['departure_on'], $record->id);
            $record->update($data);
            Audit::record('hotel.reservation.rescheduled', $record);
            return response()->json($record);
        });
    }

    public function cancel(int $id)
    {
        Gate::authorize('admin');
        return DB::transaction(function () use ($id) {
            $snapshot = HotelReservation::query()->findOrFail($id);
            HotelRoom::query()->whereKey($snapshot->room_id)->lockForUpdate()->firstOrFail();
            $record = HotelReservation::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'confirmed', 409, 'Réservation déjà annulée.');
            $record->update(['status' => 'cancelled']);
            Audit::record('hotel.reservation.cancelled', $record);
            return response()->json($record);
        });
    }

    private function lockAssignableRoom(int $hotelId, int $roomId): HotelRoom
    {
        HotelProperty::query()->where('active', true)->findOrFail($hotelId);
        return HotelRoom::query()
            ->where('hotel_id', $hotelId)
            ->where('room_status', 'available')
            ->lockForUpdate()
            ->findOrFail($roomId);
    }

    private function assertAvailable(int $roomId, string $arrival, string $departure, ?int $excludeId = null): void
    {
        $overlap = HotelReservation::query()->where('room_id', $roomId)
            ->where('status', 'confirmed')
            ->where('arrival_on', '<', $departure)
            ->where('departure_on', '>', $arrival)
            ->when($excludeId, fn ($q) => $q->where('id', '<>', $excludeId))
            ->exists();
        abort_if($overlap, 409, 'Cette chambre est déjà réservée pour les dates demandées.');
    }
}
