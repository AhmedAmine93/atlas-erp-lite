<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::addGlobalScope('salesSite', function ($query) {
            if (auth()->check() && app(\App\Services\TenantContext::class)->id() && ($site = \App\Services\SalesAccess::site())) {
                $query->where($query->getModel()->qualifyColumn('sales_establishment_id'),$site);
            }
        });
    }

    protected $guarded = [];

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }
}
