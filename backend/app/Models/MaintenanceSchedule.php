<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class MaintenanceSchedule extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_schedules';

    protected $fillable = [
        'work_order_id', 'technician_id', 'starts_at', 'ends_at',
        'status', 'planned_by', 'cancelled_by', 'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function technician()
    {
        return $this->belongsTo(MaintenanceTechnician::class, 'technician_id');
    }

    public function workOrder()
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'work_order_id');
    }
}
