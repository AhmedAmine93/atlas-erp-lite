<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class MaintenancePreventivePlan extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_preventive_plans';

    protected $fillable = [
        'asset_id', 'code', 'title', 'description', 'priority',
        'interval_days', 'next_due_on', 'active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['next_due_on' => 'date', 'active' => 'boolean', 'interval_days' => 'integer'];
    }
}
