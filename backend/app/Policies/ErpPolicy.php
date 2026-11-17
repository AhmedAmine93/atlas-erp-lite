<?php

namespace App\Policies;

use App\Models\User;

class ErpPolicy
{
    public function crm(User $u): bool
    {
        return in_array($u->role->name, ['admin', 'commercial']);
    }

    public function stock(User $u): bool
    {
        return in_array($u->role->name, ['admin', 'stock']);
    }

    public function sales(User $u): bool
    {
        return in_array($u->role->name, ['admin', 'commercial']);
    }

    public function admin(User $u): bool
    {
        return $u->role->name === 'admin';
    }
}
