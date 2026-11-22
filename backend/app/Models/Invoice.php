<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::addGlobalScope('salesSite', function ($query) {
            if (auth()->check() && app(\App\Services\TenantContext::class)->id() && ($site = \App\Services\SalesAccess::site())) {
                $query->whereHas('order',fn ($order) => $order->where('sales_establishment_id',$site));
            }
        });
    }

    protected $guarded = [];

    protected $casts = ['snapshot' => 'array', 'issued_at' => 'datetime', 'paid_at' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
