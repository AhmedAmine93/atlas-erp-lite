<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped V5 domain registry; all writes derive company_id from session. */
class MaintenanceSite extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_sites';
    protected $fillable = ['code','name','active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
