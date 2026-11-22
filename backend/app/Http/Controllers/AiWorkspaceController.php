<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceRequest;
use App\Models\MaintenanceWorkOrder;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * M16 initial guarded domain API. No inference provider is silently simulated:
 * assistant and search use only verified, authorised registered excerpts.
 * Forecasts/anomaly scores are registered observations, not claimed model inference.
 */
final class AiWorkspaceController extends Controller
{
    private function tenant(): int { return app(TenantContext::class)->requireId(); }
    private function read(): void { Gate::authorize('admin'); }
    private function rows(string $table) { return DB::table($table)->where('company_id', $this->tenant()); }
    private function one(string $table, int $id, bool $lock = false): object
    {
        $query = $this->rows($table)->where('id', $id);
        if ($lock) $query->lockForUpdate();
        return $query->first() ?? abort(404);
    }
    private function event(string $name, array $evidence, ?int $suggestion = null): void
    {
        DB::table('ai_events')->insert([
            'company_id' => $this->tenant(), 'actor_id' => auth()->id(),
            'suggestion_id' => $suggestion, 'event_type' => $name,
            'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
    private function listing(string $table, Request $r, array $fields)
    {
        $data = $r->validate(['status' => 'nullable|string|max:32', 'page' => 'nullable|integer|min:1']);
        $q = $this->rows($table)->select($fields);
        if (isset($data['status'])) $q->where('status', $data['status']);
        return $q->orderByDesc('id')->paginate(20);
    }
    public function models(Request $r) {
        $this->read();
        return $this->listing('ai_models', $r, ['id','code','name','version','kind','status','policy_version','description','created_at']);
    }
    public function createModel(Request $r) {
        $this->read();
        $d=$r->validate([
            'code'=>'required|string|max:80', 'name'=>'required|string|max:160',
            'version'=>'required|string|max:60', 'kind'=>['required', Rule::in(['rules','predictive','llm'])],
            'policy_version'=>'required|string|max:60', 'description'=>'nullable|string|max:2000',
            'company_id'=>'prohibited','status'=>'prohibited'
        ]);
        $id=DB::table('ai_models')->insertGetId(array_merge($d,[
            'company_id'=>$this->tenant(),'status'=>'draft','created_at'=>now(),'updated_at'=>now()
        ]));
        $this->event('model.created',['id'=>$id]);
        return response()->json($this->one('ai_models',$id),201);
    }
    public function activateModel(int $id) {
        $this->read();
        return DB::transaction(function () use ($id) {
            $m=$this->one('ai_models',$id,true);
            abort_unless($m->status==='draft',409,'Version non activable.');
            $this->rows('ai_models')->where('id',$id)->update(['status'=>'active','updated_at'=>now()]);
            $this->event('model.activated',['id'=>$id,'version'=>$m->version]);
            return response()->json($this->one('ai_models',$id));
        },3);
    }
    public function agents(Request $r) {
        $this->read();
        return $this->listing('ai_agents',$r,['id','code','name','model_id','status','configuration_version','allowed_tools','max_steps','created_at']);
    }
    public function createAgent(Request $r) {
        $this->read();
        $d=$r->validate([
            'code'=>'required|string|max:80','name'=>'required|string|max:160',
            'model_id'=>'nullable|integer|min:1','max_steps'=>'nullable|integer|min:1|max:5',
            'allowed_tools'=>'required|array|max:5',
            'allowed_tools.*'=>['string',Rule::in(['read_verified_documents'])],
            'company_id'=>'prohibited','status'=>'prohibited','configuration_version'=>'prohibited',
        ]);
        if (isset($d['model_id'])) $this->one('ai_models',(int)$d['model_id']);
        $id=DB::table('ai_agents')->insertGetId([
            'company_id'=>$this->tenant(), 'code'=>$d['code'],'name'=>$d['name'],
            'model_id'=>$d['model_id']??null,'max_steps'=>$d['max_steps']??1,
            'allowed_tools'=>json_encode(array_values(array_unique($d['allowed_tools'])), JSON_THROW_ON_ERROR),
            'status'=>'draft','configuration_version'=>1,'created_at'=>now(),'updated_at'=>now()
        ]);
        $this->event('agent.created',['id'=>$id]);
        return response()->json($this->one('ai_agents',$id),201);
    }
    public function activateAgent(int $id) {
        $this->read();
        return DB::transaction(function () use ($id) {
            $agent=$this->one('ai_agents',$id,true);
            abort_unless($agent->status==='draft',409,'Agent non activable.');
            if ($agent->model_id) {
                $model=$this->one('ai_models',(int)$agent->model_id);
                abort_unless($model->status==='active',409,'Modèle non publié.');
            }
            $this->rows('ai_agents')->where('id',$id)->update(['status'=>'active','updated_at'=>now()]);
            $this->event('agent.activated',['id'=>$id,'configuration_version'=>$agent->configuration_version]);
            return response()->json($this->one('ai_agents',$id));
        },3);
    }
    public function documents(Request $r) {
        $this->read();
        return $this->listing('ai_documents',$r,['id','title','source_type','source_ref','classification','status','establishment_id','created_at','revoked_at']);
    }
    public function createDocument(Request $r) {
        $this->read();
        $d=$r->validate([
            'title'=>'required|string|max:180','source_type'=>'required|string|max:60',
            'source_ref'=>'required|string|max:240','excerpt'=>'required|string|max:5000',
            'classification'=>['required',Rule::in(['public','internal','confidential','restricted'])],
            'establishment_id'=>'nullable|integer|min:1',
            'company_id'=>'prohibited','status'=>'prohibited',
        ]);
        if (isset($d['establishment_id'])) {
            abort_unless(DB::table('establishments')->where('company_id',$this->tenant())->where('id',$d['establishment_id'])->exists(),422,'Établissement étranger au tenant.');
        }
        $id=DB::table('ai_documents')->insertGetId(array_merge($d,[
            'company_id'=>$this->tenant(), 'status'=>'active','created_at'=>now(),'updated_at'=>now()
        ]));
        $this->event('document.registered',['id'=>$id,'classification'=>$d['classification']]);
        return response()->json($this->one('ai_documents',$id),201);
    }
    public function revokeDocument(int $id) {
        $this->read();
        return DB::transaction(function () use ($id) {
            $doc=$this->one('ai_documents',$id,true);
            abort_unless($doc->status==='active',409,'Document déjà révoqué.');
            $this->rows('ai_documents')->where('id',$id)->update(['status'=>'revoked','revoked_at'=>now(),'updated_at'=>now()]);
            $this->event('document.revoked',['id'=>$id]);
            return response()->json(['id'=>$id,'status'=>'revoked']);
        },3);
    }
    /** No embeddings/LLM: deterministic full-text-ish retrieval from explicitly registered documents. */
    private function findSources(string $query): array
    {
        $tokens=array_values(array_unique(array_filter(
            preg_split('/\s+/u',mb_strtolower(trim($query))) ?: [],
            fn($s)=>mb_strlen($s)>=3
        )));
        if (!$tokens) return [];
        $documents=$this->rows('ai_documents')
            ->where('status','active')->whereIn('classification',['public','internal'])
            ->select('id','title','source_type','source_ref','excerpt','created_at')
            ->orderByDesc('id')->limit(300)->get();
        $matches=[];
        foreach ($documents as $doc) {
            $haystack=mb_strtolower($doc->title.' '.$doc->excerpt);
            $score=0;
            foreach ($tokens as $token) if(mb_strpos($haystack,$token)!==false) $score++;
            if (!$score) continue;
            $matches[]=['id'=>$doc->id,'title'=>$doc->title,'source_type'=>$doc->source_type,
                'source_ref'=>$doc->source_ref,'excerpt'=>mb_substr($doc->excerpt,0,400),
                'created_at'=>$doc->created_at,'matched_terms'=>$score];
        }
        usort($matches,fn($a,$b)=>$b['matched_terms']<=>$a['matched_terms'] ?: $b['id']<=>$a['id']);
        return array_slice($matches,0,10);
    }
    public function search(Request $r) {
        $this->read();
        $d=$r->validate(['q'=>'required|string|min:3|max:300']);
        $items=$this->findSources($d['q']);
        return response()->json(['data'=>$items,'verified'=>true,'retrieval_mode'=>'lexical',
            'message'=>count($items)?'Sources enregistrées disponibles.':'Aucun résultat vérifié.']);
    }
    public function assistant(Request $r) {
        $this->read();
        $d=$r->validate([
            'question'=>'required|string|min:3|max:1000','agent_id'=>'nullable|integer|min:1',
            'tool'=>'nullable|string|in:read_verified_documents',
        ]);
        if (isset($d['agent_id'])) {
            $agent=$this->one('ai_agents',(int)$d['agent_id']);
            abort_unless($agent->status==='active',409,'Agent non actif.');
            $tools=json_decode($agent->allowed_tools,true,512,JSON_THROW_ON_ERROR);
            abort_unless(in_array('read_verified_documents',$tools,true),403,'Outil non autorisé pour cet agent.');
        }
        abort_if(isset($d['tool']) && $d['tool']!=='read_verified_documents',403);
        $sources=$this->findSources($d['question']);
        $answer=$sources?'Voici les sources enregistrées correspondant à votre demande. Aucune conclusion IA externe n’a été générée.':'Aucun résultat vérifié. Le service de génération IA n’est pas configuré.';
        $id=DB::table('ai_runs')->insertGetId([
            'company_id'=>$this->tenant(),'agent_id'=>$d['agent_id']??null,
            'created_by'=>auth()->id(),'question'=>$d['question'],'answer'=>$answer,
            'references'=>json_encode($sources,JSON_THROW_ON_ERROR),
            'status'=>$sources?'sources_found':'no_verified_sources',
            'created_at'=>now(),'updated_at'=>now()
        ]);
        return response()->json(['id'=>$id,'answer'=>$answer,'sources'=>$sources,'mode'=>'verified_sources_only'],201);
    }
    public function runs(Request $r) {
        $this->read();
        return $this->listing('ai_runs',$r,['id','question','answer','references','status','created_at']);
    }
    public function anomalies(Request $r) {
        $this->read();
        return $this->listing('ai_anomalies',$r,['id','model_id','model_version','entity_type','entity_ref','score','threshold','evidence','status','reviewed_by','created_at']);
    }
    public function createAnomaly(Request $r) {
        $this->read();
        $d=$r->validate([
            'model_id'=>'required|integer|min:1','entity_type'=>['required',Rule::in(['maintenance','stock','finance','hotel','data'])],
            'entity_ref'=>'required|string|max:120','score'=>'required|numeric|between:0,1',
            'threshold'=>'required|numeric|between:0,1','evidence'=>'required|array|max:20',
            'company_id'=>'prohibited','status'=>'prohibited','reviewed_by'=>'prohibited',
        ]);
        $model=$this->one('ai_models',(int)$d['model_id']);
        abort_unless($model->status==='active',409,'Modèle non publié.');
        $id=DB::table('ai_anomalies')->insertGetId([
            'company_id'=>$this->tenant(),'model_id'=>$model->id,'model_version'=>$model->version,
            'policy_version'=>$model->policy_version,'entity_type'=>$d['entity_type'],
            'entity_ref'=>$d['entity_ref'],'score'=>$d['score'],'threshold'=>$d['threshold'],
            'evidence'=>json_encode($d['evidence'],JSON_THROW_ON_ERROR),'status'=>'open',
            'created_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->event('anomaly.registered',['id'=>$id,'score'=>$d['score'],'threshold'=>$d['threshold']]);
        return response()->json($this->one('ai_anomalies',$id),201);
    }
    public function reviewAnomaly(Request $r,int $id) {
        $this->read();
        $d=$r->validate(['decision'=>['required',Rule::in(['confirmed','false_positive'])]]);
        return DB::transaction(function () use ($id,$d) {
            $row=$this->one('ai_anomalies',$id,true);
            abort_unless($row->status==='open',409,'Anomalie déjà revue.');
            $this->rows('ai_anomalies')->where('id',$id)->update([
                'status'=>$d['decision'],'reviewed_by'=>auth()->id(),'updated_at'=>now()
            ]);
            $this->event('anomaly.reviewed',['id'=>$id,'decision'=>$d['decision']]);
            return response()->json($this->one('ai_anomalies',$id));
        },3);
    }
    public function forecasts(Request $r) {
        $this->read();
        return $this->listing('ai_forecasts',$r,['id','model_id','model_version','cutoff_date','horizon_start','horizon_end','predicted_value','lower_bound','upper_bound','backtest_mae','training_population','status','created_at']);
    }
    public function createForecast(Request $r) {
        $this->read();
        $d=$r->validate([
            'model_id'=>'required|integer|min:1', 'cutoff_date'=>'required|date_format:Y-m-d',
            'horizon_start'=>'required|date_format:Y-m-d|after:cutoff_date',
            'horizon_end'=>'required|date_format:Y-m-d|after_or_equal:horizon_start',
            'predicted_value'=>'required|numeric','lower_bound'=>'required|numeric|lte:predicted_value',
            'upper_bound'=>'required|numeric|gte:predicted_value',
            'backtest_mae'=>'nullable|numeric|min:0','training_population'=>'required|string|max:180',
            'backtest_evidence'=>'required|array|max:30',
            'company_id'=>'prohibited','status'=>'prohibited',
        ]);
        $model=$this->one('ai_models',(int)$d['model_id']);
        abort_unless($model->status==='active',409,'Modèle non publié.');
        $d['backtest_evidence']=json_encode($d['backtest_evidence'],JSON_THROW_ON_ERROR);
        $id=DB::table('ai_forecasts')->insertGetId(array_merge($d,[
            'company_id'=>$this->tenant(),'model_version'=>$model->version,
            'created_by'=>auth()->id(),'status'=>'active','created_at'=>now(),'updated_at'=>now(),
        ]));
        $this->event('forecast.registered',['id'=>$id,'model_version'=>$model->version]);
        return response()->json($this->one('ai_forecasts',$id),201);
    }
    public function staleForecast(int $id) {
        $this->read();
        return DB::transaction(function () use ($id) {
            $f=$this->one('ai_forecasts',$id,true);
            abort_unless($f->status==='active',409,'Prévision déjà périmée.');
            $this->rows('ai_forecasts')->where('id',$id)->update(['status'=>'stale','updated_at'=>now()]);
            $this->event('forecast.stale',['id'=>$id]);
            return response()->json($this->one('ai_forecasts',$id));
        },3);
    }
    public function suggestions(Request $r) {
        $this->read();
        return $this->listing('ai_suggestions',$r,['id','action_type','arguments','cost_cents','revision','policy_version','explanation','status','expires_at','created_by','decided_by','work_order_id','created_at']);
    }
    private function digest(string $action, array $args, int $cost, string $policy, string $explanation, string $expiresAt): string
    {
        ksort($args);
        return hash('sha256',json_encode([$action,$args,$cost,$policy,$explanation,$expiresAt],JSON_THROW_ON_ERROR));
    }
    public function propose(Request $r) {
        $this->read();
        $d=$r->validate([
            'idempotency_key'=>'required|string|min:8|max:120',
            'action_type'=>'required|in:maintenance_create_work_order',
            'arguments'=>'required|array', 'arguments.maintenance_request_id'=>'required|integer|min:1',
            'arguments.summary'=>'nullable|string|max:2000',
            'arguments.due_at'=>'nullable|date',
            'cost_cents'=>'required|integer|min:0|max:1000000000',
            'policy_version'=>'required|string|max:60',
            'explanation'=>'required|string|min:10|max:4000',
            'expires_at'=>'required|date|after:now',
            'company_id'=>'prohibited','status'=>'prohibited','created_by'=>'prohibited',
        ]);
        $args=array_intersect_key($d['arguments'],array_flip(['maintenance_request_id','summary','due_at']));
        abort_unless(count($args)===count($d['arguments']),422,'Arguments non autorisés.');
        $di=MaintenanceRequest::query()->findOrFail((int)$args['maintenance_request_id']);
        abort_unless($di->status==='qualified',409,'DI non qualifiée.');
        $expiresAt=\Carbon\CarbonImmutable::parse($d['expires_at'])->startOfSecond()->toISOString();
        $hash=$this->digest($d['action_type'],$args,(int)$d['cost_cents'],$d['policy_version'],$d['explanation'],$expiresAt);
        return DB::transaction(function () use ($d,$args,$hash,$expiresAt) {
            $prior=$this->rows('ai_suggestions')->where('idempotency_key',$d['idempotency_key'])->lockForUpdate()->first();
            if ($prior) {
                abort_unless(hash_equals($prior->payload_sha256,$hash),409,'Clé utilisée avec des arguments différents.');
                return response()->json($prior);
            }
            $id=DB::table('ai_suggestions')->insertGetId([
                'company_id'=>$this->tenant(),'idempotency_key'=>$d['idempotency_key'],
                'payload_sha256'=>$hash,'action_type'=>$d['action_type'],
                'arguments'=>json_encode($args,JSON_THROW_ON_ERROR),'cost_cents'=>$d['cost_cents'],
                'policy_version'=>$d['policy_version'],'explanation'=>$d['explanation'],
                'expires_at'=>$expiresAt,'status'=>'proposed','created_by'=>auth()->id(),
                'created_at'=>now(),'updated_at'=>now()
            ]);
            $this->event('suggestion.proposed',['id'=>$id,'payload_sha256'=>$hash,'cost_cents'=>$d['cost_cents']],$id);
            return response()->json($this->one('ai_suggestions',$id),201);
        },3);
    }
    public function decide(Request $r,int $id) {
        $this->read();
        $d=$r->validate(['decision'=>'required|in:approved,rejected']);
        return DB::transaction(function () use ($id,$d) {
            $s=$this->one('ai_suggestions',$id,true);
            abort_unless($s->status==='proposed',409,'Proposition déjà traitée.');
            abort_unless(auth()->id()!==(int)$s->created_by,403,'Double validation requise : autre utilisateur.');
            abort_unless(now()->lessThan($s->expires_at),409,'Approbation expirée.');
            $this->rows('ai_suggestions')->where('id',$id)->update([
                'status'=>$d['decision'],'decided_by'=>auth()->id(),'decided_at'=>now(),'updated_at'=>now()
            ]);
            $this->event('suggestion.'.$d['decision'],['id'=>$id,'payload_sha256'=>$s->payload_sha256],$id);
            return response()->json($this->one('ai_suggestions',$id));
        },3);
    }
    /** Explicit human-triggered, initially kill-switched tool execution. */
    public function execute(int $id) {
        $this->read();
        abort_unless(config('atlas.ai_execution_enabled',false)===true,503,'Exécution IA désactivée par sécurité.');
        return DB::transaction(function () use ($id) {
            $s=$this->one('ai_suggestions',$id,true);
            if ($s->status==='executed') return response()->json($s);
            abort_unless($s->status==='approved',409,'Approbation requise.');
            abort_unless(now()->lessThan($s->expires_at),409,'Approbation expirée.');
            $args=json_decode($s->arguments,true,512,JSON_THROW_ON_ERROR);
            abort_unless(hash_equals($s->payload_sha256,$this->digest($s->action_type,$args,(int)$s->cost_cents,$s->policy_version,$s->explanation,\Carbon\CarbonImmutable::parse($s->expires_at)->startOfSecond()->toISOString())),409,'Arguments modifiés.');
            abort_unless($s->action_type==='maintenance_create_work_order',403,'Outil inconnu.');
            $di=MaintenanceRequest::query()->lockForUpdate()->findOrFail((int)$args['maintenance_request_id']);
            abort_unless($di->status==='qualified',409,'DI non qualifiée.');
            $existing=MaintenanceWorkOrder::query()->where('maintenance_request_id',$di->id)->first();
            $order=$existing ?: MaintenanceWorkOrder::create([
                'maintenance_request_id'=>$di->id,'asset_id'=>$di->asset_id,
                'priority'=>$di->priority,'summary'=>$args['summary']??$di->title,
                'due_at'=>$args['due_at']??null,'status'=>'open'
            ]);
            $this->rows('ai_suggestions')->where('id',$id)->update([
                'status'=>'executed','executed_at'=>now(),'work_order_id'=>$order->id,'updated_at'=>now()
            ]);
            $this->event('suggestion.executed',['id'=>$id,'work_order_id'=>$order->id],$id);
            Audit::record('ai.maintenance_work_order.created',$order,['suggestion_id'=>$id]);
            return response()->json($this->one('ai_suggestions',$id));
        },3);
    }
    public function events(Request $r) {
        $this->read();
        $d=$r->validate(['suggestion_id'=>'nullable|integer|min:1','page'=>'nullable|integer|min:1']);
        return $this->rows('ai_events')->when($d['suggestion_id']??null,fn($q,$v)=>$q->where('suggestion_id',$v))
            ->select('id','suggestion_id','actor_id','event_type','evidence','created_at')
            ->orderByDesc('id')->paginate(30);
    }
}
