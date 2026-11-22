<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * M15 internal, versioned control-plane API.
 * Provider egress, OAuth consent, payment effects and MQTT adapters are deliberately
 * unavailable until individual contracts, secrets vault and sandbox certification exist.
 */
final class IntegrationsController extends Controller
{
    private const TABLE = [
        'connectors' => 'integration_connectors',
        'api' => 'integration_webhook_receipts',
        'imports' => 'integration_exchange_batches',
        'jobs' => 'integration_jobs',
        'errors' => 'integration_dead_letters',
        'iot' => 'integration_devices',
    ];
    private const SAFE_FIELDS = [
        'connectors' => ['id','code','name','provider','status','owner_id','sandbox_passed_at','secret_version','quota_per_minute','timeout_seconds','created_at'],
        'api' => ['id','connector_id','event_id','event_type','status','created_at'],
        'imports' => ['id','kind','mapping_version','sha256','status','accepted_rows','rejected_rows','report','created_at'],
        'jobs' => ['id','code','name','task','cadence','timezone','suspended','owner_id','created_at'],
        'errors' => ['id','connector_id','source_type','source_reference','correlation_id','cause_code','status','attempt_count','error_summary','next_retry_at','resolved_at','created_at'],
        'iot' => ['id','site_id','code','name','unit','lower_bound','upper_bound','active','created_at'],
    ];

    private function tenant(): int { return app(TenantContext::class)->requireId(); }

    private function allowed(string $area): string
    {
        Gate::authorize('admin');
        abort_unless(array_key_exists($area, self::TABLE), 404);
        return self::TABLE[$area];
    }

    private function scoped(string $table)
    {
        return DB::table($table)->where('company_id', $this->tenant());
    }

    private function row(string $table, int $id, bool $lock = false): object
    {
        $q = $this->scoped($table)->where('id', $id);
        $r = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($r, 404, 'Objet introuvable dans cette entreprise.');
        return $r;
    }

    private function audit(string $kind, int $id, string $action, array $details = []): void
    {
        DB::table('integration_audit_events')->insert([
            'company_id' => $this->tenant(), 'entity_kind' => $kind,
            'entity_id' => $id, 'action' => $action, 'actor_id' => auth()->id(),
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        Audit::record('integrations.'.$kind.'.'.$action, null, ['id'=>$id]);
    }

    private function emit(string $kind, int $id, string $topic, array $payload = []): void
    {
        DB::table('integration_outbox')->insertOrIgnore([
            'company_id'=>$this->tenant(), 'topic'=>$topic,
            'aggregate_kind'=>$kind, 'aggregate_id'=>$id,
            'payload'=>json_encode($payload, JSON_THROW_ON_ERROR), 'created_at'=>now(),
        ]);
    }

    public function index(Request $request, string $area)
    {
        $table = $this->allowed($area);
        $input = $request->validate([
            'page'=>'sometimes|integer|min:1',
            'status'=>'sometimes|string|max:30',
            'search'=>'sometimes|string|max:80',
        ]);
        $q = $this->scoped($table)->select(self::SAFE_FIELDS[$area]);
        if ($input['status'] ?? null) {
            $field = $area === 'jobs' ? 'suspended' : 'status';
            if ($area === 'iot' || $area === 'imports' || $area === 'api') abort(422, 'Ce filtre est indisponible.');
            $q->where($field, $input['status']);
        }
        if ($search = trim($input['search'] ?? '')) {
            $column = match ($area) {
                'connectors','jobs','iot' => 'name',
                'api' => 'event_id', 'imports' => 'mapping_version',
                default => 'source_reference',
            };
            $q->whereRaw('LOWER("'.$column.'") LIKE ?', ['%'.strtolower(addcslashes($search,'%_\\')).'%']);
        }
        return $q->orderByDesc('id')->paginate(20);
    }

    public function store(Request $request, string $area)
    {
        $table = $this->allowed($area);
        abort_unless(in_array($area,['connectors','jobs','iot'],true),405);
        $company = $this->tenant();
        $data = match ($area) {
            'connectors' => $request->validate([
                'code'=>['required','string','max:60','regex:/^[A-Za-z0-9_-]+$/',Rule::unique($table)->where('company_id',$company)],
                'name'=>'required|string|max:180',
                'provider'=>['required',Rule::in(['mock','sirene','microsoft_graph','google_workspace','sap','oracle','sqlserver','pms','psp','iot'])],
                'quota_per_minute'=>'sometimes|integer|min:1|max:600',
                'timeout_seconds'=>'sometimes|integer|min:1|max:30',
                'secret'=>'sometimes|string|min:32|max:4096',
                'owner_id'=>'prohibited', 'company_id'=>'prohibited', 'status'=>'prohibited',
                'sandbox_passed_at'=>'prohibited', 'secret_ciphertext'=>'prohibited',
            ]),
            'jobs' => $request->validate([
                'code'=>['required','string','max:60','regex:/^[A-Za-z0-9_-]+$/',Rule::unique($table)->where('company_id',$company)],
                'name'=>'required|string|max:180',
                'task'=>['required',Rule::in(['health_snapshot'])],
                'cadence'=>['required',Rule::in(['hourly','daily'])],
                'timezone'=>['required','timezone'],
                'company_id'=>'prohibited','owner_id'=>'prohibited','suspended'=>'prohibited',
            ]),
            'iot' => $request->validate([
                'site_id'=>['required','integer',Rule::exists('maintenance_sites','id')->where('company_id',$company)],
                'code'=>['required','string','max:60',Rule::unique($table)->where('company_id',$company)],
                'name'=>'required|string|max:180',
                'unit'=>'required|string|max:24',
                'lower_bound'=>'nullable|numeric|between:-999999999,999999999',
                'upper_bound'=>'nullable|numeric|between:-999999999,999999999',
                'secret'=>'required|string|min:32|max:4096',
                'company_id'=>'prohibited','active'=>'prohibited',
                'secret_ciphertext'=>'prohibited',
            ]),
            default => [],
        };
        if ($area==='iot' && isset($data['lower_bound'],$data['upper_bound']) &&
            $data['lower_bound']>$data['upper_bound']) {
            throw ValidationException::withMessages(['upper_bound'=>'La borne haute doit dépasser la borne basse.']);
        }
        return DB::transaction(function () use ($area,$table,$data,$company) {
            if (isset($data['secret'])) {
                $data['secret_ciphertext'] = Crypt::encryptString($data['secret']);
                unset($data['secret']);
            }
            if (in_array($area,['connectors','jobs'],true)) {
                $data['owner_id'] = auth()->id();
            }
            $id=DB::table($table)->insertGetId(array_merge($data,[
                'company_id'=>$company,'created_at'=>now(),'updated_at'=>now(),
            ]));
            $this->audit($area,$id,'created');
            return response()->json($this->scoped($table)->where('id',$id)->first(self::SAFE_FIELDS[$area]),201);
        });
    }

    public function connectorAction(Request $request, int $id, string $action)
    {
        $this->allowed('connectors');
        abort_unless(in_array($action,['sandbox','activate','suspend','rotate'],true),404);
        $payload = $request->validate([
            'secret'=>$action==='rotate'?'required|string|min:32|max:4096':'prohibited',
            'expected_version'=>'required|integer|min:0',
        ]);
        return DB::transaction(function () use ($id,$action,$payload) {
            $c=$this->row(self::TABLE['connectors'],$id,true);
            abort_unless((int)$c->secret_version===$payload['expected_version'],409,'Version obsolète.');
            $update=['updated_at'=>now()];
            if ($action==='sandbox') {
                abort_unless($c->status==='draft' && $c->provider==='mock' && $c->secret_ciphertext,409,'Seul un mock avec secret peut passer ce contrôle local.');
                $update['sandbox_passed_at']=now();
            } elseif ($action==='activate') {
                abort_unless($c->provider==='mock' && $c->sandbox_passed_at && $c->secret_ciphertext && $c->owner_id,409,'Connecteur fournisseur non certifié ou sandbox absente.');
                $update['status']='active';
            } elseif ($action==='suspend') {
                abort_unless($c->status==='active',409);
                $update['status']='suspended';
            } else {
                abort_unless($c->status!=='active',409,'Suspendre avant rotation pour éviter une fenêtre de secret ambiguë.');
                $update['secret_ciphertext']=Crypt::encryptString($payload['secret']);
                $update['secret_version']=$c->secret_version+1;
                $update['sandbox_passed_at']=null;
                $update['status']='draft';
            }
            $this->scoped(self::TABLE['connectors'])->where('id',$id)->update($update);
            $this->audit('connectors',$id,$action,['version'=>$update['secret_version']??$c->secret_version]);
            return response()->json($this->scoped(self::TABLE['connectors'])->where('id',$id)->first(self::SAFE_FIELDS['connectors']));
        });
    }

    /** Signed sandbox ingress. There is no payment/finance write behind this endpoint. */
    public function receiveWebhook(Request $request, int $connector)
    {
        $this->allowed('api');
        $body = $request->getContent();
        abort_if(strlen($body)>65536,413);
        $data=$request->validate([
            'event_id'=>'required|string|max:120',
            'event_type'=>'required|string|max:80',
            'payload'=>'required|array',
        ]);
        $time=$request->header('X-Atlas-Webhook-Timestamp');
        $signature=$request->header('X-Atlas-Webhook-Signature');
        abort_unless(is_string($time) && preg_match('/^[0-9]{10}$/D',$time) && abs(time()-(int)$time)<=300,401,'Horodatage invalide.');
        abort_unless(is_string($signature) && preg_match('/^[a-f0-9]{64}$/D',$signature),401,'Signature absente.');
        $c=$this->row(self::TABLE['connectors'],$connector);
        abort_unless($c->provider==='mock' && $c->status==='active' && $c->secret_ciphertext,409,'Connecteur sandbox inactif.');
        $expected=hash_hmac('sha256',$time.'.'.$body,Crypt::decryptString($c->secret_ciphertext));
        abort_unless(hash_equals($expected,$signature),401,'Signature incorrecte.');
        $digest=hash('sha256',$body);
        return DB::transaction(function () use ($connector,$data,$digest) {
            $table=self::TABLE['api'];
            DB::table($table)->insertOrIgnore([
                'company_id'=>$this->tenant(), 'connector_id'=>$connector,
                'event_id'=>$data['event_id'], 'event_type'=>$data['event_type'],
                'payload_sha256'=>$digest, 'status'=>'accepted',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $r=$this->scoped($table)->where('connector_id',$connector)->where('event_id',$data['event_id'])->first();
            abort_unless(hash_equals($r->payload_sha256,$digest),409,'Identifiant événement déjà utilisé avec un autre contenu.');
            $this->emit('webhook',$r->id,'integration.webhook.accepted',['receipt_id'=>$r->id,'event_type'=>$r->event_type]);
            return response()->json(['receipt_id'=>$r->id,'status'=>$r->status],202);
        });
    }

    /** CSV-like JSON preview; durable validation report, no domain mutations. */
    public function exchange(Request $request)
    {
        $this->allowed('imports');
        $data=$request->validate([
            'kind'=>['required',Rule::in(['import','export'])],
            'mapping_version'=>'required|string|max:30',
            'rows'=>'required|array|max:500',
            'rows.*'=>'required|array',
            'rows.*.external_id'=>'required|string|max:120',
            'rows.*.value'=>'required|string|max:300',
            'commit'=>'required|boolean',
        ]);
        $seen=[];$report=[];$valid=0;$invalid=0;
        foreach ($data['rows'] as $i=>$line) {
            $id=$line['external_id']??null;
            $reason=!is_string($id)||trim($id)===''?'invalid_reference':
                (isset($seen[$id])?'duplicate_reference':null);
            $seen[$id]=true;
            if ($reason) $invalid++; else $valid++;
            $report[]=['line'=>$i+1,'external_id'=>is_string($id)?$id:null,'status'=>$reason?'rejected':'accepted','reason'=>$reason];
        }
        $hash=hash('sha256',json_encode(['kind'=>$data['kind'],'mapping'=>$data['mapping_version'],'rows'=>$data['rows']],JSON_THROW_ON_ERROR));
        if (!$data['commit']) return response()->json(['preview'=>true,'accepted_rows'=>$valid,'rejected_rows'=>$invalid,'report'=>$report]);
        return DB::transaction(function () use ($data,$hash,$valid,$invalid,$report) {
            $table=self::TABLE['imports'];
            DB::table($table)->insertOrIgnore([
                'company_id'=>$this->tenant(),'kind'=>$data['kind'],
                'mapping_version'=>$data['mapping_version'],'sha256'=>$hash,
                'status'=>$invalid?'needs_review':'validated',
                'accepted_rows'=>$valid,'rejected_rows'=>$invalid,
                'report'=>json_encode($report,JSON_THROW_ON_ERROR),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $r=$this->scoped($table)->where('kind',$data['kind'])->where('mapping_version',$data['mapping_version'])->where('sha256',$hash)->first();
            $this->emit('exchange',$r->id,'integration.exchange.validated',['batch_id'=>$r->id]);
            return response()->json(['id'=>$r->id,'status'=>$r->status,'accepted_rows'=>$r->accepted_rows,'rejected_rows'=>$r->rejected_rows],201);
        });
    }

    public function jobAction(Request $request,int $id,string $action)
    {
        $this->allowed('jobs');
        abort_unless(in_array($action,['resume','suspend','run'],true),404);
        $request->validate(['window_key'=>'sometimes|string|max:80']);
        return DB::transaction(function () use ($id,$action,$request) {
            $j=$this->row(self::TABLE['jobs'],$id,true);
            if ($action==='resume' || $action==='suspend') {
                $this->scoped(self::TABLE['jobs'])->where('id',$id)->update(['suspended'=>$action==='suspend','updated_at'=>now()]);
                $this->audit('jobs',$id,$action);
                return response()->json(['id'=>$id,'suspended'=>$action==='suspend']);
            }
            abort_if($j->suspended,409,'Tâche suspendue.');
            $now=CarbonImmutable::now($j->timezone);
            $window=$request->input('window_key') ?: ($j->cadence==='hourly'?$now->format('Y-m-d-H'):$now->format('Y-m-d'));
            abort_unless($window===($j->cadence==='hourly'?$now->format('Y-m-d-H'):$now->format('Y-m-d')),422,'Fenêtre non courante.');
            DB::table('integration_job_runs')->insertOrIgnore([
                'company_id'=>$this->tenant(),'job_id'=>$id,'window_key'=>$window,
                'status'=>'completed','result'=>json_encode(['health'=>'ok','provider_calls'=>0]),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $r=$this->scoped('integration_job_runs')->where('job_id',$id)->where('window_key',$window)->first();
            $this->emit('job_run',$r->id,'integration.job.completed',['run_id'=>$r->id]);
            return response()->json(['run_id'=>$r->id,'window_key'=>$window,'status'=>$r->status]);
        });
    }

    public function resolveError(Request $request,int $id)
    {
        $this->allowed('errors');
        $data=$request->validate(['resolution'=>'required|string|min:8|max:250']);
        return DB::transaction(function () use ($id,$data) {
            $r=$this->row(self::TABLE['errors'],$id,true);
            abort_unless($r->status==='needs_review',409,'Déjà résolu.');
            $this->scoped(self::TABLE['errors'])->where('id',$id)->update(['status'=>'resolved','resolved_at'=>now(),'updated_at'=>now()]);
            $this->audit('errors',$id,'resolved',['resolution'=>$data['resolution']]);
            return response()->json(['id'=>$id,'status'=>'resolved']);
        });
    }

    public function telemetry(Request $request,int $id)
    {
        $this->allowed('iot');
        $data=$request->validate([
            'event_id'=>'required|string|max:120',
            'value'=>'required|numeric|between:-999999999,999999999',
            'observed_at'=>'required|date',
            'secret'=>'required|string|min:32|max:4096',
        ]);
        $device=$this->row(self::TABLE['iot'],$id);
        abort_unless($device->active && hash_equals(Crypt::decryptString($device->secret_ciphertext),$data['secret']),403,'Identité de capteur refusée.');
        $quality=(($device->lower_bound!==null && $data['value']<$device->lower_bound) || ($device->upper_bound!==null && $data['value']>$device->upper_bound))?'needs_review':'valid';
        return DB::transaction(function () use ($id,$data,$quality) {
            DB::table('integration_telemetry')->insertOrIgnore([
                'company_id'=>$this->tenant(),'device_id'=>$id,
                'event_id'=>$data['event_id'],'value'=>$data['value'],
                'quality'=>$quality,'observed_at'=>$data['observed_at'],
                'created_at'=>now(),
            ]);
            $r=$this->scoped('integration_telemetry')->where('device_id',$id)->where('event_id',$data['event_id'])->first();
            abort_unless((float)$r->value===(float)$data['value'] &&
                CarbonImmutable::parse($r->observed_at)->equalTo(CarbonImmutable::parse($data['observed_at'])),409,'Référence télémétrie déjà utilisée différemment.');
            return response()->json(['id'=>$r->id,'quality'=>$r->quality],202);
        });
    }

    public function history(Request $request,string $area,int $id)
    {
        $table=$this->allowed($area);
        $this->row($table,$id);
        return $this->scoped('integration_audit_events')
            ->where('entity_kind',$area)->where('entity_id',$id)
            ->select('id','action','actor_id','details','created_at')
            ->orderByDesc('id')->paginate(20);
    }
}
