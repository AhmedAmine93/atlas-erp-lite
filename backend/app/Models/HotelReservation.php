<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HotelReservation extends Model
{
    use BelongsToTenant;

    protected $table = 'hotel_reservations';

    protected $fillable = [
        'hotel_id','room_id','reference','guest_name','arrival_on',
        'departure_on','channel','status','created_by',
    ];

    protected function casts(): array
    {
        return ['arrival_on' => 'date:Y-m-d', 'departure_on' => 'date:Y-m-d'];
    }
}
