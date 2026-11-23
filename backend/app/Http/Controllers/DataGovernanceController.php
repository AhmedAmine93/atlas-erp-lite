<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * M14 governance catalogues. Declarative records ONLY: no arbitrary SQL,
 * Python, network destinations, scheduled jobs, or secret handling.
 * Execution/integration remains a separate audited phase.
 */
final class DataGovernanceController extends Controller
{
    private const TABLES = [
        'pipelines' => 'data_pipelines',
        'quality-rules' => 'data_quality_rules',
        'lineage' => 'data_lineage_edges',
        'reconciliations' => 'data_reconciliation_runs',
        'lakehouse' => 'data_lakehouse_datasets',
    ];

    private function table(string $domain): string {
        abort_unless(isset(self::TABLES[$domain]), 404);
        return self::TABLES[$domain];
    }

    public function index(Request $request, string $domain) {
        Gate::authorize('admin');
        $table = $this->table($domain);
        $company = app(TenantContext::class)->requireId();
        $filters = $request->validate([
            'status' => ['sometimes',Rule::in(['draft','active','disabled'])],
            'code' => 'sometimes|string|max:100',
            'page' => 'sometimes|integer|min:1',
        ]);
        return DB::table($table)->where('company_id',$company)
            ->when(isset($filters['status']),fn($q)=>$q->where('status',$filters['status']))
            ->when(isset($filters['code']),fn($q)=>$q->where('code',$filters['code']))
            ->orderByDesc('id')->paginate(20);
    }

    public function store(Request $request, string $domain) {
        Gate::authorize('admin');
        $table = $this->table($domain);
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'code' => 'required|string|max:100|regex:/^[A-Za-z0-9_.:-]+$/',
            'name' => 'required|string|max:180',
            'version' => 'required|integer|min:1|max:1000000',
            'configuration' => 'required|array|min:1|max:20',
            'company_id' => 'prohibited',
            'created_by' => 'prohibited',
            'status' => 'prohibited',
            'sql' => 'prohibited',
            'password' => 'prohibited',
            'token' => 'prohibited',
            'credentials' => 'prohibited',
        ]);
        // Only explicitly permitted keys; never accept executable or connector configuration.
        $allowed = [
            'pipelines' => ['source_code','destination_code','transformation_version','window','owner','replay_strategy'],
            'quality-rules' => ['source_code','field','check','threshold','severity','owner'],
            'lineage' => ['source_code','source_field','target_code','target_field','transformation_version'],
            'reconciliations' => ['left_batch_id','right_batch_id','expected_rows','expected_amount_cents','actual_rows','actual_amount_cents','justification'],
            'lakehouse' => ['dataset','zone','partition_key','retention_days','owner','schema_version'],
        ][$domain];
        foreach ($data['configuration'] as $key=>$value) {
            abort_unless(in_array($key,$allowed,true),422,'Attribut de configuration inconnu.');
            abort_unless(is_string($value) || is_int($value) || is_bool($value) || is_null($value),
                422,'Valeur de configuration non prise en charge.');
            if (is_string($value)) abort_unless(mb_strlen($value)<=500,422,'Valeur trop longue.');
        }
        return DB::transaction(function() use($request,$table,$domain,$company,$data){
            $payload = json_encode($data['configuration'],JSON_THROW_ON_ERROR);
            // Unique tenant/code/version serializes concurrent inserts even if no row exists.
            // insertOrIgnore is safe on PostgreSQL READ COMMITTED, unlike locking absence.
            $created = DB::table($table)->insertOrIgnore([
                'company_id'=>$company,'code'=>$data['code'],'name'=>$data['name'],
                'version'=>$data['version'],'configuration'=>$payload,
                'status'=>'draft','created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $existing = DB::table($table)->where('company_id',$company)
                ->where('code',$data['code'])->where('version',$data['version'])->firstOrFail();
            if (! $created) {
                abort_unless($existing->name===$data['name'] &&
                    json_decode($existing->configuration,true) == $data['configuration'],
                    409,'Référence/version existante avec contenu différent.');
                return response()->json($existing,200);
            }
            if ($created) Audit::record('data.governance.'.$domain.'.created',null,
                ['record_id'=>$existing->id,'code'=>$data['code']]);
            return response()->json($existing,$created ? 201 : 200);
        },3);
    }
}
