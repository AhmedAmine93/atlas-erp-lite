<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped V5 domain registry; all writes derive company_id from session. */
class HotelRoom extends Model
{
    use BelongsToTenant;

    protected $table = 'hotel_rooms';
    protected $fillable = ['hotel_id','room_number','floor_code','category','room_status'];

    protected function casts(): array
    {
        return [];
    }
}
