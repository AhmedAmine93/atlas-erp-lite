<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryWarehouse extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function locations()
    {
        return $this->hasMany(InventoryLocation::class, 'warehouse_id');
    }
}
