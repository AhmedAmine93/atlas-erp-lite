<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;

/**
 * A role is granted by an explicit membership in the active company.
 * Never fall back to users.role_id for authorization.
 */
class TenantRoles
{
    public function role(User $user, ?int $companyId = null): ?Role
    {
        $companyId ??= app(TenantContext::class)->id();
        if (! $companyId) {
            return null;
        }

        return Role::query()
            ->join('company_user', 'company_user.role_id', '=', 'roles.id')
            ->where('company_user.user_id', $user->id)
            ->where('company_user.company_id', $companyId)
            ->select('roles.id', 'roles.name')
            ->first();
    }

    public function profile(User $user, int $companyId): array
    {
        $role = $this->role($user, $companyId);
        abort_unless($role, 403, 'Aucun rôle autorisé pour cette entreprise.');

        return array_merge($user->toArray(), [
            'role_id' => $role->id,
            'role' => $role->toArray(),
            'active_company_id' => $companyId,
            'companies' => $user->companies()
                ->select('companies.id', 'companies.name', 'companies.code')
                ->orderBy('companies.id')
                ->get()
                ->map(fn ($company) => $company->only(['id', 'name', 'code']))
                ->all(),
        ]);
    }
}
