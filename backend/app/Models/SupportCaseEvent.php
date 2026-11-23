<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportCaseEvent extends Model
{
    use BelongsToTenant;

    public $timestamps = false;
    protected $fillable = ['case_id', 'actor_id', 'action', 'from_status', 'to_status', 'version', 'details'];
    protected function casts(): array { return ['details' => 'array', 'created_at' => 'datetime']; }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}