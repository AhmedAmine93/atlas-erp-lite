<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\SupportIncident;
use App\Models\SupportIncidentEvent;
use App\Models\SupportSlaEscalation;
use App\Services\Audit;
use App\Services\SupportSla;
use App\Services\TenantContext;
use App\Services\TenantRoles;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportIncidentController extends Controller
{
    private const STATUSES = ['open', 'investigating', 'resolved', 'closed'];
    private const SEVERITIES = ['P1', 'P2', 'P3', 'P4'];

    private function assignmentRule()
    {
        $tenantId = app(TenantContext::class)->requireId();
        $roleIds = Role::whereIn('name', ['admin', 'support_n1', 'support_n2', 'support_n3', 'support_manager'])->pluck('id')->all();

        return Rule::exists('company_user', 'user_id')
            ->where(fn ($query) => $query->where('company_id', $tenantId)->whereIn('role_id', $roleIds));
    }

    private function decorate(SupportIncident $incident): SupportIncident
    {
        $sla = app(SupportSla::class);
        $incident->setAttribute('response_sla_status',
            $sla->status($incident->first_responded_at, $incident->response_due_at));
        $incident->setAttribute('resolution_sla_status',
            $sla->status($incident->resolved_at, $incident->resolution_due_at));

        return $incident;
    }

    private function event(SupportIncident $incident, string $action, ?string $before = null, ?string $after = null, ?string $message = null): void
    {
        SupportIncidentEvent::create([
            'support_incident_id' => $incident->id,
            'actor_id' => auth()->id(),
            'action' => $action,
            'from_status' => $before,
            'to_status' => $after,
            'message' => $message,
        ]);
    }

    public function index(Request $request)
    {
        Gate::authorize('support');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'severity' => ['nullable', Rule::in(self::SEVERITIES)],
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        $page = SupportIncident::query()->with(['reporter:id,name', 'assignee:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $term = '%'.mb_strtolower($v).'%';
                $q->where(fn ($inner) => $inner
                    ->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$term]));
            })
            ->latest('id')->paginate(20);

        $page->getCollection()->transform(fn ($incident) => $this->decorate($incident));

        return $page;
    }

    public function show(int $id)
    {
        Gate::authorize('support');

        return $this->decorate(SupportIncident::with([
            'reporter:id,name',
            'assignee:id,name',
            'events.actor:id,name',
        ])->findOrFail($id));
    }

    public function store(Request $request)
    {
        Gate::authorize('support');
        $data = $request->validate([
            'title' => 'required|string|max:180',
            'description' => 'required|string|max:10000',
            'severity' => ['required', Rule::in(self::SEVERITIES)],
            'environment' => ['required', Rule::in(['demo', 'dev', 'staging', 'production'])],
            'assigned_to' => ['nullable', 'integer', $this->assignmentRule()],
        ]);

        return DB::transaction(function () use ($request, $data) {
            $createdAt = CarbonImmutable::now();
            $sla = app(SupportSla::class)->targets($data['severity'], $createdAt);
            $incident = SupportIncident::create([
                ...$data,
                ...$sla,
                'reference' => 'INC-'.strtoupper(bin2hex(random_bytes(6))),
                'reported_by' => $request->user()->id,
                'status' => 'open',
                // Persist explicitly so the immediate API response is consistent
                // with the database default before Eloquent refreshes the model.
                'escalation_level' => app(TenantRoles::class)->role($request->user())?->name === 'support_n1' ? 1 : 2,
            ]);
            $this->event($incident, 'created', null, 'open');
            Audit::record('support.incident_created', $incident, [
                'severity' => $incident->severity,
            ]);

            return response()->json($this->decorate($incident), 201);
        }, 3);
    }

    public function transition(Request $request, int $id)
    {
        Gate::authorize('support');
        $data = $request->validate([
            'status' => ['required', Rule::in(['investigating', 'resolved', 'closed'])],
            'resolution' => 'nullable|string|max:10000',
            'assigned_to' => ['sometimes', 'nullable', 'integer', $this->assignmentRule()],
        ]);

        return DB::transaction(function () use ($data, $id) {
            $incident = SupportIncident::lockForUpdate()->findOrFail($id);
            $transitions = [
                'open' => ['investigating'],
                'investigating' => ['resolved'],
                'resolved' => ['closed'],
                'closed' => [],
            ];
            if (! in_array($data['status'], $transitions[$incident->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Transition de statut non autorisée.',
                ]);
            }
            if ($data['status'] === 'resolved' && trim($data['resolution'] ?? '') === '') {
                throw ValidationException::withMessages([
                    'resolution' => 'Une résolution est obligatoire.',
                ]);
            }

            $before = $incident->status;
            $incident->status = $data['status'];
            if (array_key_exists('assigned_to', $data)) {
                $incident->assigned_to = $data['assigned_to'];
            }
            if ($data['status'] === 'investigating') {
                $incident->first_responded_at ??= now();
            } elseif ($data['status'] === 'resolved') {
                $incident->resolution = trim($data['resolution']);
                $incident->resolved_at = now();
            } elseif ($data['status'] === 'closed') {
                $incident->closed_at = now();
            }
            $incident->save();
            $this->event($incident, 'status_changed', $before, $incident->status);
            Audit::record('support.incident_transitioned', $incident, [
                'from' => $before, 'to' => $incident->status,
            ]);

            return $this->decorate($incident->load(['reporter:id,name', 'assignee:id,name']));
        }, 3);
    }

    public function comment(Request $request, int $id)
    {
        Gate::authorize('support');
        $data = $request->validate(['message' => 'required|string|min:3|max:5000']);

        return DB::transaction(function () use ($data, $id) {
            $incident = SupportIncident::findOrFail($id);
            $this->event($incident, 'commented', null, null, $data['message']);
            Audit::record('support.incident_commented', $incident);

            return response()->json(['message' => 'Commentaire ajouté.'], 201);
        }, 3);
    }

    public function escalate(Request $request, int $id)
    {
        Gate::authorize('support');
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:2000',
            'assigned_to' => ['sometimes', 'nullable', 'integer', $this->assignmentRule()],
        ]);

        return DB::transaction(function () use ($data, $id, $request) {
            $incident = SupportIncident::lockForUpdate()->findOrFail($id);
            abort_if($incident->status === 'closed', 409, 'Un incident clôturé ne peut pas être escaladé.');
            abort_if($incident->escalation_level >= 3, 409, 'Incident déjà escaladé au N3.');

            $incident->escalation_level++;
            $incident->escalated_at = now();
            if (array_key_exists('assigned_to', $data)) {
                $incident->assigned_to = $data['assigned_to'];
            }
            $incident->save();
            $level = (int) $incident->escalation_level;
            $this->event($incident, 'escalated_n'.$level, null, null, $data['reason']);
            SupportSlaEscalation::create([
                'incident_id' => $incident->id,
                'raised_by' => $request->user()->id,
                'target_level' => $level,
                'status' => 'pending',
                'reason' => $data['reason'],
            ]);
            Audit::record('support.incident_escalated', $incident, ['target_level' => $level]);

            return $this->decorate($incident->load(['reporter:id,name', 'assignee:id,name']));
        }, 3);
    }

    /**
     * A closed/resolved incident may be reopened without overwriting its
     * original resolution timestamp or erasing any historical event.
     */
    public function reopen(Request $request, int $id)
    {
        Gate::authorize('support');
        $data = $request->validate(['reason' => 'required|string|min:5|max:2000']);
        return DB::transaction(function () use ($request, $id, $data) {
            $incident = SupportIncident::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($incident->status, ['resolved', 'closed'], true), 409, 'Seul un incident résolu ou clôturé peut être rouvert.');
            $before = $incident->status;
            $incident->status = 'investigating';
            // Keep the first response/resolution snapshots for SLA audit.
            $incident->closed_at = null;
            $incident->save();
            $this->event($incident, 'reopened', $before, 'investigating', $data['reason']);
            Audit::record('support.incident_reopened', $incident, ['from' => $before]);
            return $this->decorate($incident->load(['reporter:id,name','assignee:id,name']));
        }, 3);
    }

    public function summary()
    {
        Gate::authorize('support');
        $query = SupportIncident::query();

        return [
            'open' => (clone $query)->whereIn('status', ['open', 'investigating'])->count(),
            'escalated_n3' => (clone $query)->where('escalation_level', 3)->where('status', '!=', 'closed')->count(),
            'response_sla_breached' => (clone $query)
                ->whereNotNull('response_due_at')->where('response_due_at', '<', now())
                ->where(fn ($q) => $q->whereNull('first_responded_at')
                    ->orWhereColumn('first_responded_at', '>', 'response_due_at'))->count(),
            'resolution_sla_breached' => (clone $query)
                ->whereNotNull('resolution_due_at')->where('resolution_due_at', '<', now())
                ->where(fn ($q) => $q->whereNull('resolved_at')
                    ->orWhereColumn('resolved_at', '>', 'resolution_due_at'))->count(),
        ];
    }
}
