<?php

namespace App\Http\Controllers;

use App\Models\SupportIncident;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportIncidentController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('admin');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'investigating', 'resolved', 'closed'])],
            'severity' => ['nullable', Rule::in(['P1', 'P2', 'P3', 'P4'])],
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        return SupportIncident::query()
            ->with(['reporter:id,name', 'assignee:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['search'] ?? null, function ($q, $v) {
                $term = '%'.mb_strtolower($v).'%';
                $q->where(fn ($inner) => $inner->whereRaw('LOWER(title) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$term]));
            })
            ->latest()->paginate(20);
    }

    public function show(int $id)
    {
        Gate::authorize('admin');

        return SupportIncident::with(['reporter:id,name', 'assignee:id,name'])->findOrFail($id);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'title' => 'required|string|max:180',
            'description' => 'required|string|max:10000',
            'severity' => ['required', Rule::in(['P1', 'P2', 'P3', 'P4'])],
            'environment' => ['required', Rule::in(['demo', 'dev', 'staging', 'production'])],
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        return DB::transaction(function () use ($request, $data) {
            $incident = SupportIncident::create([
                ...$data,
                'reference' => 'INC-'.strtoupper(bin2hex(random_bytes(6))),
                'reported_by' => $request->user()->id,
                'status' => 'open',
            ]);
            Audit::record('support.incident_created', $incident, ['severity' => $incident->severity]);

            return response()->json($incident, 201);
        });
    }

    public function transition(Request $request, int $id)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'status' => ['required', Rule::in(['investigating', 'resolved', 'closed'])],
            'resolution' => 'nullable|string|max:10000',
            'assigned_to' => 'nullable|integer|exists:users,id',
        ]);

        return DB::transaction(function () use ($data, $id) {
            $incident = SupportIncident::query()->lockForUpdate()->findOrFail($id);
            $next = [
                'open' => ['investigating'],
                'investigating' => ['resolved'],
                'resolved' => ['closed'],
                'closed' => [],
            ];
            if (!in_array($data['status'], $next[$incident->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'Transition de statut non autorisée.']);
            }
            if ($data['status'] === 'resolved' && trim($data['resolution'] ?? '') === '') {
                throw ValidationException::withMessages(['resolution' => 'Une résolution est obligatoire.']);
            }
            $before = $incident->status;
            $incident->status = $data['status'];
            if (array_key_exists('assigned_to', $data)) {
                $incident->assigned_to = $data['assigned_to'];
            }
            if ($data['status'] === 'resolved') {
                $incident->resolution = $data['resolution'];
                $incident->resolved_at = now();
            }
            $incident->save();
            Audit::record('support.incident_transitioned', $incident, ['from' => $before, 'to' => $incident->status]);

            return $incident;
        });
    }
}
