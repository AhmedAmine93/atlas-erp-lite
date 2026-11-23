<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * M18: operational evidence register, not a remote-shell / deployment executor.
 * All measurements and verification records remain labelled as declarations.
 * No endpoint can run a command, import a log file, execute a job, restore a DB,
 * deploy images or inspect private infrastructure.
 */
final class OpsController extends Controller
{
    private const TABLES = [
        'health' => 'ops_service_checks',
        'logs' => 'ops_log_entries',
        'jobs' => 'ops_jobs',
        'backups' => 'ops_backups',
        'deployments' => 'ops_deployments',
    ];

    private function tenant(): int
    {
        Gate::authorize('admin');
        return app(TenantContext::class)->requireId();
    }

    private function table(string $section): string
    {
        abort_unless(isset(self::TABLES[$section]), 404);
        return self::TABLES[$section];
    }

    public function overview()
    {
        $companyId = $this->tenant();
        $latestIds = DB::table('ops_service_checks')->where('company_id', $companyId)
            ->selectRaw('MAX(id) as id')->groupBy('service_key')->pluck('id');
        $checks = DB::table('ops_service_checks')->where('company_id', $companyId)
            ->whereIn('id', $latestIds)->orderBy('service_key')->limit(100)->get();

        return [
            'scope' => 'Entreprise active uniquement',
            'data_source' => 'Déclarations enregistrées ; aucun contrôle live implicite',
            'generated_at' => now()->toIso8601String(),
            'services' => $checks,
            'counts' => [
                'observed_services' => $checks->count(),
                'degraded_services' => $checks->filter(fn ($s) =>
                    $s->technical_status !== 'ok' || $s->business_status !== 'operational')->count(),
                'failed_jobs' => DB::table('ops_jobs')->where('company_id', $companyId)
                    ->whereIn('status', ['failed', 'dead_letter'])->count(),
                'restore_attestations' => DB::table('ops_backups')->where('company_id', $companyId)
                    ->where('isolated_restore_verified', true)->count(),
                'approved_release_records' => DB::table('ops_deployments')->where('company_id', $companyId)
                    ->where('status', 'approved')->count(),
            ],
        ];
    }

    public function index(Request $request, string $section)
    {
        $companyId = $this->tenant();
        $table = $this->table($section);
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1',
            'search' => 'nullable|string|max:80',
            'status' => 'nullable|string|max:30',
        ]);
        $query = DB::table($table)->where('company_id', $companyId);
        $allowedStatus = match ($section) {
            'jobs' => ['pending', 'running', 'completed', 'failed', 'dead_letter'],
            'deployments' => ['draft', 'approved', 'blocked'],
            'health' => ['ok', 'degraded', 'down'],
            'logs' => ['info', 'warning', 'error', 'critical'],
            default => [],
        };
        if (isset($filters['status'])) {
            abort_unless(in_array($filters['status'], $allowedStatus, true), 422, 'Filtre de statut inconnu.');
            $field = match ($section) {
                'health' => 'technical_status', 'logs' => 'level', default => 'status',
            };
            $query->where($field, $filters['status']);
        }
        if (!empty($filters['search'])) {
            $field = match ($section) {
                'health' => 'service_key', 'logs' => 'event',
                'jobs' => 'job_type', 'backups' => 'backup_ref',
                'deployments' => 'image_digest',
            };
            $query->where($field, 'like', '%'.$filters['search'].'%');
        }

        return $query->orderByDesc('id')->paginate(20);
    }

    private function secretFree(string $message): void
    {
        // Refuse potentially sensitive log payloads instead of blindly saving
        // them; instrumentation must produce allowlisted event summaries.
        if (preg_match('/\b(password|passwd|secret|token|bearer|authorization|set-cookie|api[_-]?key|credential)\b/i', $message)
            || preg_match('/[\r\n\x00-\x1f]/', $message)) {
            throw ValidationException::withMessages([
                'message' => 'Les secrets et contenus multilignes ne peuvent pas être enregistrés.',
            ]);
        }
    }

    public function store(Request $request, string $section)
    {
        $companyId = $this->tenant();
        $table = $this->table($section);
        abort_unless(in_array($section, ['health', 'logs', 'jobs', 'backups', 'deployments'], true), 404);

        $data = match ($section) {
            'health' => $request->validate([
                'service_key' => 'required|alpha_dash|max:40',
                'technical_status' => ['required', Rule::in(['ok', 'degraded', 'down'])],
                'business_status' => ['required', Rule::in(['operational', 'degraded', 'blocked'])],
                'source' => ['required', Rule::in(['manual', 'synthetic', 'otel', 'prometheus'])],
                'latency_ms' => 'nullable|integer|min:0|max:600000',
                'observed_at' => 'required|date|before_or_equal:now',
            ]),
            'logs' => $request->validate([
                'component' => 'required|alpha_dash|max:48',
                'level' => ['required', Rule::in(['info', 'warning', 'error', 'critical'])],
                'event' => 'required|alpha_dash|max:80',
                'message' => 'required|string|max:180',
                'correlation_id' => 'required|uuid',
                'observed_at' => 'required|date|before_or_equal:now',
            ]),
            'jobs' => $request->validate([
                'job_type' => ['required', Rule::in(['reconciliation', 'report', 'export', 'quality_check'])],
                'idempotency_key' => 'required|string|min:8|max:100|regex:/^[A-Za-z0-9._:-]+$/',
                'window_start' => 'nullable|date',
                'window_end' => 'nullable|date|after_or_equal:window_start',
                'max_attempts' => 'required|integer|min:1|max:5',
            ]),
            'backups' => $request->validate([
                'backup_ref' => 'required|string|min:8|max:90|regex:/^[A-Za-z0-9._:-]+$/',
                'environment' => ['required', Rule::in(['dev', 'staging', 'production'])],
                'checksum_sha256' => 'required|regex:/^[a-f0-9]{64}$/i',
                'encrypted' => 'required|accepted',
                'captured_at' => 'required|date|before_or_equal:now',
            ]),
            'deployments' => $request->validate([
                'environment' => ['required', Rule::in(['dev', 'staging', 'production'])],
                'image_digest' => 'required|regex:/^sha256:[a-f0-9]{64}$/',
            ]),
        };

        if ($section === 'logs') $this->secretFree($data['message']);
        if ($section === 'jobs') {
            $digest = hash('sha256', json_encode([
                $data['job_type'], $data['window_start'] ?? null,
                $data['window_end'] ?? null, (int) $data['max_attempts'],
            ], JSON_THROW_ON_ERROR));
            return DB::transaction(function () use ($table, $companyId, $request, $data, $digest) {
                // PostgreSQL ON CONFLICT DO NOTHING serializes simultaneous submissions
                // on the tenant/key UNIQUE constraint. Only the winner emits an audit.
                $inserted = DB::table($table)->insertOrIgnore([
                    ...$data, 'company_id' => $companyId, 'created_by' => $request->user()->id,
                    'correlation_id' => (string) Str::uuid(), 'payload_digest' => $digest,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $record = DB::table($table)->where('company_id', $companyId)
                    ->where('idempotency_key', $data['idempotency_key'])->first();
                abort_unless($record, 500, 'Échec de lecture après enregistrement du job.');
                abort_unless(hash_equals($record->payload_digest, $digest), 409,
                    'Clé de rejeu utilisée avec un contenu différent.');
                if ($inserted) {
                    Audit::record('ops.job_registered', null, ['id' => $record->id]);
                }
                return response()->json([
                    'record' => $record, 'already_exists' => ! (bool) $inserted,
                ], $inserted ? 201 : 200);
            }, 3);
        }
        if ($section === 'backups') {
            $data['encrypted'] = true;
        }
        if ($section === 'health') {
            $data['actor_id'] = $request->user()->id;
        } elseif ($section === 'logs') {
            $data['actor_id'] = $request->user()->id;
        } else {
            $data['created_by'] = $request->user()->id;
        }
        if ($section === 'deployments') {
            $data['status'] = 'draft';
        }

        return DB::transaction(function () use ($table, $companyId, $data, $section) {
            $id = DB::table($table)->insertGetId([
                ...$data, 'company_id' => $companyId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record('ops.'.$section.'_registered', null, ['id' => $id]);
            return response()->json(
                DB::table($table)->where('company_id', $companyId)->find($id), 201
            );
        }, 3);
    }

    public function transition(Request $request, string $section, int $id)
    {
        $companyId = $this->tenant();
        abort_unless(in_array($section, ['jobs', 'backups', 'deployments'], true), 404);
        $data = match ($section) {
            'jobs' => $request->validate([
                'action' => ['required', Rule::in(['start', 'complete', 'fail', 'retry', 'dead_letter'])],
                'version' => 'required|integer|min:1',
                'result' => 'nullable|string|max:120',
            ]),
            'backups' => $request->validate([
                'action' => ['required', Rule::in(['attest_restore'])],
                'restored_at' => 'required|date|before_or_equal:now',
                'observed_rpo_seconds' => 'required|integer|min:0|max:31536000',
                'observed_rto_seconds' => 'required|integer|min:0|max:31536000',
                'proof_ref' => 'required|string|min:8|max:120|regex:/^[A-Za-z0-9._:-]+$/',
            ]),
            'deployments' => $request->validate([
                'action' => ['required', Rule::in(['approve', 'block'])],
                'sbom_verified' => 'required_if:action,approve|boolean',
                'provenance_verified' => 'required_if:action,approve|boolean',
                'staging_smoke_passed' => 'required_if:action,approve|boolean',
                'migration_compatible' => 'required_if:action,approve|boolean',
                'backup_id' => 'required_if:action,approve|nullable|integer|min:1',
                'decision_ref' => 'required|string|min:8|max:120|regex:/^[A-Za-z0-9._:-]+$/',
            ]),
        };
        $table = $this->table($section);

        return DB::transaction(function () use ($companyId, $table, $section, $id, $data) {
            $row = DB::table($table)->where('company_id', $companyId)->where('id', $id)
                ->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($section === 'jobs') {
                abort_unless((int) $data['version'] === (int) $row->version, 409, 'Version obsolète, rechargez le job.');
                $action = $data['action'];
                $allowed = match ($row->status) {
                    'pending' => ['start', 'dead_letter'],
                    'running' => ['complete', 'fail'],
                    'failed' => ['retry', 'dead_letter'],
                    default => [],
                };
                abort_unless(in_array($action, $allowed, true), 409, 'Transition de job non autorisée.');
                abort_if($action === 'retry' && $row->attempts >= $row->max_attempts, 409, 'Limite de reprises atteinte.');
                $status = match ($action) {
                    'start' => 'running', 'complete' => 'completed',
                    'fail' => 'failed', 'retry' => 'pending', 'dead_letter' => 'dead_letter',
                };
                $patch = [
                    'status' => $status, 'version' => $row->version + 1,
                    'updated_at' => now(),
                ];
                if ($action === 'start') {
                    abort_if($row->attempts >= $row->max_attempts, 409, 'Limite d’essais atteinte.');
                    $patch['attempts'] = $row->attempts + 1;
                    $patch['last_attempt_at'] = now();
                }
                if (in_array($action, ['complete', 'fail'], true)) {
                    $patch['result'] = trim($data['result'] ?? '') ?: null;
                }
                DB::table($table)->where('company_id', $companyId)->where('id', $id)->update($patch);
            } elseif ($section === 'backups') {
                abort_if($row->isolated_restore_verified || !$row->encrypted, 409, 'Attestation déjà enregistrée ou sauvegarde non chiffrée.');
                abort_if(strtotime($data['restored_at']) < strtotime($row->captured_at), 422, 'Restauration antérieure à la sauvegarde.');
                DB::table($table)->where('company_id', $companyId)->where('id', $id)->update([
                    'restored_at' => $data['restored_at'],
                    'isolated_restore_verified' => true,
                    'observed_rpo_seconds' => $data['observed_rpo_seconds'],
                    'observed_rto_seconds' => $data['observed_rto_seconds'],
                    'proof_ref' => $data['proof_ref'],
                    'updated_at' => now(),
                ]);
            } else {
                abort_unless($row->status === 'draft', 409, 'Cette décision de release est définitive.');
                if ($data['action'] === 'approve') {
                    abort_unless($data['sbom_verified'] && $data['provenance_verified'] &&
                        $data['staging_smoke_passed'] && $data['migration_compatible'], 422,
                        'Preuves SBOM, provenance, staging et compatibilité obligatoires.');
                    $backup = DB::table('ops_backups')->where('company_id', $companyId)
                        ->where('id', $data['backup_id'])->where('isolated_restore_verified', true)->first();
                    abort_unless($backup, 422, 'Restauration isolée prouvée obligatoire dans la même entreprise.');
                    DB::table($table)->where('company_id', $companyId)->where('id', $id)->update([
                        'status' => 'approved',
                        'sbom_verified' => true,
                        'provenance_verified' => true,
                        'staging_smoke_passed' => true,
                        'migration_compatible' => true,
                        'backup_id' => $backup->id,
                        'decision_ref' => $data['decision_ref'],
                        'approved_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table($table)->where('company_id', $companyId)->where('id', $id)->update([
                        'status' => 'blocked', 'decision_ref' => $data['decision_ref'],
                        'updated_at' => now(),
                    ]);
                }
            }
            Audit::record('ops.'.$section.'_transition', null, [
                'id' => $id, 'action' => $data['action'],
            ]);
            return DB::table($table)->where('company_id', $companyId)->find($id);
        }, 3);
    }
}
