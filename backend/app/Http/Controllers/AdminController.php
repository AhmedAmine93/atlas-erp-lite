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
        $page = User::whereHas('companies', fn ($query) => $query->whereKey($companyId))
            ->orderBy('name')->paginate(20);

        // The legacy global User.role relation is for backward compatibility only.
        // Always show the role of the membership in the active company.
        $roleIds = DB::table('company_user')->where('company_id', $companyId)
            ->whereIn('user_id', $page->getCollection()->pluck('id'))
            ->pluck('role_id', 'user_id');
        $roles = Role::whereIn('id', $roleIds->values()->unique()->all())->get()->keyBy('id');

        $page->getCollection()->each(function (User $user) use ($roles, $roleIds): void {
            $user->setRelation('role', $roles->get((int) ($roleIds[$user->id] ?? 0)));
        });

        return $page;
    }

    public function roles()
    {
        Gate::authorize('admin');

        return Role::all();
    }

    public function audit()
    {
        Gate::authorize('admin');

        return AuditLog::with('user')->latest('id')->paginate(30);
    }

    public function save(Request $r, ?int $id = null)
    {
        Gate::authorize('admin');
        $data = $r->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)],
            'role_id' => 'required|integer|exists:roles,id',
            'password' => [$id ? 'nullable' : 'required', 'string', Password::min(12)->letters()->numbers()],
        ]);
        if (empty($data['password'])) {
            unset($data['password']);
        }

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
                            ->where('role_id', $adminRoleId)->count() <= 1,
                        409,
                        'Le dernier administrateur de cette entreprise doit être conservé.',
                    );
                }

                // Keep the historical users.role_id intact. Only the active
                // company's membership is edited.
                $userData = $data;
                unset($userData['role_id']);
                $user->fill($userData)->save();
                $user->companies()->updateExistingPivot($companyId, ['role_id' => $roleId]);
                DB::table('sessions')->where('user_id', $id)->delete();
            } else {
                $user->fill($data)->save();
                $user->companies()->attach($companyId, ['role_id' => $roleId]);
            }

            Audit::record($id ? 'user.updated' : 'user.created', $user, [
                'company_id' => $companyId, 'role_id' => $roleId,
            ]);

            return response()->json(array_merge($user->load('role')->toArray(), [
                'role' => Role::findOrFail($roleId)->toArray(),
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
            if (! $user->companies()->exists()) {
                $user->delete();
            }
            Audit::record('user.removed_from_company', $user, ['company_id' => $companyId]);

            return response()->noContent();
        }, 3);
    }
}
