<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryCount extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_counts';
    protected $guarded = [];
    
}
