<?php

namespace App\Http\Controllers;

use App\Models\HotelHousekeepingTask;
use App\Models\HotelProperty;
use App\Models\HotelRoom;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class HotelHousekeepingController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'hotel_id' => 'nullable|integer|min:1',
            'business_date' => 'nullable|date_format:Y-m-d',
            'status' => 'nullable|in:dirty,in_progress,clean,inspected',
        ]);
        $query = HotelHousekeepingTask::query()
            ->orderByDesc('business_date')->orderBy('room_id');
        if (isset($data['hotel_id'])) {
            HotelProperty::query()->findOrFail($data['hotel_id']);
            $query->where('hotel_id', $data['hotel_id']);
        }
        if (isset($data['business_date'])) {
            $query->whereDate('business_date', $data['business_date']);
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        return $query->paginate(50);
    }

    public function roomDay(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'hotel_id' => 'required|integer|min:1', 'business_date' => 'required|date_format:Y-m-d',
            'company_id' => 'prohibited',
        ]);
        $hotel = HotelProperty::query()->findOrFail($data['hotel_id']);
        $today = \Carbon\CarbonImmutable::now($hotel->timezone)->toDateString();
        $rooms = DB::select(<<<'SQL'
            WITH dates AS (SELECT CAST(? AS date) AS day, CAST(? AS date) AS today)
            SELECT r.id, r.hotel_id, r.room_number, r.category, r.floor_code, r.room_status,
                t.id AS task_id, t.status AS housekeeping_status,
                COUNT(b.id) FILTER (WHERE COALESCE(b.checked_in_on,b.arrival_date) <= d.day
                    AND d.day <= d.today AND b.status IN ('checked_in','checked_out')
                    AND (b.status = 'checked_in' OR COALESCE(b.checked_out_on,b.departure_date) > d.day)) AS occupied,
                COUNT(b.id) FILTER (WHERE COALESCE(b.checked_in_on,b.arrival_date) = d.day) AS arrivals,
                COUNT(b.id) FILTER (WHERE COALESCE(b.checked_out_on,b.departure_date) = d.day) AS departures
            FROM hotel_rooms r CROSS JOIN dates d
            LEFT JOIN hotel_housekeeping_tasks t ON (t.company_id,t.hotel_id,t.room_id,t.business_date)
                = (r.company_id,r.hotel_id,r.id,d.day)
            LEFT JOIN hotel_bookings b ON (b.company_id,b.hotel_id,b.room_id) = (r.company_id,r.hotel_id,r.id)
                AND b.status IN ('confirmed','checked_in','checked_out') AND b.arrival_date <= d.day
                AND (b.status = 'checked_in' OR COALESCE(b.checked_out_on,b.departure_date) >= d.day)
            WHERE r.company_id = ? AND r.hotel_id = ?
            GROUP BY r.id, t.id ORDER BY r.floor_code, r.room_number, r.id
            SQL, [$data['business_date'], $today, $hotel->company_id, $hotel->id]);
        return response()->json(['business_date' => $data['business_date'], 'rooms' => $rooms]);
    }

    public function create(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'room_id' => 'required|integer|min:1',
            'business_date' => 'required|date_format:Y-m-d',
            'hotel_id' => 'prohibited',
            'status' => 'prohibited',
            'company_id' => 'prohibited',
            'created_by' => 'prohibited',
            'inspected_by' => 'prohibited',
        ]);
        $room = HotelRoom::query()->findOrFail($data['room_id']);
        HotelProperty::query()->findOrFail($room->hotel_id);
        try {
            return DB::transaction(function () use ($room, $data, $request) {
            $existing = HotelHousekeepingTask::query()
                ->where('room_id', $room->id)
                ->whereDate('business_date', $data['business_date'])->first();
            abort_if($existing !== null, 409, 'Une mission existe déjà pour cette chambre et cette journée.');
            $task = HotelHousekeepingTask::create([
                'hotel_id' => $room->hotel_id,
                'room_id' => $room->id,
                'business_date' => $data['business_date'],
                'status' => 'dirty',
                'created_by' => $request->user()->id,
            ]);
            DB::table('hotel_room_status_events')->insert([
                'company_id' => app(TenantContext::class)->requireId(),
                'task_id' => $task->id,
                'room_id' => $room->id,
                'from_status' => 'none',
                'to_status' => 'dirty',
                'actor_id' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('hotel.housekeeping.task.created', $task);
            return response()->json($task, 201);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) === '23505') {
                abort(409, 'Une mission existe déjà pour cette chambre et cette journée.');
            }
            throw $exception;
        }
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'status' => 'required|in:in_progress,clean,inspected',
            'reason' => 'nullable|string|max:300',
            'company_id' => 'prohibited',
            'room_id' => 'prohibited',
            'inspected_by' => 'prohibited',
        ]);
        $allowed = [
            'dirty' => 'in_progress',
            'in_progress' => 'clean',
            'clean' => 'inspected',
        ];
        return DB::transaction(function () use ($id, $data, $allowed, $request) {
            $task = HotelHousekeepingTask::query()->lockForUpdate()->findOrFail($id);
            abort_unless(($allowed[$task->status] ?? null) === $data['status'],
                409, 'Transition de housekeeping non autorisée.');
            $before = $task->status;
            $task->status = $data['status'];
            if ($data['status'] === 'inspected') {
                $task->inspected_at = now();
                $task->inspected_by = $request->user()->id;
            }
            $task->save();
            DB::table('hotel_room_status_events')->insert([
                'company_id' => app(TenantContext::class)->requireId(),
                'task_id' => $task->id, 'room_id' => $task->room_id,
                'from_status' => $before, 'to_status' => $task->status,
                'actor_id' => $request->user()->id,
                'reason' => $data['reason'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('hotel.housekeeping.task.transitioned', $task, [
                'from' => $before, 'to' => $task->status,
            ]);
            return response()->json($task);
        });
    }

    /**
     * Advisory readiness only. Booking confirmation does NOT yet integrate it.
     * A cleaned/inspected room is still unsellable if blocked/out_of_service.
     */
    public function readiness(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'room_id' => 'required|integer|min:1',
            'business_date' => 'required|date_format:Y-m-d',
        ]);
        $room = HotelRoom::query()->findOrFail($data['room_id']);
        $task = HotelHousekeepingTask::query()
            ->where('room_id', $room->id)
            ->whereDate('business_date', $data['business_date'])
            ->first();
        // These fields describe the physical and cleaning state only.
        // They are NOT booking availability: confirmed stays and holds are not evaluated here.
        $housekeepingReady = $task?->status === 'inspected';
        $readyForCheckin = $housekeepingReady && $room->room_status === 'available';
        return response()->json([
            'room_id' => $room->id,
            'hotel_id' => $room->hotel_id,
            'business_date' => $data['business_date'],
            'physical_status' => $room->room_status,
            'housekeeping_status' => $task?->status,
            'housekeeping_ready' => $housekeepingReady,
            'ready_for_checkin' => $readyForCheckin,
            // Deprecated: maintained for existing Next.js clients until a versioned migration.
            // Never use this legacy key to decide whether a reservation can be sold.
            'ready_for_sale' => $readyForCheckin,
            'advisory_only' => true,
        ]);
    }
}
