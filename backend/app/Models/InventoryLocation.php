<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryLocation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function warehouse()
    {
        return $this->belongsTo(InventoryWarehouse::class, 'warehouse_id');
    }
}
