<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryLot extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_lots';
    protected $guarded = [];
    
}
