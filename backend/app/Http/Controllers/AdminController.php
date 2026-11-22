<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AdminController extends Controller
{
    public function users()
    {
        Gate::authorize('admin');
        $companyId = app(TenantContext::class)->requireId();
        $page = User::whereHas('companies', fn ($query) => $query->whereKey($companyId)->whereNull('company_user.suspended_at'))
            ->orderBy('name')->paginate(20);

        // The legacy global User.role relation is for backward compatibility only.
        // Always show the role of the membership in the active company.
        $roleIds = DB::table('company_user')->where('company_id', $companyId)
            ->whereIn('user_id', $page->getCollection()->pluck('id'))
            ->pluck('role_id', 'user_id');
        $roles = Role::whereIn('id', $roleIds->values()->unique()->all())->get()->keyBy('id');

        $page->getCollection()->each(function (User $user) use ($roles, $roleIds): void {
            $effectiveRoleId = (int) ($roleIds[$user->id] ?? 0);
            $user->setRelation('role', $roles->get($effectiveRoleId));
            // The UI must edit the membership's role, not the legacy global role.
            // This modifies only the serialized model, never the users table.
            $user->setAttribute('role_id', $effectiveRoleId);
        });

        return $page;
    }

    public function roles()
    {
        Gate::authorize('admin');

        return Role::all();
    }

    private function auditQuery(Request $request)
    {
        $filter = $request->validate([
            'action' => 'nullable|string|max:120',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
        return AuditLog::query()
            ->when($filter['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filter['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filter['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id');
    }

    public function audit(Request $request)
    {
        Gate::authorize('admin');
        // Do not expose metadata containing potentially sensitive payloads in
        // this generalized audit view; permissioned investigations require
        // a separately reviewed field allowlist.
        return $this->auditQuery($request)
            ->select('id','user_id','action','entity_type','entity_id','created_at')
            ->with('user:id,name')
            ->paginate(30);
    }

    public function auditExport(Request $request)
    {
        Gate::authorize('admin');
        // Bound exports avoid loading unbounded personal data in memory.
        $query = $this->auditQuery($request)
            ->select('id','user_id','action','entity_type','entity_id','created_at');
        abort_if((clone $query)->limit(10001)->count() > 10000, 422,
            'Export limité à 10 000 lignes : réduisez la période.');
        Audit::record('audit.exported', null, ['format'=>'csv','max_rows'=>10000]);
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output','w');
            fputcsv($out,['id','actor_id','action','entity_type','entity_id','created_at'], ';');
            foreach ($query->cursor() as $row) {
                // Prevent spreadsheet formula interpretation in exported text.
                $safe = static function ($value): string {
                    $value = (string) ($value ?? '');
                    return preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
                };
                fputcsv($out, [
                    $row->id, $row->user_id, $safe($row->action),
                    $safe($row->entity_type), $row->entity_id, $safe($row->created_at)
                ], ';');
            }
            fclose($out);
        }, 'atlas-audit.csv', ['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    public function save(Request $r, ?int $id = null)
    {
        Gate::authorize('admin');
        // Tenant administrators may create a user identity, but editing an
        // existing user's GLOBAL identity belongs to a separate verified
        // workflow. Avoid global email-existence probing via update validation.
        $data = $id
            ? $r->validate([
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|max:255',
                'role_id' => 'required|integer|exists:roles,id',
                'password' => 'prohibited',
            ])
            : $r->validate([
                'name' => 'required|string|max:255',
                'email' => ['required', 'email', 'max:255', Rule::unique('users')],
                'role_id' => 'required|integer|exists:roles,id',
                'password' => ['required', 'string', Password::min(12)->letters()->numbers()],
            ]);

        return DB::transaction(function () use ($id, $data) {
            $companyId = app(TenantContext::class)->requireId();
            // Serializes admin-role changes within the same company.
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $adminRoleId = Role::where('name', 'admin')->value('id');
            $roleId = (int) $data['role_id'];
            $user = $id
                ? User::whereHas('companies', fn ($query) => $query->whereKey($companyId))
                    ->lockForUpdate()->findOrFail($id)
                : new User;

            if ($id) {
                $previousRoleId = DB::table('company_user')
                    ->where('company_id', $companyId)
                    ->where('user_id', $id)
                    ->value('role_id');

                if ((int) $previousRoleId === (int) $adminRoleId && $roleId !== (int) $adminRoleId) {
                    abort_if(
                        DB::table('company_user')
                            ->where('company_id', $companyId)
                            ->where('role_id', $adminRoleId)->whereNull('suspended_at')->count() <= 1,
                        409,
                        'Le dernier administrateur de cette entreprise doit être conservé.',
                    );
                }

                // Global identity (name, email, password) is shared across
                // companies. A tenant admin may ONLY modify the membership
                // role, even when the user currently has one affiliation.
                // Identity edits require a separate verified self-service flow.
                if ((isset($data['name']) && $data['name'] !== $user->name)
                    || (isset($data['email']) && $data['email'] !== $user->email)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'identity' => 'Les informations du compte sont globales : seule l’affiliation à cette entreprise peut être modifiée.',
                    ]);
                }

                $user->companies()->updateExistingPivot($companyId, ['role_id' => $roleId]);
                DB::table('sessions')->where('user_id', $id)->delete();
            } else {
                $user->fill($data)->save();
                $user->companies()->attach($companyId, ['role_id' => $roleId]);
            }

            Audit::record($id ? 'user.updated' : 'user.created', $user, [
                'company_id' => $companyId, 'role_id' => $roleId,
            ]);

            $effectiveRole = Role::findOrFail($roleId);
            // Do not leak the deprecated global users.role_id into the UI.
            return response()->json(array_merge($user->load('role')->toArray(), [
                'role_id' => $effectiveRole->id,
                'role' => $effectiveRole->toArray(),
            ]), $id ? 200 : 201);
        }, 3);
    }

    public function destroy(int $id)
    {
        Gate::authorize('admin');
        abort_if($id === auth()->id(), 409, 'Vous ne pouvez pas supprimer votre propre compte.');

        return DB::transaction(function () use ($id) {
            $companyId = app(TenantContext::class)->requireId();
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $adminRoleId = Role::where('name', 'admin')->value('id');

            $user = User::whereHas('companies', fn ($query) => $query->whereKey($companyId))
                ->lockForUpdate()->findOrFail($id);
            $currentRoleId = DB::table('company_user')
                ->where('company_id', $companyId)->where('user_id', $id)->value('role_id');

            if ((int) $currentRoleId === (int) $adminRoleId) {
                abort_if(
                    DB::table('company_user')
                        ->where('company_id', $companyId)
                        ->where('role_id', $adminRoleId)->count() <= 1,
                    409,
                    'Le dernier administrateur de cette entreprise doit être conservé.',
                );
            }

            DB::table('sessions')->where('user_id', $id)->delete();
            $user->companies()->detach($companyId);
            // Preserve the global identity and immutable historical references
            // (invoices, incidents, audit records) even if this was the final
            // membership. Login already rejects users without affiliations.
            // Account lifecycle/deletion requires a separate retention policy.
            Audit::record('user.removed_from_company', $user, ['company_id' => $companyId]);

            return response()->noContent();
        }, 3);
    }
}
