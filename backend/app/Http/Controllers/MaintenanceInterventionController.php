<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceAsset;
use App\Models\MaintenanceIntervention;
use App\Models\MaintenanceSite;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MaintenanceInterventionController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('admin');
        $request->validate(['page' => 'sometimes|integer|min:1']);
        return MaintenanceIntervention::query()->latest('id')->paginate(20);
    }

    public function show(int $id)
    {
        Gate::authorize('admin');
        return MaintenanceIntervention::query()->findOrFail($id);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'asset_id' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'category' => ['required', Rule::in(['breakdown', 'inspection', 'preventive', 'other'])],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'critical'])],
            'status' => ['prohibited'],
            'submitted_by' => ['prohibited'],
            'company_id' => ['prohibited'],
        ]);
        $site = MaintenanceSite::query()->where('active', true)->findOrFail($data['site_id']);
        if (isset($data['asset_id'])) {
            MaintenanceAsset::query()
                ->where('site_id', $site->id)
                ->where('status', 'in_service')
                ->findOrFail($data['asset_id']);
        }
        return DB::transaction(function () use ($request, $data) {
            $record = MaintenanceIntervention::create(array_merge($data, [
                'status' => 'new',
                'submitted_by' => $request->user()->id,
            ]));
            $this->event($record, null, 'new', $request->user()->id);
            Audit::record('maintenance.intervention.created', $record);
            return response()->json($record, 201);
        });
    }

    public function transition(Request $request, int $id)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'status' => ['required', Rule::in(['qualified', 'accepted', 'rejected'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'company_id' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ]);
        return DB::transaction(function () use ($request, $data, $id) {
            $record = MaintenanceIntervention::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $next = $data['status'];
            $previous = $record->status;
            abort_unless(
                ($previous === 'new' && $next === 'qualified') ||
                ($previous === 'qualified' && in_array($next, ['accepted', 'rejected'], true)),
                409,
                'Transition de demande d’intervention interdite.'
            );
            $update = ['status' => $next];
            if ($next === 'qualified') {
                $update['qualified_by'] = $request->user()->id;
                $update['qualified_at'] = now();
            }
            $record->update($update);
            $this->event($record, $previous, $next, $request->user()->id, $data['reason']);
            Audit::record('maintenance.intervention.'.$next, $record);
            return response()->json($record);
        });
    }

    public function history(int $id)
    {
        Gate::authorize('admin');
        $record = MaintenanceIntervention::query()->findOrFail($id);
        return DB::table('maintenance_intervention_events')
            ->where('company_id', $record->company_id)
            ->where('intervention_id', $record->id)
            ->orderBy('id')
            ->get(['id', 'actor_id', 'from_status', 'to_status', 'reason', 'created_at']);
    }

    private function event(MaintenanceIntervention $record, ?string $previous, string $next, int $actor, ?string $reason = null): void
    {
        DB::table('maintenance_intervention_events')->insert([
            'company_id' => $record->company_id,
            'intervention_id' => $record->id,
            'actor_id' => $actor,
            'from_status' => $previous,
            'to_status' => $next,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
