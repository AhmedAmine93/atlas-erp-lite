<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Services\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Quality V5.1: all writes are company-bound, transactional, audited and
 * optionally idempotent. Proposed policy rules require business sign-off.
 */
final class QualityController extends Controller
{
    private const MODULES = [
        'controls' => ['quality_plans', 'quality_checks'],
        'nonconformities' => ['quality_nonconformities'],
        'actions' => ['quality_actions'],
        'audits' => ['quality_audits', 'quality_findings'],
        'documents' => ['quality_documents', 'quality_document_versions', 'quality_read_receipts'],
    ];
    private const READ_ROLES = ['admin', 'quality_manager', 'quality_auditor', 'quality_operator'];
    private const WRITE_ROLES = ['admin', 'quality_manager', 'quality_operator'];
    private const APPROVERS = ['admin', 'quality_manager'];

    private function actor(Request $r, array $roles = self::READ_ROLES): int
    {
        abort_unless(in_array(app(TenantRoles::class)->role($r->user())?->name, $roles, true), 403, 'Rôle qualité insuffisant.');
        return (int) $r->user()->id;
    }
    private function tenant(): int { return app(TenantContext::class)->requireId(); }
    private function items(string $table) { return DB::table($table)->where('company_id', $this->tenant()); }
    private function one(string $table, int $id, bool $locked = false): object
    {
        $q = $this->items($table)->where('id', $id);
        if ($locked) $q->lockForUpdate();
        return $q->first() ?? abort(404, 'Élément introuvable dans cette entreprise.');
    }
    private function add(string $table, array $data): int
    {
        return (int) DB::table($table)->insertGetId(array_merge($data, [
            'company_id' => $this->tenant(), 'created_at' => now(),
            ...($table === 'quality_events' ? [] : ['updated_at' => now()]),
        ]));
    }
    private function change(string $table, int $id, array $data): void
    {
        $this->items($table)->where('id', $id)->update([...$data, 'updated_at' => now()]);
    }
    private function event(string $type, int $id, string $action, int $actor, array $details = []): void
    {
        $this->add('quality_events', [
            'entity_type' => $type, 'entity_id' => $id, 'action' => $action,
            'actor_id' => $actor, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
    private function object(string $table, int $id): object { return $this->one($table, $id); }
    private function respond(string $table, int $id, int $status = 200) {
        return response()->json($this->object($table, $id), $status);
    }
    /**
     * The full response is recorded inside the same transaction as the write.
     * Duplicate keys with the same body return the original result, not another write.
     */
    private function perform(Request $r, string $operation, callable $work)
    {
        $actor = $this->actor($r, self::WRITE_ROLES);
        $key = $r->header('Idempotency-Key');
        if ($key !== null) abort_unless(preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key), 422, 'Idempotency-Key invalide.');
        return DB::transaction(function () use ($r, $operation, $work, $actor, $key) {
            $hash = hash('sha256', json_encode($r->all(), JSON_THROW_ON_ERROR));
            if ($key !== null) {
                // Serialized per company so simultaneous first attempts do not race.
                $company = $this->tenant();
                DB::table('companies')->where('id', $company)->lockForUpdate()->first();
                $previous = $this->items('quality_request_keys')
                    ->where('actor_id', $actor)->where('operation', $operation)
                    ->where('request_key', $key)->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->payload_sha256, $hash), 409, 'Clé déjà utilisée avec des données différentes.');
                    return response()->json(json_decode($previous->result, true));
                }
            }
            $out = $work($actor);
            if ($key !== null) {
                $this->add('quality_request_keys', [
                    'actor_id' => $actor, 'operation' => $operation,
                    'request_key' => $key, 'payload_sha256' => $hash,
                    'result' => json_encode($out, JSON_THROW_ON_ERROR),
                ]);
            }
            return response()->json($out, 201);
        }, 3);
    }
    private function requireVersion(object $row, int $expected): void
    {
        abort_unless((int) $row->lock_version === $expected, 409, 'La fiche a été modifiée. Rechargez-la.');
    }
    private function checkUser(int $userId): void
    {
        abort_unless(DB::table('company_user')->where('company_id', $this->tenant())
            ->where('user_id', $userId)->exists(), 422, 'Responsable non membre de cette entreprise.');
    }

    public function index(Request $r, string $module)
    {
        $this->actor($r);
        abort_unless(isset(self::MODULES[$module]), 404);
        $table = self::MODULES[$module][0];
        $params = $r->validate(['status' => 'nullable|string|max:32', 'search' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = $this->items($table);
        if (!empty($params['status'])) $q->where('status', $params['status']);
        if (!empty($params['search'])) {
            $field = match ($module) { 'controls' => 'code', 'documents' => 'title', 'actions' => 'title', 'audits' => 'reference', default => 'reference' };
            $q->where($field, 'ilike', '%' . str_replace(['%', '_'], ['\%', '\_'], $params['search']) . '%');
        }
        return response()->json($q->orderByDesc('id')->paginate($params['per_page'] ?? 20));
    }
    public function detail(Request $r, string $module, int $id)
    {
        $this->actor($r);
        abort_unless(isset(self::MODULES[$module]), 404);
        $table = self::MODULES[$module][0];
        $row = $this->one($table, $id);
        $extra = match ($module) {
            'controls' => ['checks' => $this->items('quality_checks')->where('plan_id', $id)->orderByDesc('id')->limit(100)->get()],
            'nonconformities' => ['actions' => $this->items('quality_actions')->where('nonconformity_id', $id)->get()],
            'audits' => ['findings' => $this->items('quality_findings')->where('audit_id', $id)->get()],
            'documents' => ['versions' => $this->items('quality_document_versions')->where('document_id', $id)->orderByDesc('revision')->get()],
            default => [],
        };
        $events = $this->items('quality_events')->where('entity_type', $module)
            ->where('entity_id', $id)->orderByDesc('id')->limit(100)->get();
        return response()->json([...((array) $row), ...$extra, 'events' => $events]);
    }
    public function metrics(Request $r)
    {
        $this->actor($r);
        return response()->json([
            'failed_controls' => $this->items('quality_checks')->where('result', 'fail')->count(),
            'open_nonconformities' => $this->items('quality_nonconformities')->where('status', '!=', 'closed')->count(),
            'quarantined_units' => $this->items('quality_nonconformities')->where('status', '!=', 'closed')->sum('quarantine_units'),
            'late_actions' => $this->items('quality_actions')->whereNotIn('status', ['effective','ineffective'])
                ->whereDate('due_on', '<', today())->count(),
            'open_audits' => $this->items('quality_audits')->where('status', '!=', 'closed')->count(),
            'published_documents' => $this->items('quality_document_versions')->where('status', 'published')->count(),
        ]);
    }
    public function users(Request $r)
    {
        $this->actor($r);
        return response()->json(DB::table('users as u')->join('company_user as cu', 'cu.user_id', '=', 'u.id')
            ->where('cu.company_id', $this->tenant())->select('u.id','u.name')->orderBy('u.name')->limit(200)->get());
    }

    public function createPlan(Request $r)
    {
        $this->actor($r, self::APPROVERS);
        $d = $r->validate([
            'code'=>'required|string|max:60', 'revision'=>'required|integer|min:1',
            'title'=>'required|string|max:180','metric'=>'required|string|max:100',
            'minimum'=>'required|numeric','maximum'=>'required|numeric|gte:minimum',
            'sample_size'=>'required|integer|min:1|max:1000000','valid_from'=>'required|date_format:Y-m-d',
            'valid_until'=>'nullable|date_format:Y-m-d|after_or_equal:valid_from',
        ]);
        abort_if($this->items('quality_plans')->where('code',$d['code'])->where('revision',$d['revision'])->exists(),409,'Cette révision existe.');
        return $this->perform($r, 'create-plan', function ($actor) use ($d) {
            $id=$this->add('quality_plans', [...$d, 'approved_by'=>$actor,'created_by'=>$actor]);
            $this->event('controls',$id,'plan.approved',$actor);
            return (array)$this->object('quality_plans',$id);
        });
    }
    public function executeCheck(Request $r, int $planId)
    {
        $d=$r->validate([
            'source_type'=>['required',Rule::in(['lot','receipt','production','import'])],
            'source_ref'=>'required|string|max:100', 'product_id'=>'nullable|integer|min:1',
            'measured'=>'required|numeric','inspected_units'=>'required|integer|min:1',
            'rejected_units'=>'required|integer|min:0|lte:inspected_units',
            'evidence_ref'=>'required|string|max:255',
            'corrects_check_id'=>'nullable|integer|min:1',
        ]);
        return $this->perform($r,'check-'.$planId,function($actor)use($planId,$d){
            $plan=$this->one('quality_plans',$planId,true);
            $today=today()->toDateString();
            abort_unless($plan->valid_from <= $today && (!$plan->valid_until || $plan->valid_until >= $today),409,'Plan non applicable à cette date.');
            abort_unless((int)$d['inspected_units'] >= $plan->sample_size,422,'Échantillon inférieur au plan approuvé.');
            if (!empty($d['product_id'])) $this->one('products',(int)$d['product_id']);
            if (!empty($d['corrects_check_id'])) $this->one('quality_checks',(int)$d['corrects_check_id']);
            $fail=(float)$d['measured'] < (float)$plan->minimum
                || (float)$d['measured'] > (float)$plan->maximum
                || (int)$d['rejected_units'] > 0;
            $id=$this->add('quality_checks',[...$d,'plan_id'=>$planId,'result'=>$fail?'fail':'pass','inspected_by'=>$actor]);
            $this->event('controls',$planId,'check.recorded',$actor,['check_id'=>$id,'result'=>$fail?'fail':'pass']);
            $nonconformity=null;
            if($fail) {
                $nc=$this->add('quality_nonconformities',[
                    'check_id'=>$id,'product_id'=>$d['product_id']??null,
                    'reference'=>'NC-'.$this->tenant().'-'.$id,
                    'title'=>'Écart sur '.$d['source_ref'],
                    'severity'=>'major','scope'=>$d['source_type'].': '.$d['source_ref'],
                    'quarantine_units'=>max(1,(int)$d['rejected_units']),
                    'owner_id'=>$actor,'reported_by'=>$actor,'status'=>'open',
                ]);
                $action=$this->add('quality_actions',[
                    'nonconformity_id'=>$nc,'kind'=>'corrective',
                    'title'=>'Analyser et traiter NC-'.$this->tenant().'-'.$id,
                    'owner_id'=>$actor,'status'=>'needs_plan',
                ]);
                $this->event('nonconformities',$nc,'created.from.check',$actor,['check_id'=>$id,'action_id'=>$action]);
                $this->event('actions',$action,'created.from.check',$actor,['nonconformity_id'=>$nc]);
                $nonconformity=['id'=>$nc,'action_id'=>$action];
            }
            return ['check'=>$this->object('quality_checks',$id),'nonconformity'=>$nonconformity];
        });
    }
    public function createNonconformity(Request $r)
    {
        $d=$r->validate([
            'reference'=>'required|string|max:60','title'=>'required|string|max:180',
            'severity'=>['required',Rule::in(['minor','major','critical'])],
            'scope'=>'required|string|max:3000','defect_code'=>'nullable|string|max:80',
            'owner_id'=>'required|integer|min:1',
            'product_id'=>'nullable|integer|min:1',
            'quarantine_units'=>'required|integer|min:0',
        ]);
        return $this->perform($r,'create-nc',function($actor)use($d){
            $this->checkUser((int)$d['owner_id']);
            if(!empty($d['product_id']))$this->one('products',(int)$d['product_id']);
            abort_if($this->items('quality_nonconformities')->where('reference',$d['reference'])->exists(),409,'Référence déjà utilisée.');
            $id=$this->add('quality_nonconformities',[...$d,'status'=>'open','reported_by'=>$actor]);
            $this->event('nonconformities',$id,'created',$actor);
            return (array)$this->object('quality_nonconformities',$id);
        });
    }
    public function transitionNonconformity(Request $r,int $id)
    {
        $d=$r->validate([
            'version'=>'required|integer|min:1','status'=>['required',Rule::in(['contained','closed'])],
            'decision'=>['required_if:status,closed',Rule::in(['corrected','rejected','concession'])],
            'closure_reason'=>'required_if:status,closed|string|max:4000',
            'closure_evidence'=>'required_if:status,closed|string|max:255',
        ]);
        return $this->perform($r,'nc-'.$id.'-'.$d['status'],function($actor)use($id,$d,$r){
            $nc=$this->one('quality_nonconformities',$id,true);
            $this->requireVersion($nc,(int)$d['version']);
            $role=app(TenantRoles::class)->role($r->user())?->name;
            if($d['status']==='contained'){
                abort_unless($nc->status==='open',409,'Confinement non applicable.');
                $this->change('quality_nonconformities',$id,['status'=>'contained','lock_version'=>$nc->lock_version+1]);
            }else{
                abort_unless($nc->status==='contained',409,'Le confinement est obligatoire.');
                abort_unless(in_array($role,self::APPROVERS,true),403,'Approbateur qualité requis.');
                abort_if($d['decision']==='concession' && (int)$nc->reported_by===$actor,403,'Concession : validation indépendante requise.');
                abort_if($this->items('quality_actions')->where('nonconformity_id',$id)->where('kind','corrective')->where('status','!=','effective')->exists(),409,'CAPA non vérifiée.');
                $this->change('quality_nonconformities',$id,[
                    'status'=>'closed','decision'=>$d['decision'],'closure_reason'=>$d['closure_reason'],
                    'closure_evidence'=>$d['closure_evidence'],'closed_by'=>$actor,'closed_at'=>now(),
                    'lock_version'=>$nc->lock_version+1,
                ]);
            }
            $this->event('nonconformities',$id,'status.'.$d['status'],$actor,['decision'=>$d['decision']??null]);
            return (array)$this->object('quality_nonconformities',$id);
        });
    }
    public function createAction(Request $r,int $ncId)
    {
        $d=$r->validate([
            'kind'=>['required',Rule::in(['corrective','preventive'])],
            'title'=>'required|string|max:180','root_cause'=>'required|string|max:3000',
            'owner_id'=>'required|integer|min:1','due_on'=>'required|date_format:Y-m-d',
            'target_metric'=>'required|string|max:120','target_value'=>'required|numeric',
        ]);
        return $this->perform($r,'create-action-'.$ncId,function($actor)use($ncId,$d){
            $nc=$this->one('quality_nonconformities',$ncId);
            abort_if($nc->status==='closed',409,'Dossier clôturé.');
            $this->checkUser((int)$d['owner_id']);
            $id=$this->add('quality_actions',[...$d,'nonconformity_id'=>$ncId,'status'=>'planned']);
            $this->event('actions',$id,'created',$actor);
            return (array)$this->object('quality_actions',$id);
        });
    }
    public function updateAction(Request $r,int $id)
    {
        $d=$r->validate([
            'version'=>'required|integer|min:1',
            'status'=>['required',Rule::in(['planned','implemented','effective','ineffective'])],
            'root_cause'=>'nullable|string|max:3000','due_on'=>'nullable|date_format:Y-m-d',
            'target_metric'=>'nullable|string|max:120','target_value'=>'nullable|numeric',
            'measured_value'=>'nullable|numeric','evidence_ref'=>'nullable|string|max:255',
        ]);
        return $this->perform($r,'action-'.$id.'-'.$d['status'],function($actor)use($id,$d,$r){
            $a=$this->one('quality_actions',$id,true);
            $this->requireVersion($a,(int)$d['version']);
            $allowed=[
                'needs_plan'=>['planned'],'planned'=>['implemented'],
                'implemented'=>['effective','ineffective'],'ineffective'=>['planned'],
            ];
            abort_unless(in_array($d['status'],$allowed[$a->status]??[],true),409,'Transition CAPA interdite.');
            $update=['status'=>$d['status'],'lock_version'=>$a->lock_version+1];
            if($d['status']==='planned'){
                foreach(['root_cause','due_on','target_metric','target_value'] as $f) {
                    $value=$d[$f]??$a->$f;
                    abort_if($value===null||$value==='',422,"Plan incomplet : {$f}.");
                    $update[$f]=$value;
                }
            }
            if($d['status']==='implemented'){
                abort_if(empty($d['evidence_ref']),422,'Preuve d’implémentation obligatoire.');
                $update['evidence_ref']=$d['evidence_ref'];
            }
            if(in_array($d['status'],['effective','ineffective'],true)){
                abort_unless(in_array(app(TenantRoles::class)->role($r->user())?->name,self::APPROVERS,true),403);
                abort_if((int)$a->owner_id===$actor,403,'Vérification indépendante obligatoire.');
                abort_if(!isset($d['measured_value']) || !$a->evidence_ref || !$a->target_metric,422,'Mesure et preuve CAPA requises.');
                $update['measured_value']=$d['measured_value'];
                $update['verified_by']=$actor;
                // Target_value is a minimum threshold; the evaluation is server-side.
                $effective=(float)$d['measured_value'] >= (float)$a->target_value;
                abort_unless(($d['status']==='effective')===$effective,422,'Décision incompatible avec le critère de mesure.');
            }
            $this->change('quality_actions',$id,$update);
            $this->event('actions',$id,'status.'.$d['status'],$actor);
            return (array)$this->object('quality_actions',$id);
        });
    }
    public function escalateOverdue(Request $r)
    {
        $actor=$this->actor($r,self::APPROVERS);
        return DB::transaction(function()use($actor){
            $items=$this->items('quality_actions')->whereNotIn('status',['effective','ineffective'])
                ->whereDate('due_on','<',today())->whereNull('escalated_at')->lockForUpdate()->get();
            foreach($items as $a){
                $this->change('quality_actions',$a->id,['escalated_at'=>now()]);
                $this->event('actions',$a->id,'overdue.escalated',$actor);
            }
            return response()->json(['escalated'=>$items->count()]);
        });
    }
    public function createAudit(Request $r)
    {
        $this->actor($r,self::APPROVERS);
        $d=$r->validate([
            'reference'=>'required|string|max:60','scope'=>'required|string|max:255',
            'standard'=>'required|string|max:120','standard_version'=>'required|string|max:60',
            'auditor_id'=>'required|integer|min:1','starts_on'=>'required|date_format:Y-m-d',
            'ends_on'=>'required|date_format:Y-m-d|after_or_equal:starts_on',
        ]);
        return $this->perform($r,'create-audit',function($actor)use($d){
            $this->checkUser((int)$d['auditor_id']);
            abort_if($this->items('quality_audits')->where('reference',$d['reference'])->exists(),409);
            $id=$this->add('quality_audits',[...$d,'created_by'=>$actor,'status'=>'planned']);
            $this->event('audits',$id,'created',$actor);
            return (array)$this->object('quality_audits',$id);
        });
    }
    public function addFinding(Request $r,int $auditId)
    {
        $d=$r->validate([
            'title'=>'required|string|max:180',
            'severity'=>['required',Rule::in(['minor','major','critical'])],
            'description'=>'required|string|max:5000','evidence_ref'=>'required|string|max:255',
        ]);
        return $this->perform($r,'finding-'.$auditId,function($actor)use($auditId,$d){
            $a=$this->one('quality_audits',$auditId,true);
            abort_if($a->status==='closed',409,'Audit clos : constats figés.');
            $id=$this->add('quality_findings',[...$d,'audit_id'=>$auditId,'recorded_by'=>$actor]);
            $this->event('audits',$auditId,'finding.recorded',$actor,['finding_id'=>$id]);
            return (array)$this->object('quality_findings',$id);
        });
    }
    public function transitionAudit(Request $r,int $id)
    {
        $d=$r->validate([
            'version'=>'required|integer|min:1',
            'status'=>['required',Rule::in(['in_progress','closed'])],
            'closure_evidence'=>'required_if:status,closed|string|max:255',
        ]);
        return $this->perform($r,'audit-'.$id.'-'.$d['status'],function($actor)use($id,$d,$r){
            $a=$this->one('quality_audits',$id,true);
            $this->requireVersion($a,(int)$d['version']);
            abort_unless(($a->status==='planned'&&$d['status']==='in_progress')||($a->status==='in_progress'&&$d['status']==='closed'),409);
            if($d['status']==='closed'){
                abort_unless(in_array(app(TenantRoles::class)->role($r->user())?->name,self::APPROVERS,true),403);
                $major=$this->items('quality_findings')->where('audit_id',$id)
                    ->whereIn('severity',['major','critical'])->exists();
                abort_if($major && $actor===(int)$a->auditor_id,403,'Audit majeur : réviseur indépendant requis.');
                $update=['closure_evidence'=>$d['closure_evidence'],'closed_by'=>$actor,'closed_at'=>now()];
            } else $update=[];
            $this->change('quality_audits',$id,[...$update,'status'=>$d['status'],'lock_version'=>$a->lock_version+1]);
            $this->event('audits',$id,'status.'.$d['status'],$actor);
            return (array)$this->object('quality_audits',$id);
        });
    }
    public function createDocument(Request $r)
    {
        $d=$r->validate([
            'code'=>'required|string|max:60','title'=>'required|string|max:180',
            'country_code'=>'required|regex:/^[A-Z]{2}$/',
            'content'=>'required|string|min:10|max:100000',
        ]);
        return $this->perform($r,'create-document',function($actor)use($d){
            abort_if($this->items('quality_documents')->where('code',$d['code'])->exists(),409);
            $id=$this->add('quality_documents',[
                'code'=>$d['code'],'title'=>$d['title'],'country_code'=>$d['country_code'],'created_by'=>$actor,
            ]);
            $revision=$this->add('quality_document_versions',[
                'document_id'=>$id,'revision'=>1,'content'=>$d['content'],
                'sha256'=>hash('sha256',$d['content']),'status'=>'draft','authored_by'=>$actor,
            ]);
            $this->event('documents',$id,'draft.created',$actor,['version_id'=>$revision]);
            return ['document'=>$this->object('quality_documents',$id),'version'=>$this->object('quality_document_versions',$revision)];
        });
    }
    public function reviseDocument(Request $r,int $documentId)
    {
        $d=$r->validate(['content'=>'required|string|min:10|max:100000']);
        return $this->perform($r,'revise-document-'.$documentId,function($actor)use($documentId,$d){
            $this->one('quality_documents',$documentId,true);
            $previous=$this->items('quality_document_versions')->where('document_id',$documentId)->orderByDesc('revision')->lockForUpdate()->first();
            abort_if(in_array($previous->status,['draft','review'],true),409,'Révision précédente en cours.');
            $id=$this->add('quality_document_versions',[
                'document_id'=>$documentId,'revision'=>$previous->revision+1,
                'content'=>$d['content'],'sha256'=>hash('sha256',$d['content']),
                'status'=>'draft','authored_by'=>$actor,
            ]);
            $this->event('documents',$documentId,'revision.created',$actor,['version_id'=>$id]);
            return (array)$this->object('quality_document_versions',$id);
        });
    }
    public function transitionDocument(Request $r,int $versionId)
    {
        $d=$r->validate([
            'status'=>['required',Rule::in(['review','approved','published','obsolete'])],
            'effective_at'=>'required_if:status,published|nullable|date',
        ]);
        return $this->perform($r,'document-version-'.$versionId.'-'.$d['status'],function($actor)use($versionId,$d,$r){
            $v=$this->one('quality_document_versions',$versionId,true);
            $allowed=['draft'=>'review','review'=>'approved','approved'=>'published','published'=>'obsolete'];
            abort_unless(($allowed[$v->status]??null)===$d['status'],409,'Transition documentaire interdite.');
            if(in_array($d['status'],['approved','published','obsolete'],true))
                abort_unless(in_array(app(TenantRoles::class)->role($r->user())?->name,self::APPROVERS,true),403);
            if($d['status']==='approved') abort_if((int)$v->authored_by===$actor,403,'Approbation indépendante requise.');
            $changes=['status'=>$d['status']];
            if($d['status']==='approved')$changes+=['approved_by'=>$actor,'approved_at'=>now()];
            if($d['status']==='published'){
                abort_unless($v->approved_by && $v->sha256===hash('sha256',$v->content),409,'Empreinte documentaire non valide.');
                $changes+=['effective_at'=>$d['effective_at'],'published_at'=>now()];
                $this->items('quality_document_versions')->where('document_id',$v->document_id)
                    ->where('status','published')->where('id','!=',$versionId)
                    ->update(['status'=>'obsolete','updated_at'=>now()]);
            }
            $this->change('quality_document_versions',$versionId,$changes);
            $this->event('documents',$v->document_id,'version.'.$d['status'],$actor,[
                'version_id'=>$versionId,'sha256'=>$v->sha256,
            ]);
            return (array)$this->object('quality_document_versions',$versionId);
        });
    }
    public function acknowledgeDocument(Request $r,int $versionId)
    {
        return $this->perform($r,'acknowledge-'.$versionId,function($actor)use($versionId){
            $v=$this->one('quality_document_versions',$versionId);
            abort_unless($v->status==='published' && $v->effective_at <= now(),409,'Procédure non effective.');
            $previous=$this->items('quality_read_receipts')->where('version_id',$versionId)->where('user_id',$actor)->first();
            if($previous)return (array)$previous;
            $id=$this->add('quality_read_receipts',[
                'version_id'=>$versionId,'user_id'=>$actor,'sha256'=>$v->sha256,'acknowledged_at'=>now(),
            ]);
            $this->event('documents',$v->document_id,'version.acknowledged',$actor,['version_id'=>$versionId]);
            return (array)$this->object('quality_read_receipts',$id);
        });
    }
    public function checks(Request $r,int $planId)
    {
        $this->actor($r);
        $this->one('quality_plans',$planId);
        return response()->json($this->items('quality_checks')->where('plan_id',$planId)->latest('id')->paginate(30));
    }
    public function findings(Request $r,int $auditId)
    {
        $this->actor($r);
        $this->one('quality_audits',$auditId);
        return response()->json($this->items('quality_findings')->where('audit_id',$auditId)->latest('id')->paginate(30));
    }
    public function versions(Request $r,int $docId)
    {
        $this->actor($r);
        $this->one('quality_documents',$docId);
        return response()->json($this->items('quality_document_versions')->where('document_id',$docId)->orderByDesc('revision')->paginate(30));
    }
    public function receiptList(Request $r,int $versionId)
    {
        $this->actor($r,self::APPROVERS);
        $this->one('quality_document_versions',$versionId);
        return response()->json($this->items('quality_read_receipts')->where('version_id',$versionId)->paginate(30));
    }
}
