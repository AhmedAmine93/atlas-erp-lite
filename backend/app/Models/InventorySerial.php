<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventorySerial extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_serials';
    protected $guarded = [];
    
}
