<?php

namespace App\Models\Concerns;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = app(TenantContext::class)->id();
            // Fail closed, including in unauthenticated console commands.
            if (! $tenantId) {
                $query->whereRaw('1 = 0');
                return;
            }
            $query->where($query->getModel()->qualifyColumn('company_id'), $tenantId);
        });

        static::creating(function ($model) {
            // Ignore any client-supplied company_id, including mass assignment.
            $model->company_id = app(TenantContext::class)->requireId();
        });
    }
}
