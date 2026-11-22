<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryTransferReceipt extends Model
{
    use BelongsToTenant;

    protected $table = 'inventory_transfer_receipts';
    protected $guarded = [];
    public $timestamps = false;
}
