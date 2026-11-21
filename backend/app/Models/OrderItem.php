<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToTenant;

class OrderItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public $timestamps = false;
}
