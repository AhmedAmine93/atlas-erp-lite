<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DataIngestionBatch extends Model
{
    use BelongsToTenant;

    protected $table = 'data_ingestion_batches';
    protected $fillable = ['source_id','batch_key','sha256','expected_rows','status','created_by'];
}
