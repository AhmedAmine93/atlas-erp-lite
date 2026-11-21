<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped V5 domain registry; all writes derive company_id from session. */
class MaintenanceAsset extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_assets';
    protected $fillable = ['site_id','asset_code','name','status','commissioned_on'];

    protected function casts(): array
    {
        return [];
    }
}
