<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class MinibarProduct extends Model
{
    use BelongsToTenant;
    public $timestamps = false;
    protected $fillable = ['hotel_id','sku','name','active'];
    protected $casts = ['active' => 'boolean'];
}
