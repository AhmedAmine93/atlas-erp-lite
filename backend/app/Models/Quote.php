<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

class Quote extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function items()
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
