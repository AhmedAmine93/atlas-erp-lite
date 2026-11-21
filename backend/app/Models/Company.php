<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    protected $fillable = ['code', 'name', 'email', 'phone', 'address'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'company_user')->withPivot('role_id');
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(Establishment::class);
    }
}
