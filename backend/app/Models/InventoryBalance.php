<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryBalance extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_balances';
    protected $guarded = [];
    
}
