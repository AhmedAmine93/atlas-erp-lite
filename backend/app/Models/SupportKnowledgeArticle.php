<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportKnowledgeArticle extends Model
{
    use BelongsToTenant;
    protected $fillable = ['title', 'content', 'classification', 'status', 'critical', 'version', 'owner_id', 'reviewer_id', 'next_review_at'];
    protected function casts(): array { return ['critical' => 'boolean', 'version' => 'integer', 'next_review_at' => 'datetime']; }
}