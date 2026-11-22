<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryTransfer extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_transfers';
    protected $guarded = [];
    
}
