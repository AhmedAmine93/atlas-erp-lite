<?php

namespace App\Policies;

use App\Models\User;
use App\Services\TenantRoles;

class ErpPolicy
{
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

    public function admin(User $u): bool
    {
        return app(TenantRoles::class)->role($u)?->name === 'admin';
    }
}
