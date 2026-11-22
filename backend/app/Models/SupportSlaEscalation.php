<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportSlaEscalation extends Model
{
    use BelongsToTenant;
    protected $fillable = ['incident_id', 'raised_by', 'acknowledged_by', 'target_level', 'status', 'reason', 'acknowledged_at'];
    protected function casts(): array { return ['acknowledged_at' => 'datetime']; }
}