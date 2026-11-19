<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportIncident extends Model
{
    protected $fillable = ['reference', 'title', 'description', 'severity', 'status', 'environment', 'reported_by', 'assigned_to', 'resolution', 'resolved_at'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
