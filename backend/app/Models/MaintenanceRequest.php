<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class MaintenanceRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'maintenance_requests';
    protected $fillable = ['asset_id','reference','title','description','priority','status','created_by'];
}
