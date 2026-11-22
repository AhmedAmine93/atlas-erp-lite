<?php

namespace App\Policies;

use App\Models\User;
use App\Services\TenantRoles;

class ErpPolicy
{
    /**
     * P066.04 / P019.05: read abilities follow active COMPANY membership.
     * Never trust users.role_id or client-supplied role/tenant identifiers.
     */
    private function role(User $u): ?string
    {
        return app(TenantRoles::class)->role($u)?->name;
    }

    private function oneOf(User $u, array $roles): bool
    {
        return in_array($this->role($u), $roles, true);
    }

    public function crmRead(User $u): bool
    {
        return $this->oneOf($u, ['admin', 'commercial', 'reader']);
    }

    public function productsRead(User $u): bool
    {
        // Commercial needs catalog items for preparing quotes and orders.
        return $this->oneOf($u, ['admin', 'commercial', 'stock', 'reader']);
    }

    public function movementsRead(User $u): bool
    {
        return $this->oneOf($u, ['admin', 'stock', 'reader']);
    }

    public function salesRead(User $u): bool
    {
        return $this->oneOf($u, ['admin', 'commercial', 'reader']);
    }

    public function invoicesRead(User $u): bool
    {
        return $this->oneOf($u, ['admin', 'commercial', 'reader']);
    }

    public function dashboardRead(User $u): bool
    {
        return $this->oneOf($u, ['admin', 'commercial', 'stock', 'reader']);
    }

    public function crm(User $u): bool
    {
        return in_array(app(TenantRoles::class)->role($u)?->name, ['admin', 'commercial']);
    }

    public function stock(User $u): bool
    {
        return in_array(app(TenantRoles::class)->role($u)?->name, ['admin', 'stock']);
    }

    public function sales(User $u): bool
    {
        return in_array(app(TenantRoles::class)->role($u)?->name, ['admin', 'commercial']);
    }

    public function support(User $u): bool
    {
        return in_array(app(TenantRoles::class)->role($u)?->name, ['admin', 'support_n2', 'support_n3'], true);
    }

    public function supportDiagnostics(User $u): bool
    {
        return in_array(app(TenantRoles::class)->role($u)?->name, ['admin', 'support_n3'], true);
    }

    public function opsRead(User $u): bool
    {
        return $this->oneOf($u, ['admin', 'ops_viewer', 'ops_operator']);
    }

    public function opsWrite(User $u): bool
    {
        return $this->oneOf($u, ['admin', 'ops_operator']);
    }

    public function admin(User $u): bool
    {
        return app(TenantRoles::class)->role($u)?->name === 'admin';
    }
}
