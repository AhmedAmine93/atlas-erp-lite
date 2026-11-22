<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class MaintenanceWorkOrderController extends Controller
{
    public function index()
    {
        Gate::authorize('admin');
        return MaintenanceWorkOrder::query()->latest('id')->paginate(20);
    }

    /**
     * One work order per accepted DI in this initial vertical slice.
     * The DI lock serializes concurrent creation, while the DB UNIQUE
     * (company_id, maintenance_request_id) protects all other writers.
     */
    public function createFromRequest(Request $request, int $id)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'due_at' => 'nullable|date',
            'summary' => 'nullable|string|max:2000',
            'company_id' => 'prohibited',
            'asset_id' => 'prohibited',
            'status' => 'prohibited',
        ]);
        return DB::transaction(function () use ($id, $data) {
            $di = MaintenanceRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless($di->status === 'qualified', 409, 'DI non qualifiée.');
            $existing = MaintenanceWorkOrder::query()
                ->where('maintenance_request_id', $di->id)->first();
            if ($existing) {
                return response()->json($existing);
            }
            $order = MaintenanceWorkOrder::create([
                'maintenance_request_id' => $di->id,
                'asset_id' => $di->asset_id,
                'priority' => $di->priority,
                'summary' => $data['summary'] ?? $di->title,
                'due_at' => $data['due_at'] ?? null,
                'status' => 'open',
            ]);
            Audit::record('maintenance.work_order.created', $order);
            return response()->json($order, 201);
        }, 3);
    }

    public function start(int $id)
    {
        Gate::authorize('admin');
        return DB::transaction(function () use ($id) {
            $order = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($id);
            abort_unless($order->status === 'open', 409, 'BT non démarrable.');
            $order->status = 'in_progress';
            $order->save();
            Audit::record('maintenance.work_order.started', $order);
            return response()->json($order);
        });
    }

    /** Closure requires a human-recorded outcome, not an automatic transition. */
    public function complete(Request $request, int $id)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'resolution' => 'required|string|min:10|max:5000',
        ]);
        return DB::transaction(function () use ($id, $data) {
            $order = MaintenanceWorkOrder::query()->lockForUpdate()->findOrFail($id);
            abort_unless($order->status === 'in_progress', 409, 'BT non clôturable.');
            $order->resolution = $data['resolution'];
            $order->status = 'completed';
            $order->completed_at = now();
            $order->save();
            Audit::record('maintenance.work_order.completed', $order);
            return response()->json($order);
        });
    }
}
