<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceAsset;
use App\Models\MaintenancePreventiveOccurrence;
use App\Models\MaintenancePreventivePlan;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Services\Audit;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MaintenancePreventiveController extends Controller
{
    public function index()
    {
        Gate::authorize('admin');

        return MaintenancePreventivePlan::query()->orderBy('next_due_on')->paginate(50);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $companyId = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'asset_id' => 'required|integer|min:1',
            'code' => ['required', 'string', 'max:60', Rule::unique('maintenance_preventive_plans')->where('company_id', $companyId)],
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:2000',
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'critical'])],
            'interval_days' => 'required|integer|between:1,3650',
            'next_due_on' => 'required|date_format:Y-m-d',
            'active' => 'prohibited',
            'company_id' => 'prohibited',
            'created_by' => 'prohibited',
        ]);
        MaintenanceAsset::query()->findOrFail($data['asset_id']);

        return DB::transaction(function () use ($request, $data) {
            $plan = MaintenancePreventivePlan::create(array_merge($data, [
                'active' => true,
                'created_by' => $request->user()->id,
            ]));
            Audit::record('maintenance.preventive.plan.created', $plan);
            return response()->json($plan, 201);
        });
    }

    public function occurrences(Request $request)
    {
        Gate::authorize('admin');
        $query = MaintenancePreventiveOccurrence::query()->orderByDesc('due_on');
        if ($request->has('plan_id')) {
            $data = $request->validate(['plan_id' => 'required|integer|min:1']);
            $query->where('plan_id', $data['plan_id']);
        }
        return $query->paginate(50);
    }

    /**
     * Bounded, explicit trigger: one due occurrence per plan on each execution.
     * Production scheduling / counters / telemetry are separate P053 stages.
     *
     * Lock the PLAN so two simultaneously executing runs cannot create the
     * same due date. The DB's (company_id, plan_id, due_on) UNIQUE constraint
     * is the second-line idempotency guard.
     */
    public function run(Request $request): JsonResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'through_date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ]);
        $throughDate = $data['through_date'] ?? today()->toDateString();
        $candidateIds = MaintenancePreventivePlan::query()
            ->where('active', true)
            ->whereDate('next_due_on', '<=', $throughDate)
            ->orderBy('next_due_on')->orderBy('id')
            ->limit(20)->pluck('id');

        $generated = [];
        foreach ($candidateIds as $planId) {
            $occurrence = DB::transaction(function () use ($planId, $throughDate, $request): ?MaintenancePreventiveOccurrence {
                $plan = MaintenancePreventivePlan::query()->lockForUpdate()->find($planId);
                if (! $plan || ! $plan->active || $plan->next_due_on->toDateString() > $throughDate) {
                    return null;
                }
                $dueOn = CarbonImmutable::parse($plan->next_due_on)->toDateString();
                $existing = MaintenancePreventiveOccurrence::query()
                    ->where('plan_id', $plan->id)->whereDate('due_on', $dueOn)->first();
                if ($existing) {
                    // Recover cursor if a previous worker created the occurrence.
                    $plan->next_due_on = CarbonImmutable::parse($dueOn)->addDays($plan->interval_days)->toDateString();
                    $plan->save();
                    return null;
                }

                $reference = 'PM-'.$plan->id.'-'.str_replace('-', '', $dueOn);
                $di = MaintenanceRequest::create([
                    'asset_id' => $plan->asset_id,
                    'reference' => $reference,
                    'title' => $plan->title,
                    'description' => $plan->description,
                    'priority' => $plan->priority,
                    'status' => 'qualified',
                    'created_by' => $request->user()->id,
                ]);
                $bt = MaintenanceWorkOrder::create([
                    'maintenance_request_id' => $di->id,
                    'asset_id' => $plan->asset_id,
                    'priority' => $plan->priority,
                    'summary' => $plan->title,
                    'due_at' => CarbonImmutable::parse($dueOn)->endOfDay(),
                    'status' => 'open',
                ]);
                $occurrence = MaintenancePreventiveOccurrence::create([
                    'plan_id' => $plan->id,
                    'due_on' => $dueOn,
                    'maintenance_request_id' => $di->id,
                    'work_order_id' => $bt->id,
                ]);
                $plan->next_due_on = CarbonImmutable::parse($dueOn)->addDays($plan->interval_days)->toDateString();
                $plan->save();
                Audit::record('maintenance.preventive.occurrence.generated', $occurrence, [
                    'plan_id' => $plan->id, 'request_id' => $di->id, 'work_order_id' => $bt->id,
                ]);
                return $occurrence;
            }, 3);
            if ($occurrence !== null) {
                $generated[] = [
                    'id' => $occurrence->id,
                    'plan_id' => $occurrence->plan_id,
                    'due_on' => $occurrence->due_on->toDateString(),
                    'work_order_id' => $occurrence->work_order_id,
                ];
            }
        }

        return response()->json([
            'generated_count' => count($generated),
            'generated' => $generated,
            'batch_limit' => 20,
        ]);
    }
}
