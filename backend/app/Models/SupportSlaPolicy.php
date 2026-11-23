<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportSlaPolicy extends Model
{
    use BelongsToTenant;
    protected $fillable = ['severity', 'version', 'response_minutes', 'resolution_minutes', 'calendar', 'effective_from', 'published', 'created_by'];
    protected function casts(): array { return ['published' => 'boolean', 'effective_from' => 'datetime']; }
}