<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MaintenanceWorkOrder extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_work_orders';
    protected $fillable = [
        'maintenance_request_id', 'asset_id', 'priority', 'summary',
        'due_at', 'status', 'resolution', 'completed_at',
    ];
    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
