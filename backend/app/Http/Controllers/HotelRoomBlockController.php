<?php

namespace App\Http\Controllers;

use App\Models\HotelProperty;
use App\Models\HotelRoom;
use App\Models\HotelRoomBlock;
use App\Services\Audit;
use App\Services\HotelRoomInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class HotelRoomBlockController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'hotel_id' => 'required|integer|min:1',
            'room_id' => 'nullable|integer|min:1',
            'company_id' => 'prohibited',
        ]);
        HotelProperty::query()->findOrFail($data['hotel_id']);
        $query = HotelRoomBlock::query()->where('hotel_id', $data['hotel_id'])
            ->orderByDesc('id');
        if (isset($data['room_id'])) {
            HotelRoom::query()->where('hotel_id', $data['hotel_id'])->findOrFail($data['room_id']);
            $query->where('room_id', $data['room_id']);
        }
        return $query->paginate(50);
    }

    /**
     * Room row lock serializes block creation with booking confirmation.
     * Block rows themselves are never a replacement for the booking GiST rule.
     */
    public function create(Request $request, HotelRoomInventory $inventory): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'room_id' => 'required|integer|min:1',
            'kind' => 'required|in:hold,maintenance,restriction',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after:starts_on',
            'expires_at' => 'nullable|date|after:now',
            'reason' => 'required|string|max:300',
            'company_id' => 'prohibited',
            'hotel_id' => 'prohibited',
            'status' => 'prohibited',
            'created_by' => 'prohibited',
        ]);
        if ($data['kind'] === 'hold') {
            abort_unless(isset($data['expires_at']), 422, 'Un blocage temporaire exige une expiration.');
        } else {
            abort_if(isset($data['expires_at']), 422, 'Seule une retenue temporaire peut expirer.');
        }

        return DB::transaction(function () use ($data, $request, $inventory): JsonResponse {
            $room = HotelRoom::query()->lockForUpdate()->findOrFail($data['room_id']);
            $hotel = HotelProperty::query()->findOrFail($room->hotel_id);
            $reasons = $inventory->reasons($hotel, $room, $data['starts_on'], $data['ends_on']);
            abort_if($reasons !== [], 409, 'Créneau indisponible : '.implode(', ', $reasons));

            $block = HotelRoomBlock::create([
                'hotel_id' => $room->hotel_id,
                'room_id' => $room->id,
                'kind' => $data['kind'],
                'status' => 'active',
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'expires_at' => $data['kind'] === 'hold' ? $data['expires_at'] : null,
                'reason' => $data['reason'],
                'created_by' => $request->user()->id,
            ]);
            Audit::record('hotel.room.block.created', $block, ['kind' => $block->kind]);
            return response()->json($block, 201);
        }, 3);
    }

    public function release(Request $request, int $id): JsonResponse
    {
        Gate::authorize('admin');
        $request->validate(['company_id' => 'prohibited', 'status' => 'prohibited']);
        return DB::transaction(function () use ($id, $request): JsonResponse {
            $block = HotelRoomBlock::query()->lockForUpdate()->findOrFail($id);
            abort_unless($block->status === 'active', 409, 'Blocage déjà levé.');
            $block->status = 'released';
            $block->released_at = now();
            $block->released_by = $request->user()->id;
            $block->save();
            Audit::record('hotel.room.block.released', $block);
            return response()->json($block);
        }, 3);
    }
}
