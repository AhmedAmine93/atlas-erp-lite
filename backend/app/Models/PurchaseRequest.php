<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped V5 domain registry; all writes derive company_id from session. */
class PurchaseRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_requests';
    protected $fillable = ['reference','title','justification','estimated_total_cents','currency','status','requested_by'];

    protected function casts(): array
    {
        return ['estimated_total_cents' => 'integer', 'submitted_at' => 'datetime'];
    }
}
