<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
