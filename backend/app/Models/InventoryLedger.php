<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_ledger';
    protected $guarded = [];
    public $timestamps = false;
}
