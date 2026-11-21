<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MaintenanceIntervention extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_interventions';

    protected $fillable = [
        'site_id', 'asset_id', 'title', 'description', 'category',
        'priority', 'status', 'submitted_by', 'qualified_by', 'qualified_at',
    ];

    protected function casts(): array
    {
        return ['qualified_at' => 'datetime'];
    }
}
