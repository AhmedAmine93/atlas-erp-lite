<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceSchedule;
use App\Models\MaintenanceTeam;
use App\Models\MaintenanceTechnician;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Services\Audit;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * P052/P068 — tenant-isolated first planning slice: one technician per
 * scheduled work order, one active slot per work order, no overlapping slots
 * for a technician (enforced by PostgreSQL exclusion constraint).
 */
final class MaintenancePlanningController extends Controller
{
    public function teams()
    {
        Gate::authorize('admin');
        return MaintenanceTeam::query()->orderBy('code')->paginate(50);
    }

    public function createTeam(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('maintenance_teams')
                ->where('company_id', app(TenantContext::class)->requireId())],
            'name' => 'required|string|max:180',
            'active' => 'sometimes|boolean',
            'company_id' => 'prohibited',
        ]);
        return DB::transaction(function () use ($data) {
            $team = MaintenanceTeam::create($data);
            Audit::record('maintenance.team.created', $team);
            return response()->json($team, 201);
        });
    }

    public function technicians()
    {
        Gate::authorize('admin');
        return MaintenanceTechnician::query()->with('team:id,name,code')
            ->orderBy('code')->paginate(50);
    }

    public function createTechnician(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('maintenance_technicians')
                ->where('company_id', app(TenantContext::class)->requireId())],
            'name' => 'required|string|max:180',
            'team_id' => 'nullable|integer|min:1',
            'active' => 'sometimes|boolean',
            'company_id' => 'prohibited',
        ]);
        if (isset($data['team_id'])) {
            $team = MaintenanceTeam::query()->findOrFail($data['team_id']);
            abort_unless($team->active, 409, 'Équipe désactivée.');
        }
        return DB::transaction(function () use ($data) {
            $tech = MaintenanceTechnician::create($data);
            Audit::record('maintenance.technician.created', $tech);
            return response()->json($tech, 201);
        });
    }

    public function slots(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'status' => ['nullable', Rule::in(['scheduled', 'cancelled'])],
        ]);
        $query = MaintenanceSchedule::query()
            ->with(['technician:id,code,name', 'workOrder:id,asset_id,status,summary'])
            ->orderBy('starts_at');
        if (isset($data['from'])) {
            $query->where('starts_at', '>=', CarbonImmutable::parse($data['from'])->startOfDay());
        }
        if (isset($data['to'])) {
            $query->where('starts_at', '<', CarbonImmutable::parse($data['to'])->addDay()->startOfDay());
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        return $query->paginate(50);
    }

    public function schedule(Request $request, int $id): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'technician_id' => 'required|integer|min:1',
            'starts_at' => 'required|date_format:Y-m-d\TH:i:sP',
            'ends_at' => 'required|date_format:Y-m-d\TH:i:sP',
            'company_id' => 'prohibited',
            'work_order_id' => 'prohibited',
            'status' => 'prohibited',
            'planned_by' => 'prohibited',
        ]);
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        $end = CarbonImmutable::parse($data['ends_at'])->utc();
        $duration = $start->diffInMinutes($end, false);
        if ($duration < 15 || $duration > 1440) {
            return response()->json(['message' => 'Créneau de 15 minutes à 24 heures requis.'], 422);
        }

        try {
            return DB::transaction(function () use ($id, $data, $start, $end, $request): JsonResponse {
                $workOrder = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($id);
                abort_unless($workOrder->status === 'open', 409, 'Seul un BT ouvert est planifiable.');
                $technician = MaintenanceTechnician::query()->lockForUpdate()
                    ->findOrFail($data['technician_id']);
                abort_unless($technician->active, 409, 'Technicien désactivé.');

                $existing = MaintenanceSchedule::query()
                    ->where('work_order_id', $workOrder->id)
                    ->where('status', 'scheduled')->first();
                if ($existing) {
                    if ($existing->technician_id === $technician->id
                        && $existing->starts_at->equalTo($start)
                        && $existing->ends_at->equalTo($end)) {
                        return response()->json($existing->load('technician'));
                    }
                    abort(409, 'Ce BT possède déjà une affectation active. Annulez-la avant de replanifier.');
                }

                $slot = MaintenanceSchedule::create([
                    'work_order_id' => $workOrder->id,
                    'technician_id' => $technician->id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'status' => 'scheduled',
                    'planned_by' => $request->user()->id,
                ]);
                Audit::record('maintenance.schedule.created', $slot, [
                    'work_order_id' => $workOrder->id,
                    'technician_id' => $technician->id,
                ]);
                return response()->json($slot->load('technician'), 201);
            }, 3);
        } catch (QueryException $exception) {
            $sqlstate = $exception->errorInfo[0] ?? (string) $exception->getCode();
            if (in_array($sqlstate, ['23P01', '23505'], true)) {
                return response()->json([
                    'message' => 'Créneau déjà occupé : ce technicien ou ce BT possède une réservation concurrente.',
                ], 409);
            }
            throw $exception;
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'reason' => 'required|string|min:10|max:300',
            'status' => 'prohibited',
            'company_id' => 'prohibited',
        ]);
        return DB::transaction(function () use ($id, $data, $request): JsonResponse {
            $slot = MaintenanceSchedule::query()->lockForUpdate()->findOrFail($id);
            abort_unless($slot->status === 'scheduled', 409, 'Ce créneau est déjà annulé.');
            $order = MaintenanceWorkOrder::query()->findOrFail($slot->work_order_id);
            abort_unless($order->status === 'open', 409, 'Un BT démarré ou terminé ne peut pas être déplanifié.');
            $slot->status = 'cancelled';
            $slot->cancellation_reason = trim($data['reason']);
            $slot->cancelled_by = $request->user()->id;
            $slot->cancelled_at = now();
            $slot->save();
            Audit::record('maintenance.schedule.cancelled', $slot, [
                'reason' => $slot->cancellation_reason,
            ]);
            return response()->json($slot);
        });
    }

    /** Read-only planning/technical KPIs; always bound to the active tenant. */
    public function report(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
        ]);
        $from = CarbonImmutable::parse($data['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? now()->addDays(30)->toDateString())->addDay()->startOfDay();
        if ($to <= $from || $from->diffInDays($to) > 366) {
            return response()->json(['message' => 'Période attendue : de 1 à 365 jours.'], 422);
        }

        $workOrders = MaintenanceWorkOrder::query()
            ->select('status')->selectRaw('COUNT(*)::integer AS total')
            ->groupBy('status')->pluck('total', 'status');
        $priorities = MaintenanceRequest::query()
            ->select('priority')->selectRaw('COUNT(*)::integer AS total')
            ->groupBy('priority')->pluck('total', 'priority');
        $range = MaintenanceSchedule::query()
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $to);
        $statuses = (clone $range)
            ->select('status')->selectRaw('COUNT(*)::integer AS total')
            ->groupBy('status')->pluck('total', 'status');
        $byTechnician = (clone $range)
            ->where('status', 'scheduled')
            ->select('technician_id')->selectRaw('COUNT(*)::integer AS total')
            ->groupBy('technician_id')->orderByDesc('total')->limit(15)->get()
            ->map(function ($row): array {
                $technician = MaintenanceTechnician::query()->find($row->technician_id);
                return [
                    'technician_id' => (int) $row->technician_id,
                    'name' => $technician?->name ?? 'Technicien inactif',
                    'scheduled' => (int) $row->total,
                ];
            })->all();

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString()],
            'generated_at' => now()->toIso8601String(),
            'work_orders_by_status' => $workOrders,
            'requests_by_priority' => $priorities,
            'overdue_work_orders' => MaintenanceWorkOrder::query()
                ->whereNotIn('status', ['completed'])
                ->where('due_at', '<', now())->count(),
            'schedules_by_status' => $statuses,
            'technicians_scheduled' => $byTechnician,
        ]);
    }
}
