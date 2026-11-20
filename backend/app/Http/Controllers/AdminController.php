<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\Audit;
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

        return User::whereHas('companies', fn ($query) => $query->whereKey(app(TenantContext::class)->requireId()))->orderBy('name')->paginate(20);
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
        $data = $r->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($id)],
            'role_id' => 'required|integer|exists:roles,id', 'password' => [$id ? 'nullable' : 'required', 'string', Password::min(12)->letters()->numbers()]]);
        if (empty($data['password'])) {
            unset($data['password']);
        }

        return DB::transaction(function () use ($id, $data) {
            $tenant = app(TenantContext::class)->requireId();
            $admin = Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $user = $id ? User::whereHas('companies', fn ($query) => $query->whereKey($tenant))->lockForUpdate()->firstWhere('id', $id) : new User;
            abort_if($id && ! $user, 404);
            if ($id && $user->role_id === $admin->id && (int) $data['role_id'] !== $admin->id) {
                abort_if(User::where('role_id', $admin->id)->whereHas('companies', fn ($query) => $query->whereKey($tenant))->count() <= 1, 409, 'Le dernier administrateur doit être conservé.');
            }
            if ($id && $user->companies()->count() > 1 && (int) $data['role_id'] !== $user->role_id) {
                abort(409, 'Les rôles des comptes multi-entreprises doivent être gérés par affiliation.');
            }
            $user->fill($data)->save();
            if (! $id) {
                $user->companies()->attach($tenant);
            }
            if ($id) {
                DB::table('sessions')->where('user_id', $id)->delete();
            }
            Audit::record($id ? 'user.updated' : 'user.created', $user, ['role_id' => $user->role_id]);

            return response()->json($user->load('role'), $id ? 200 : 201);
        }, 3);
    }

    public function destroy(int $id)
    {
        Gate::authorize('admin');
        abort_if($id === auth()->id(), 409, 'Vous ne pouvez pas supprimer votre propre compte.');

        return DB::transaction(function () use ($id) {
            $tenant = app(TenantContext::class)->requireId();
            Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $user = User::whereHas('companies', fn ($query) => $query->whereKey($tenant))->findOrFail($id);
            abort_if($user->role->name === 'admin' && User::where('role_id', $user->role_id)->whereHas('companies', fn ($query) => $query->whereKey($tenant))->count() <= 1, 409, 'Le dernier administrateur doit être conservé.');
            DB::table('sessions')->where('user_id', $id)->delete();
            $user->companies()->detach($tenant);
            if (! $user->companies()->exists()) {
                $user->delete();
            }
            Audit::record('user.removed_from_company', $user);

            return response()->noContent();
        }, 3);
    }
}
