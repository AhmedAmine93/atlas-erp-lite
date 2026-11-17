<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
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

        return User::orderBy('name')->paginate(20);
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
            $admin = Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $user = $id ? User::lockForUpdate()->findOrFail($id) : new User;
            if ($id && $user->role_id === $admin->id && (int) $data['role_id'] !== $admin->id) {
                abort_if(User::where('role_id', $admin->id)->count() <= 1, 409, 'Le dernier administrateur doit être conservé.');
            }
            $user->fill($data)->save();
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
            Role::where('name', 'admin')->lockForUpdate()->firstOrFail();
            $user = User::findOrFail($id);
            abort_if($user->role->name === 'admin' && User::where('role_id', $user->role_id)->count() <= 1, 409, 'Le dernier administrateur doit être conservé.');
            DB::table('sessions')->where('user_id', $id)->delete();
            $user->delete();
            Audit::record('user.deleted', $user);

            return response()->noContent();
        }, 3);
    }
}
