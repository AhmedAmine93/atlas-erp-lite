<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryReplenishment extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_replenishments';
    protected $guarded = [];
    
}
