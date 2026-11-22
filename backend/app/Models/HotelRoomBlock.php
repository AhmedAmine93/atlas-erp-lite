<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class HotelRoomBlock extends Model
{
    use BelongsToTenant;

    protected $table = 'hotel_room_blocks';

    protected $fillable = [
        'hotel_id', 'room_id', 'kind', 'status', 'starts_on', 'ends_on',
        'expires_at', 'reason', 'created_by', 'released_by', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
