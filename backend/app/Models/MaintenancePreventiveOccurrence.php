<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class MaintenancePreventiveOccurrence extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_preventive_occurrences';

    protected $fillable = ['plan_id', 'due_on', 'maintenance_request_id', 'work_order_id'];

    protected function casts(): array
    {
        return ['due_on' => 'date'];
    }
}
