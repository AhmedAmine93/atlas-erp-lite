<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportIncidentEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'support_incident_id', 'actor_id', 'action',
        'from_status', 'to_status', 'message',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
