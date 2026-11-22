<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class MaintenanceTechnician extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_technicians';

    protected $fillable = ['code', 'name', 'team_id', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function team()
    {
        return $this->belongsTo(MaintenanceTeam::class, 'team_id');
    }
}
