<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * M18 — tenant-scoped operations control plane.
 *
 * This API records verified operator-supplied evidence. It does not execute
 * backups, restore databases, run arbitrary commands or trigger deployments.
 * External collectors and CI must use authenticated, contract-reviewed adapters.
 */
class OperationsController extends Controller
{
    private const RESOURCES = [
        'services' => 'ops_services',
        'observations' => 'ops_observations',
        'logs' => 'ops_logs',
        'jobs' => 'ops_jobs',
        'backups' => 'ops_backups',
        'deployments' => 'ops_deployments',
        'alerts' => 'ops_alerts',
    ];

    private const STATUS = [
        'jobs' => [
            'queued' => ['running', 'cancelled'],
            'running' => ['succeeded', 'failed'],
            'failed' => ['requalified'],
            'requalified' => ['queued', 'cancelled'],
        ],
        'backups' => [
            'recorded' => ['restore_verified', 'rejected'],
            'restore_verified' => [],
            'rejected' => [],
        ],
        'deployments' => [
            'planned' => ['staging_verified', 'rejected'],
            'staging_verified' => ['approved', 'rejected'],
            'approved' => ['promoted', 'rejected'],
            'promoted' => ['degraded', 'rolled_back'],
            'degraded' => ['rolled_back'],
            'rejected' => [],
            'rolled_back' => [],
        ],
        'alerts' => [
            'open' => ['acknowledged', 'resolved'],
            'acknowledged' => ['resolved'],
            'resolved' => [],
        ],
    ];

    private function authorizeRead(): void
    {
        Gate::authorize('opsRead');
    }

    private function authorizeWrite(): void
    {
        Gate::authorize('opsWrite');
    }

    private function tenant(): int
    {
        return app(TenantContext::class)->requireId();
    }

    private function table(string $resource): string
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404, 'Ressource inconnue.');
        return self::RESOURCES[$resource];
    }

    private function event(string $resource, int $id, string $action, ?string $from = null, ?string $to = null): void
    {
        DB::table('ops_events')->insert([
            'company_id' => $this->tenant(),
            'resource' => $resource,
            'resource_id' => $id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    private function redact(string $message): string
    {
        // Defence-in-depth, not a replacement for client-side prevention.
        $message = preg_replace('/(?i)\b(bearer\s+)[a-z0-9._~+\/-]+/', '$1[REDACTED]', $message);
        $message = preg_replace('/(?i)\b(password|secret|token|api[_-]?key)\s*[:=]\s*[^\s,;]+/', '$1=[REDACTED]', $message);
        $message = preg_replace('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', '[EMAIL]', $message);
        return mb_substr($message, 0, 2000);
    }

    public function overview()
    {
        $this->authorizeRead();
        $tenant = $this->tenant();
        $counts = [];
        foreach (self::RESOURCES as $resource => $table) {
            $counts[$resource] = DB::table($table)->where('company_id', $tenant)->count();
        }

        $services = DB::table('ops_services as s')->where('s.company_id', $tenant)
            ->select('s.id', 's.name', 's.code', 's.owner', 's.criticality')
            ->orderBy('s.name')->limit(100)->get()->map(function ($service) use ($tenant) {
                $latest = DB::table('ops_observations')->where('company_id', $tenant)
                    ->where('service_id', $service->id)->orderByDesc('observed_at')->orderByDesc('id')->first([
                        'state', 'layer', 'observed_at', 'source',
                    ]);
                $service->latest_observation = $latest;
                return $service;
            });

        return response()->json([
            'source' => 'recorded_evidence',
            'live_monitoring' => false,
            'counts' => $counts,
            'services' => $services,
            'open_alerts' => DB::table('ops_alerts')->where('company_id', $tenant)
                ->where('status', '!=', 'resolved')->count(),
            'failed_jobs' => DB::table('ops_jobs')->where('company_id', $tenant)
                ->where('status', 'failed')->count(),
        ]);
    }

    public function index(Request $request, string $resource)
    {
        $this->authorizeRead();
        $table = $this->table($resource);
        $filters = $request->validate([
            'status' => 'nullable|string|max:24',
            'search' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'correlation_id' => 'nullable|uuid',
        ]);
        $query = DB::table($table)->where('company_id', $this->tenant());
        if (isset($filters['status']) && in_array($resource, ['jobs', 'backups', 'deployments', 'alerts'], true)) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['correlation_id']) && in_array($resource, ['logs', 'jobs'], true)) {
            $query->where('correlation_id', $filters['correlation_id']);
        }
        $searchColumns = [
            'services' => ['name', 'code'],
            'logs' => ['component'],
            'jobs' => ['name'],
            'backups' => ['artifact_reference'],
            'deployments' => ['application'],
            'alerts' => ['title'],
            'observations' => ['source'],
        ];
        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(function ($q) use ($searchColumns, $resource, $term) {
                foreach ($searchColumns[$resource] as $field) {
                    $q->orWhereRaw('LOWER('.$field.') LIKE LOWER(?)', [$term]);
                }
            });
        }
        return $query->orderByDesc('id')->paginate(20);
    }

    public function show(string $resource, int $id)
    {
        $this->authorizeRead();
        $record = DB::table($this->table($resource))
            ->where('company_id', $this->tenant())->where('id', $id)->first();
        abort_unless($record, 404);
        $events = DB::table('ops_events')->where('company_id', $this->tenant())
            ->where('resource', $resource)->where('resource_id', $id)
            ->orderBy('id')->get();
        return response()->json(['record' => $record, 'events' => $events]);
    }

    public function store(Request $request, string $resource)
    {
        $this->authorizeWrite();
        $table = $this->table($resource);
        $common = [
            'company_id' => ['prohibited'],
            'id' => ['prohibited'],
            'status' => ['prohibited'],
            'version' => ['prohibited'],
            'created_by' => ['prohibited'],
            'recorded_by' => ['prohibited'],
        ];
        $rules = match ($resource) {
            'services' => [
                'code' => 'required|string|max:80|regex:/^[a-z0-9][a-z0-9_-]*$/',
                'name' => 'required|string|max:160',
                'owner' => 'required|string|max:160',
                'runbook_url' => 'nullable|url|max:500',
                'criticality' => ['required', Rule::in(['normal', 'high', 'critical'])],
            ],
            'observations' => [
                'service_id' => 'required|integer|min:1',
                'layer' => ['required', Rule::in(['technical', 'business', 'data'])],
                'state' => ['required', Rule::in(['healthy', 'degraded', 'down', 'unknown'])],
                'latency_ms' => 'nullable|integer|min:0|max:3600000',
                'source' => 'required|string|max:160',
                'observed_at' => 'required|date',
            ],
            'logs' => [
                'component' => 'required|string|max:100',
                'level' => ['required', Rule::in(['debug', 'info', 'warning', 'error', 'critical'])],
                'correlation_id' => 'required|uuid',
                'message' => 'required|string|max:2000',
                'occurred_at' => 'required|date',
            ],
            'jobs' => [
                'name' => 'required|string|max:120',
                'correlation_id' => 'required|uuid',
                'window_start' => 'required|date',
                'window_end' => 'required|date|after:window_start',
                'max_attempts' => 'required|integer|min:1|max:50',
                'runbook_url' => 'required|url|max:500',
                'idempotency_key' => 'required|uuid',
            ],
            'backups' => [
                'artifact_reference' => 'required|string|max:160',
                'environment' => ['required', Rule::in(['demo', 'dev', 'staging', 'production'])],
                'encrypted' => 'required|boolean',
                'completed_at' => 'required|date',
                'idempotency_key' => 'required|uuid',
            ],
            'deployments' => [
                'application' => 'required|string|max:120',
                'environment' => ['required', Rule::in(['demo', 'dev', 'staging', 'production'])],
                'image_digest' => 'required|string|regex:/^sha256:[a-f0-9]{64}$/',
                'sbom_reference' => 'required|string|max:500',
                'provenance_reference' => 'required|string|max:500',
                'migration_strategy' => ['required', Rule::in(['none', 'expand_contract'])],
                'rollback_plan' => 'required|string|max:4000',
                'idempotency_key' => 'required|uuid',
            ],
            'alerts' => [
                'service_id' => 'required|integer|min:1',
                'severity' => ['required', Rule::in(['P1', 'P2', 'P3', 'P4'])],
                'title' => 'required|string|max:180',
                'owner' => 'required|string|max:160',
                'runbook_url' => 'required|url|max:500',
                'started_at' => 'required|date',
            ],
        };
        $data = $request->validate([...$common, ...$rules]);
        if (in_array($resource, ['observations', 'alerts'], true)) {
            abort_unless(DB::table('ops_services')->where('company_id', $this->tenant())
                ->where('id', $data['service_id'])->exists(), 422, 'Service absent de cette entreprise.');
        }
        if ($resource === 'logs') $data['message'] = $this->redact($data['message']);

        return DB::transaction(function () use ($resource, $table, $data) {
            $tenant = $this->tenant();
            $key = $data['idempotency_key'] ?? null;
            $hash = null;
            if ($key) {
                $payload = $data;
                unset($payload['idempotency_key']);
                ksort($payload);
                $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
                $existing = DB::table($table)->where('company_id', $tenant)
                    ->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Clé de rejeu associée à un autre contenu.');
                    return response()->json($existing, 200);
                }
                $data['payload_hash'] = $hash;
            }
            if (in_array($resource, ['jobs', 'deployments', 'alerts'], true)) $data['created_by'] = auth()->id();
            if (in_array($resource, ['logs', 'backups'], true)) $data['recorded_by'] = auth()->id();
            $record = [
                ...$data, 'company_id' => $tenant, 'created_at' => now(), 'updated_at' => now(),
            ];
            if ($key) {
                // Unique(company_id, idempotency_key) serializes concurrent replays.
                // An existing row can only be reused with the exact same payload.
                $inserted = DB::table($table)->insertOrIgnore($record);
                $stored = DB::table($table)->where('company_id', $tenant)
                    ->where('idempotency_key', $key)->first();
                abort_unless($stored && hash_equals((string) $stored->payload_hash, $hash), 409,
                    'Clé de rejeu associée à un autre contenu.');
                if ($inserted) $this->event($resource, $stored->id, 'created');
                return response()->json($stored, $inserted ? 201 : 200);
            }
            $id = DB::table($table)->insertGetId($record);
            $this->event($resource, $id, 'created');
            return response()->json(DB::table($table)->where('company_id', $tenant)->where('id', $id)->first(), 201);
        }, 3);
    }

    public function transition(Request $request, string $resource, int $id)
    {
        $this->authorizeWrite();
        abort_unless(isset(self::STATUS[$resource]), 404);
        $input = $request->validate([
            'status' => ['required', Rule::in(array_keys(array_merge(...array_map(
                fn ($next) => array_fill_keys($next, true), array_values(self::STATUS[$resource])
            ))))],
            'version' => 'required|integer|min:1',
            'failure_reason' => 'nullable|string|max:2000',
            'isolated_restore_reference' => 'nullable|string|max:160',
            'observed_rpo_seconds' => 'nullable|integer|min:0',
            'observed_rto_seconds' => 'nullable|integer|min:0',
            'expected_rows' => 'nullable|integer|min:0',
            'restored_rows' => 'nullable|integer|min:0',
            'staging_evidence' => 'nullable|string|max:500',
            'backup_restore_reference' => 'nullable|string|max:160',
            'decision' => ['nullable', Rule::in(['go', 'no_go'])],
        ]);
        return DB::transaction(function () use ($resource, $id, $input) {
            $table = $this->table($resource);
            $tenant = $this->tenant();
            $old = DB::table($table)->where('company_id', $tenant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($old, 404);
            abort_unless((int) $old->version === (int) $input['version'], 409, 'Version obsolète.');
            $status = $input['status'];
            abort_unless(in_array($status, self::STATUS[$resource][$old->status] ?? [], true), 409, 'Transition interdite.');
            $changes = ['status' => $status, 'version' => $old->version + 1, 'updated_at' => now()];
            if ($resource === 'jobs') {
                if ($status === 'running') {
                    abort_if($old->attempts >= $old->max_attempts, 409, 'Nombre maximal de tentatives atteint.');
                    $changes['attempts'] = $old->attempts + 1;
                }
                if ($status === 'failed') {
                    abort_unless(! empty($input['failure_reason']), 422, 'Motif d’échec requis.');
                    $changes['failure_reason'] = $this->redact($input['failure_reason']);
                }
            }
            if ($resource === 'backups' && $status === 'restore_verified') {
                foreach (['isolated_restore_reference', 'observed_rpo_seconds', 'observed_rto_seconds', 'expected_rows', 'restored_rows'] as $field) {
                    abort_unless(isset($input[$field]), 422, 'Preuve de restauration incomplète : '.$field);
                    $changes[$field] = $input[$field];
                }
                abort_unless($old->encrypted && $input['expected_rows'] === $input['restored_rows'], 409, 'Chiffrement ou rapprochement non conforme.');
                $changes['last_restored_at'] = now();
            }
            if ($resource === 'deployments') {
                if ($status === 'staging_verified') {
                    abort_unless(! empty($input['staging_evidence']), 422, 'Preuve de staging obligatoire.');
                    $changes['staging_evidence'] = $input['staging_evidence'];
                }
                if ($status === 'approved') {
                    abort_unless(($input['decision'] ?? '') === 'go', 422, 'Décision Go explicite obligatoire.');
                    abort_unless(! empty($input['backup_restore_reference']), 422, 'Référence de restauration obligatoire.');
                    $verified = DB::table('ops_backups')->where('company_id', $tenant)
                        ->where('artifact_reference', $input['backup_restore_reference'])
                        ->where('status', 'restore_verified')->exists();
                    abort_unless($verified, 409, 'Restauration non vérifiée.');
                    $changes['decision'] = 'go';
                    $changes['backup_restore_reference'] = $input['backup_restore_reference'];
                }
                if ($status === 'promoted') {
                    abort_unless($old->decision === 'go' && $old->staging_evidence && $old->backup_restore_reference, 409, 'Dossier de release incomplet.');
                }
                if ($status === 'rejected') $changes['decision'] = 'no_go';
            }
            if ($resource === 'alerts' && $status === 'resolved') $changes['resolved_at'] = now();
            DB::table($table)->where('company_id', $tenant)->where('id', $id)
                ->where('version', $old->version)->update($changes);
            $this->event($resource, $id, 'transition', $old->status, $status);
            return response()->json(DB::table($table)->where('company_id', $tenant)->where('id', $id)->first());
        }, 3);
    }
}
