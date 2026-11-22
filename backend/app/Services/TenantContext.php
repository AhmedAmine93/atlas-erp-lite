<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class TenantContext
{
    public function id(): ?int
    {
        $fromMiddleware = request()->attributes->get('atlas_company_id');
        if ($fromMiddleware) {
            return (int) $fromMiddleware;
        }

        $user = Auth::user();
        if (! $user) {
            return null;
        }

        $selected = request()->hasSession() ? request()->session()->get('active_company_id') : null;
        if ($selected && $user->companies()->whereKey((int) $selected)->exists()) {
            return (int) $selected;
        }

        $first = $user->companies()->orderBy('companies.id')->value('companies.id');

        return $first ? (int) $first : null;
    }

    public function requireId(): int
    {
        $companyId = $this->id();
        abort_unless($companyId, 403, 'Aucune entreprise autorisée dans cette session.');

        return $companyId;
    }
}
