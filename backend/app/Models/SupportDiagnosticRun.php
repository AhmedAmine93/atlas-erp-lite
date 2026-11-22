<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportDiagnosticRun extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'support_incident_id', 'triggered_by', 'check_name', 'status', 'duration_ms',
    ];

    protected $casts = ['duration_ms' => 'integer'];
}
