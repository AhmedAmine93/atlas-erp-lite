<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Establishment extends Model
{
    protected $fillable = ['company_id', 'code', 'name', 'city', 'address', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
