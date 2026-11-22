<?php

namespace App\Services;

use App\Models\HotelBooking;
use App\Models\HotelProperty;
use App\Models\HotelRoom;
use App\Models\HotelRoomBlock;

final class HotelRoomInventory
{
    /**
     * Local ATLAS room allocation only. This does NOT include external
     * allotments, unsynchronized channels, pricing or unassigned room types.
     * Date intervals are half open [arrival, departure) in hotel business dates.
     * Caller MUST lock the hotel_rooms row before committing a booking/block.
     *
     * @return list<string>
     */
    public function reasons(
        HotelProperty $hotel,
        HotelRoom $room,
        string $arrival,
        string $departure,
        ?int $excludeBookingId = null,
    ): array {
        $reasons = [];

        if (! $hotel->active) {
            $reasons[] = 'HOTEL_INACTIVE';
        }
        if ($room->room_status !== 'available') {
            $reasons[] = 'ROOM_PHYSICALLY_BLOCKED';
        }

        $bookings = HotelBooking::query()
            ->where('hotel_id', $hotel->id)
            ->where('room_id', $room->id)
            ->whereIn('status', ['confirmed', 'checked_in'])
            ->where('arrival_date', '<', $departure)
            ->where('departure_date', '>', $arrival);
        if ($excludeBookingId !== null) {
            $bookings->where('id', '!=', $excludeBookingId);
        }
        if ($bookings->exists()) {
            $reasons[] = 'OCCUPIED_OR_CONFIRMED';
        }

        $blocks = HotelRoomBlock::query()
            ->where('hotel_id', $hotel->id)
            ->where('room_id', $room->id)
            ->where('status', 'active')
            ->where('starts_on', '<', $departure)
            ->where('ends_on', '>', $arrival)
            ->where(function ($query): void {
                $query->where('kind', '!=', 'hold')
                    ->orWhere('expires_at', '>', now());
            })->pluck('kind');

        foreach ($blocks as $kind) {
            $code = match ($kind) {
                'hold' => 'ACTIVE_HOLD',
                'maintenance' => 'MAINTENANCE_WINDOW',
                'restriction' => 'RESTRICTION',
                default => 'UNRECOGNIZED_BLOCK',
            };
            $reasons[] = $code;
        }

        return array_values(array_unique($reasons));
    }
}
