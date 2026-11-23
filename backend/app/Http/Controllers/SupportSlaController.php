<?php

namespace App\Http\Controllers;

use App\Models\SupportIncident;
use App\Models\SupportSlaEscalation;
use App\Models\SupportSlaPolicy;
use App\Services\Audit;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SupportSlaController extends Controller
{
    public function policies()
    {
        Gate::authorize('itsmRead');
        return SupportSlaPolicy::orderByDesc('effective_from')->paginate(30);
    }

    public function createPolicy(Request $request)
    {
        Gate::authorize('itsmAdmin');
        $data = $request->validate([
            'severity' => ['required', Rule::in(['P1', 'P2', 'P3', 'P4'])],
            'response_minutes' => 'required|integer|min:1|max:525600',
            'resolution_minutes' => 'required|integer|min:1|max:525600',
            'calendar' => ['required', Rule::in(['continuous'])],
            'effective_from' => 'required|date',
        ]);
        abort_if($data['response_minutes'] > $data['resolution_minutes'], 422, 'Réponse après résolution.');
        return DB::transaction(function () use ($request, $data) {
            $version = (int) (SupportSlaPolicy::where('severity', $data['severity'])
                ->orderByDesc('version')->lockForUpdate()->value('version') ?? 0) + 1;
            $item = SupportSlaPolicy::create([
                ...$data, 'version' => $version, 'published' => false, 'created_by' => $request->user()->id,
            ]);
            Audit::record('support.sla.drafted', $item);
            return response()->json($item, 201);
        }, 3);
    }

    public function publish(Request $request, int $id)
    {
        Gate::authorize('itsmAdmin');
        return DB::transaction(function () use ($id) {
            $item = SupportSlaPolicy::lockForUpdate()->findOrFail($id);
            abort_if($item->published, 409, 'Déjà publié.');
            // This version affects future incidents only.
            $item->published = true;
            $item->save();
            Audit::record('support.sla.published', $item);
            return $item;
        }, 3);
    }

    public function escalations(Request $request)
    {
        Gate::authorize('itsmRead');
        return SupportSlaEscalation::query()->latest('id')->paginate(30);
    }

    public function raise(Request $request)
    {
        Gate::authorize('itsmManage');
        $data = $request->validate([
            'incident_id' => 'required|integer|min:1',
            'target_level' => 'required|integer|in:2,3',
            'reason' => 'required|string|min:5|max:2000',
        ]);
        return DB::transaction(function () use ($request, $data) {
            $incident = SupportIncident::lockForUpdate()->findOrFail($data['incident_id']);
            abort_if($incident->status === 'closed', 409, 'Incident clôturé.');
            $item = SupportSlaEscalation::create([
                ...$data, 'raised_by' => $request->user()->id,
                'status' => 'pending',
            ]);
            Audit::record('support.sla.escalation_raised', $item);
            return response()->json($item, 201);
        }, 3);
    }

    public function acknowledge(Request $request, int $id)
    {
        Gate::authorize('itsmManage');
        return DB::transaction(function () use ($request, $id) {
            $item = SupportSlaEscalation::lockForUpdate()->findOrFail($id);
            abort_unless($item->status === 'pending', 409, 'Escalade déjà acquittée.');
            $item->status = 'acknowledged';
            $item->acknowledged_by = $request->user()->id;
            $item->acknowledged_at = now();
            $item->save();
            Audit::record('support.sla.escalation_acknowledged', $item);
            return $item;
        }, 3);
    }

    public function metrics(Request $request)
    {
        Gate::authorize('itsmRead');
        $items = SupportIncident::select([
            'id', 'reference', 'severity', 'status', 'created_at', 'first_responded_at',
            'resolved_at', 'response_due_at', 'resolution_due_at',
        ])->latest('id')->paginate(30);
        $items->getCollection()->transform(function ($item) {
            $created = CarbonImmutable::parse($item->created_at);
            $item->response_minutes = $item->first_responded_at
                ? (int) $created->diffInMinutes(CarbonImmutable::parse($item->first_responded_at))
                : null;
            $item->resolution_minutes = $item->resolved_at
                ? (int) $created->diffInMinutes(CarbonImmutable::parse($item->resolved_at))
                : null;
            $item->response_breached = $item->response_due_at
                && CarbonImmutable::parse($item->first_responded_at ?? now())->greaterThan(CarbonImmutable::parse($item->response_due_at));
            $item->resolution_breached = $item->resolution_due_at
                && CarbonImmutable::parse($item->resolved_at ?? now())->greaterThan(CarbonImmutable::parse($item->resolution_due_at));
            return $item;
        });
        return $items;
    }
}
