<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DataCanonicalSchema extends Model
{
    use BelongsToTenant;

    protected $table = 'data_canonical_schemas';

    protected $fillable = ['source_id', 'version', 'name', 'fields', 'fingerprint', 'created_by'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'version' => 'integer'];
    }
}
