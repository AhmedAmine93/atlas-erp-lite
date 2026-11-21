<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class HotelBooking extends Model
{
    use BelongsToTenant;

    protected $table = 'hotel_bookings';
    protected $fillable = ['hotel_id','room_id','reference','guest_display_name','arrival_date','departure_date','status','created_by'];
}
