<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\SupportIncident;
use App\Models\SupportServiceCatalog;
use App\Services\Audit;
use App\Services\TenantContext;
use App\Services\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * M11.01/03/04/05: stateful tenant-scoped workflow. Every mutation is atomic,
 * version-checked and idempotent. An approval never survives a changed revision.
 */
class SupportCaseController extends Controller
{
    private const KINDS = ['ticket', 'problem', 'service', 'change'];
    private const TRANSITIONS = [
        'ticket' => [
            'open' => ['investigating', 'cancelled'],
            'investigating' => ['resolved', 'cancelled'],
            'resolved' => ['closed', 'investigating'],
            'closed' => [], 'cancelled' => [],
        ],
        'problem' => [
            'open' => ['investigating', 'cancelled'],
            'investigating' => ['known_error', 'resolved', 'cancelled'],
            'known_error' => ['resolved', 'investigating'],
            'resolved' => ['closed', 'investigating'],
            'closed' => [], 'cancelled' => [],
        ],
        'service' => [
            'open' => ['awaiting_approval', 'approved', 'cancelled'],
            'awaiting_approval' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['fulfilling', 'cancelled'],
            'fulfilling' => ['completed', 'cancelled'],
            'completed' => ['closed'], 'closed' => [], 'rejected' => [], 'cancelled' => [],
        ],
        'change' => [
            'open' => ['awaiting_approval', 'cancelled'],
            'awaiting_approval' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['implementing', 'cancelled'],
            'implementing' => ['completed', 'rolled_back'],
            'completed' => ['closed'], 'closed' => [], 'rejected' => [],
            'rolled_back' => ['closed'], 'cancelled' => [],
        ],
    ];

    private function role(Request $request): ?string
    {
        return app(TenantRoles::class)->role($request->user())?->name;
    }

    private function memberRule()
    {
        return Rule::exists('company_user', 'user_id')
            ->where(fn ($q) => $q->where('company_id', app(TenantContext::class)->requireId()));
    }

    private function record(SupportCase $item, string $action, Request $request, ?string $from = null, array $details = []): void
    {
        SupportCaseEvent::create([
            'case_id' => $item->id,
            'actor_id' => $request->user()->id,
            'action' => $action, 'from_status' => $from,
            'to_status' => $item->status, 'version' => $item->version,
            'details' => $details,
        ]);
        Audit::record('support.case.'.$action, $item, [
            'kind' => $item->kind, 'version' => $item->version,
        ]);
    }

    /**
     * Replays are scoped per company+operation; different payload for same key
     * is a hard conflict. Transaction lock serializes concurrent identical keys.
     */
    private function once(Request $request, string $operation, array $payload, callable $execute)
    {
        $key = $request->header('Idempotency-Key');
        if (! $key || ! preg_match('/^[A-Za-z0-9._:-]{8,120}$/D', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'En-tête Idempotency-Key requis (8-120 caractères).']);
        }
        $company = app(TenantContext::class)->requireId();
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return DB::transaction(function () use ($company, $operation, $key, $digest, $execute) {
            DB::table('support_request_replays')->insertOrIgnore([
                'company_id' => $company, 'operation' => $operation,
                'idempotency_key' => $key, 'payload_hash' => $digest,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $replay = DB::table('support_request_replays')
                ->where('company_id', $company)->where('operation', $operation)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            abort_unless(hash_equals($replay->payload_hash, $digest), 409, 'Clé de rejeu réutilisée avec un contenu différent.');
            if ($replay->response !== null) {
                return response()->json(json_decode($replay->response, true, 512, JSON_THROW_ON_ERROR));
            }
            $result = $execute();
            DB::table('support_request_replays')->where('id', $replay->id)->update([
                'response' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            return response()->json($result, 201);
        }, 3);
    }

    public function index(Request $request, string $kind)
    {
        Gate::authorize('itsmRead');
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $query = $request->validate([
            'status' => 'nullable|string|max:24',
            'priority' => ['nullable', Rule::in(['P1', 'P2', 'P3', 'P4'])],
            'search' => 'nullable|string|max:100',
            'assigned_to' => ['nullable', 'integer', 'min:1'],
            'page' => 'nullable|integer|min:1',
        ]);
        return SupportCase::query()->where('kind', $kind)
            ->with(['requester:id,name', 'assignee:id,name'])
            ->when($query['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($query['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($query['assigned_to'] ?? null, fn ($q, $a) => $q->where('assigned_to', $a))
            ->when($query['search'] ?? null, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $pattern = '%'.mb_strtolower($search).'%';
                $q->whereRaw('LOWER(title) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$pattern]);
            }))->latest('id')->paginate(20);
    }

    public function show(string $kind, int $id)
    {
        Gate::authorize('itsmRead');
        abort_unless(in_array($kind, self::KINDS, true), 404);
        return SupportCase::where('kind', $kind)->with([
            'events.actor:id,name', 'incidents:id,reference,title,status',
            'requester:id,name', 'assignee:id,name', 'catalog:id,code,title',
        ])->findOrFail($id);
    }

    public function store(Request $request, string $kind)
    {
        Gate::authorize('itsmManage');
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $data = $request->validate([
            'title' => 'required|string|max:180',
            'description' => 'required|string|min:3|max:10000',
            'priority' => ['required', Rule::in(['P1', 'P2', 'P3', 'P4'])],
            'assigned_to' => ['nullable', 'integer', $this->memberRule()],
            'service_name' => 'nullable|string|max:180',
            'catalog_id' => ['nullable', 'integer', 'min:1'],
            'risk_level' => ['nullable', Rule::in(['low','medium','high','critical'])],
            'window_start' => 'nullable|date',
            'window_end' => 'nullable|date|after:window_start',
            'rollback_plan' => 'nullable|string|max:10000',
        ]);
        if ($kind === 'service') {
            if (! ($data['catalog_id'] ?? null)) {
                throw ValidationException::withMessages(['catalog_id' => 'Sélectionnez une prestation du catalogue.']);
            }
            $catalog = SupportServiceCatalog::findOrFail($data['catalog_id']);
            abort_unless($catalog->active, 409, 'Prestation désactivée.');
            $role = $this->role($request);
            abort_unless(! $catalog->eligible_role || $role === $catalog->eligible_role || $role === 'admin', 403);
            $data['service_name'] = $catalog->title;
        } else {
            abort_if(isset($data['catalog_id']), 422, 'Catalogue réservé aux demandes de service.');
        }
        if ($kind === 'change') {
            foreach (['risk_level','window_start','window_end','rollback_plan'] as $field) {
                if (blank($data[$field] ?? null)) {
                    throw ValidationException::withMessages([$field] => 'Champ obligatoire pour un changement.']);
                }
            }
        }
        return $this->once($request, 'case.create.'.$kind, $data, function () use ($request, $kind, $data) {
            $item = SupportCase::create([
                ...$data, 'reference' => 'ITSM-'.strtoupper(bin2hex(random_bytes(6))),
                'kind' => $kind, 'status' => 'open', 'requester_id' => $request->user()->id,
                'version' => 1,
            ]);
            $this->record($item, 'created', $request);
            return $item->fresh()->toArray();
        });
    }

    public function update(Request $request, string $kind, int $id)
    {
        Gate::authorize('itsmManage');
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'title' => 'sometimes|required|string|max:180',
            'description' => 'sometimes|required|string|min:3|max:10000',
            'priority' => ['sometimes', Rule::in(['P1','P2','P3','P4'])],
            'assigned_to' => ['sometimes','nullable','integer',$this->memberRule()],
            'root_cause' => 'sometimes|nullable|string|max:10000',
            'workaround' => 'sometimes|nullable|string|max:10000',
            'permanent_fix' => 'sometimes|nullable|string|max:10000',
            'result_evidence' => 'sometimes|nullable|string|max:10000',
            'verification' => 'sometimes|nullable|string|max:10000',
            'pipeline_url' => 'sometimes|nullable|url|max:500',
            'risk_level' => ['sometimes', Rule::in(['low','medium','high','critical'])],
            'window_start' => 'sometimes|date',
            'window_end' => 'sometimes|date',
            'rollback_plan' => 'sometimes|nullable|string|max:10000',
        ]);
        return $this->once($request, 'case.update.'.$kind.'.'.$id, $data, function () use ($request, $kind, $id, $data) {
            $item = SupportCase::where('kind', $kind)->lockForUpdate()->findOrFail($id);
            abort_unless($item->version === (int) $data['version'], 409, 'Version obsolète.');
            abort_if(in_array($item->status, ['closed','cancelled','rejected','completed','rolled_back'], true), 409, 'Dossier non modifiable.');
            unset($data['version']);
            $item->fill($data);
            if ($kind === 'change') {
                $item->approved_by = null;
                $item->approved_version = null;
                if ($item->status === 'approved') $item->status = 'awaiting_approval';
            }
            $item->version++;
            $item->save();
            $this->record($item, 'updated', $request, null, ['fields' => array_keys($data)]);
            return $item->toArray();
        });
    }

    public function transition(Request $request, string $kind, int $id)
    {
        Gate::authorize('itsmManage');
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $data = $request->validate([
            'version' => 'required|integer|min:1',
            'status' => 'required|string|max:24',
            'reason' => 'nullable|string|max:3000',
        ]);
        return $this->once($request, 'case.transition.'.$kind.'.'.$id, $data, function () use ($request, $kind, $id, $data) {
            $item = SupportCase::where('kind', $kind)->lockForUpdate()->findOrFail($id);
            abort_unless($item->version === (int) $data['version'], 409, 'Version obsolète.');
            abort_unless(in_array($data['status'], self::TRANSITIONS[$kind][$item->status] ?? [], true), 409, 'Transition interdite.');
            $role = $this->role($request);
            $next = $data['status'];
            if ($kind === 'service') {
                $catalog = SupportServiceCatalog::findOrFail($item->catalog_id);
                $needsApproval = $catalog->requires_approval || $catalog->sensitive;
                abort_if($next === 'approved' && $item->status === 'open' && $needsApproval, 409, 'Approbation indépendante requise.');
                if ($next === 'approved') {
                    abort_unless(in_array($role, ['admin','support_manager'], true), 403);
                    abort_if($catalog->sensitive && (int) $item->requester_id === (int) $request->user()->id, 409, 'Auto-approbation sensible interdite.');
                    $item->approved_by = $request->user()->id;
                    $item->approved_version = $item->version;
                }
                if ($next === 'completed') abort_if(blank($item->result_evidence), 409, 'Preuve de prestation obligatoire.');
            }
            if ($kind === 'change') {
                if (in_array($next, ['approved','rejected'], true)) {
                    abort_unless(in_array($role, ['admin','support_cab'], true), 403, 'Décision CAB nécessaire.');
                    abort_if((int) $item->requester_id === (int) $request->user()->id, 409, 'Le demandeur ne peut pas approuver son changement.');
                    $item->approved_by = $request->user()->id;
                    $item->approved_version = $item->version;
                }
                if ($next === 'implementing') {
                    abort_if(! $item->approved_by || $item->approved_version !== $item->version - 1, 409, 'La version approuvée est obsolète.');
                    abort_if(blank($item->rollback_plan) || ! $item->window_start || ! $item->window_end, 409, 'Plan de retour et fenêtre requis.');
                }
                if ($next === 'completed') {
                    abort_if(blank($item->pipeline_url) || blank($item->verification), 409, 'Pipeline et vérification de production requis.');
                }
            }
            if ($kind === 'problem' && in_array($next, ['resolved','closed'], true)) {
                abort_if(blank($item->root_cause) || blank($item->permanent_fix), 409, 'Cause racine et correction permanente obligatoires.');
            }
            if ($kind === 'ticket' && $next === 'closed') {
                abort_if(blank($item->result_evidence), 409, 'Preuve de validation obligatoire.');
            }
            if (in_array($next, ['cancelled','rejected','rolled_back'], true)) {
                abort_if(blank($data['reason'] ?? null), 409, 'Motif obligatoire.');
            }
            $before = $item->status;
            $item->status = $next;
            $item->version++;
            if ($next === 'closed') $item->closed_at = now();
            $item->save();
            $this->record($item, 'transitioned', $request, $before, ['reason' => $data['reason'] ?? null]);
            return $item->toArray();
        });
    }

    public function linkIncident(Request $request, string $kind, int $id)
    {
        Gate::authorize('itsmManage');
        abort_unless(in_array($kind, ['ticket', 'problem', 'change', 'service'], true), 404);
        $data = $request->validate(['incident_id' => 'required|integer|min:1']);
        return $this->once($request, 'case.link.'.$kind.'.'.$id, $data, function () use ($request, $kind, $id, $data) {
            $item = SupportCase::where('kind', $kind)->lockForUpdate()->findOrFail($id);
            $incident = SupportIncident::findOrFail($data['incident_id']);
            DB::table('support_case_incidents')->insertOrIgnore([
                'company_id' => app(TenantContext::class)->requireId(),
                'case_id' => $item->id, 'incident_id' => $incident->id,
                'created_by' => $request->user()->id, 'created_at' => now(),
            ]);
            $item->version++;
            $item->save();
            $this->record($item, 'incident_linked', $request, null, ['incident_id' => $incident->id]);
            return $item->toArray();
        });
    }

    public function catalog(Request $request)
    {
        Gate::authorize('itsmRead');
        return SupportServiceCatalog::query()->orderBy('code')->paginate(30);
    }

    public function createCatalog(Request $request)
    {
        Gate::authorize('itsmAdmin');
        $data = $request->validate([
            'code' => ['required','string','max:60','regex:/^[A-Z0-9_-]+$/'],
            'title' => 'required|string|max:180',
            'description' => 'nullable|string|max:4000',
            'requires_approval' => 'required|boolean',
            'sensitive' => 'required|boolean',
            'eligible_role' => ['nullable',Rule::in(['admin','support_n1','support_n2','support_n3','support_manager','support_cab'])],
        ]);
        $companyId = app(TenantContext::class)->requireId();
        $data['code'] = strtoupper($data['code']);
        $request->validate(['code' => Rule::unique('support_service_catalog', 'code')->where('company_id', $companyId)]);
        return $this->once($request, 'catalog.create', $data, function () use ($data) {
            $item = SupportServiceCatalog::create($data);
            Audit::record('support.catalog.created', $item);
            return $item->toArray();
        });
    }
}
