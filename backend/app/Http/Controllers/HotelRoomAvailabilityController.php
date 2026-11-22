<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Models\HotelRoom;
use App\Services\HotelRoomInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class HotelRoomAvailabilityController extends Controller
{
    /**
     * Local inventory snapshot, not a sale/price commitment. Confirmation
     * always rechecks under a room lock and PostgreSQL GiST exclusion.
     */
    public function index(Request $request, HotelRoomInventory $inventory): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'hotel_id' => 'required|integer|min:1',
            'room_id' => 'nullable|integer|min:1',
            'category' => 'nullable|string|max:80',
            'arrival_date' => 'required|date_format:Y-m-d',
            'departure_date' => 'required|date_format:Y-m-d|after:arrival_date',
            'company_id' => 'prohibited',
        ]);

        $hotel = HotelProperty::query()->findOrFail($data['hotel_id']);
        $rooms = HotelRoom::query()
            ->where('hotel_id', $hotel->id)
            ->orderBy('room_number');
        if (isset($data['room_id'])) {
            // An unknown or foreign room must never disclose inventory.
            HotelRoom::query()->where('hotel_id', $hotel->id)->findOrFail($data['room_id']);
            $rooms->whereKey($data['room_id']);
        }
        if (isset($data['category'])) {
            $rooms->where('category', $data['category']);
        }
        $page = $rooms->paginate(50);
        $rows = $page->getCollection()->map(function (HotelRoom $room) use ($hotel, $data, $inventory): array {
            $reasons = $inventory->reasons($hotel, $room, $data['arrival_date'], $data['departure_date']);
            return [
                'room_id' => $room->id,
                'room_number' => $room->room_number,
                'category' => $room->category,
                'physical_status' => $room->room_status,
                'locally_available' => $reasons === [],
                'reason_codes' => $reasons,
            ];
        })->values()->all();

        return response()->json([
            'hotel_id' => $hotel->id,
            'timezone' => $hotel->timezone,
            'arrival_date' => $data['arrival_date'],
            'departure_date' => $data['departure_date'],
            'interval_semantics' => '[arrival_date, departure_date)',
            'inventory_scope' => 'atlas_local_assigned_rooms_v1',
            'external_allotments_checked' => false,
            'pricing_checked' => false,
            'subject_to_confirmation' => true,
            'data' => $rows,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }
}
