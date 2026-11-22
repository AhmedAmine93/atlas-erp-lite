<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Tenant-scoped V5 domain registry; all writes derive company_id from session. */
class DataSourceRegistry extends Model
{
    use BelongsToTenant;

    protected $table = 'data_source_registry';
    protected $fillable = ['code','name','source_type','classification','status','schema_version','purpose'];

    protected function casts(): array
    {
        return [];
    }
}
