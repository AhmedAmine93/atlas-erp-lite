<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportServiceCatalog extends Model
{
    use BelongsToTenant;
    protected $fillable = ['code', 'title', 'description', 'requires_approval', 'sensitive', 'eligible_role', 'active', 'version'];
    protected function casts(): array { return ['requires_approval' => 'boolean', 'sensitive' => 'boolean', 'active' => 'boolean', 'version' => 'integer']; }
}