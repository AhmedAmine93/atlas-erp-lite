<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped V5 domain registry; all writes derive company_id from session. */
class HotelProperty extends Model
{
    use BelongsToTenant;

    protected $table = 'hotel_properties';
    protected $fillable = ['code','name','timezone','active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
