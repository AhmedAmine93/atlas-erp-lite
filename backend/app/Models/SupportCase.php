<?php
namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SupportCase extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'reference', 'kind', 'title', 'description', 'status', 'priority',
        'requester_id', 'assigned_to', 'approved_by', 'catalog_id',
        'approved_version', 'version', 'service_name', 'risk_level',
        'window_start', 'window_end', 'rollback_plan', 'root_cause',
        'workaround', 'permanent_fix', 'result_evidence', 'pipeline_url',
        'verification', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'approved_version' => 'integer',
            'window_start' => 'datetime', 'window_end' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function events() { return $this->hasMany(SupportCaseEvent::class, 'case_id')->orderBy('id'); }
    public function incidents() { return $this->belongsToMany(SupportIncident::class, 'support_case_incidents', 'case_id', 'incident_id')->withPivot('company_id', 'created_by'); }
    public function requester() { return $this->belongsTo(User::class, 'requester_id'); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function catalog() { return $this->belongsTo(SupportServiceCatalog::class, 'catalog_id'); }
}