<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportIncident extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'reference', 'title', 'description', 'severity', 'status', 'environment',
        'reported_by', 'assigned_to', 'resolution', 'resolved_at',
        'response_due_at', 'resolution_due_at', 'first_responded_at',
        'closed_at', 'escalated_at', 'escalation_level',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime', 'response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime', 'first_responded_at' => 'datetime',
            'closed_at' => 'datetime', 'escalated_at' => 'datetime',
            'escalation_level' => 'integer',
        ];
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events()
    {
        return $this->hasMany(SupportIncidentEvent::class)->orderBy('id');
    }
}
