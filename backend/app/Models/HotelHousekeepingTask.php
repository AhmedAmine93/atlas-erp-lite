<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class HotelHousekeepingTask extends Model
{
    use BelongsToTenant;

    protected $table = 'hotel_housekeeping_tasks';

    protected $fillable = [
        'hotel_id', 'room_id', 'business_date', 'status',
        'created_by', 'inspected_by', 'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'inspected_at' => 'datetime',
        ];
    }
}
