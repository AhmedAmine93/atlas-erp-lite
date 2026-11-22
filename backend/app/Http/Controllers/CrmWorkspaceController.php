<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Audit;
use App\Services\TenantContext;
use App\Services\TenantRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * M02: all queries bind the active company explicitly, in addition to
 * PostgreSQL RLS. No SQL identifier comes from user input.
 */
final class CrmWorkspaceController extends Controller
{
    private const TABLES = [
        'prospects' => 'crm_prospects',
        'contacts' => 'crm_contacts',
        'opportunities' => 'crm_opportunities',
        'activities' => 'crm_activities',
        'segments' => 'crm_segments',
    ];
    private const FIELDS = [
        'prospects' => ['name','email','phone','source','owner_id','opt_out'],
        'contacts' => ['customer_id','first_name','last_name','email','phone','job_title','consent','active','valid_from','valid_to'],
        'opportunities' => ['customer_id','prospect_id','owner_id','title','amount_cents','currency','probability_bps','expected_close_at'],
        'activities' => ['customer_id','prospect_id','contact_id','opportunity_id','owner_id','title','type','priority','due_at','timezone'],
        'segments' => ['code','name','mode','criteria'],
    ];

    private function company(): int
    {
        return app(TenantContext::class)->requireId();
    }

    private function table(string $resource): string
    {
        abort_unless(isset(self::TABLES[$resource]), 404);
        return self::TABLES[$resource];
    }

    private function scope(string $resource)
    {
        return DB::table($this->table($resource))->where('company_id', $this->company());
    }

    private function row(string $resource, int $id, bool $lock = false): object
    {
        $query = $this->scope($resource);
        return ($lock ? $query->lockForUpdate() : $query)->where('id', $id)->first()
            ?? abort(404, 'Fiche introuvable dans cette entreprise.');
    }

    private function owned(string $table, ?int $id): void
    {
        if ($id === null) return;
        abort_unless(DB::table($table)->where('company_id', $this->company())->where('id', $id)->exists(), 422,
            'Référence non autorisée pour cette entreprise.');
    }

    private function owner(int $userId): void
    {
        abort_unless(DB::table('company_user')->where('company_id', $this->company())->where('user_id', $userId)->exists(),
            422, 'Responsable sans affiliation à cette entreprise.');
    }

    private function rules(string $resource, ?int $id = null): array
    {
        $company = $this->company();
        $unique = fn (string $table, string $field) =>
            Rule::unique($table, $field)->where('company_id', $company)->ignore($id);
        return match ($resource) {
            'prospects' => [
                'name' => 'sometimes|required|string|max:255',
                'email' => ['sometimes','nullable','email','max:255',$unique('crm_prospects','email')],
                'phone' => 'nullable|string|max:40', 'source' => 'nullable|string|max:100',
                'owner_id' => 'nullable|integer|min:1', 'opt_out' => 'sometimes|boolean',
            ],
            'contacts' => [
                'customer_id' => 'nullable|integer|min:1', 'first_name' => 'sometimes|required|string|max:120',
                'last_name' => 'sometimes|required|string|max:120', 'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:40', 'job_title' => 'nullable|string|max:150',
                'consent' => ['sometimes',Rule::in(['unknown','granted','refused'])],
                'active' => 'sometimes|boolean', 'valid_from' => 'nullable|date',
                'valid_to' => 'nullable|date|after_or_equal:valid_from',
            ],
            'opportunities' => [
                'customer_id' => 'nullable|integer|min:1', 'prospect_id' => 'nullable|integer|min:1',
                'owner_id' => 'sometimes|required|integer|min:1', 'title' => 'sometimes|required|string|max:255',
                'amount_cents' => 'sometimes|integer|min:0|max:100000000000',
                'currency' => ['sometimes','string','size:3',Rule::in(['EUR','USD','GBP'])],
                'probability_bps' => 'sometimes|integer|between:0,10000',
                'expected_close_at' => 'nullable|date',
            ],
            'activities' => [
                'customer_id' => 'nullable|integer|min:1', 'prospect_id' => 'nullable|integer|min:1',
                'contact_id' => 'nullable|integer|min:1', 'opportunity_id' => 'nullable|integer|min:1',
                'owner_id' => 'sometimes|required|integer|min:1', 'title' => 'sometimes|required|string|max:255',
                'type' => ['sometimes',Rule::in(['call','meeting','email','task'])],
                'priority' => ['sometimes',Rule::in(['low','normal','high'])],
                'due_at' => 'sometimes|required|date',
                'timezone' => 'sometimes|timezone',
            ],
            'segments' => [
                'code' => ['sometimes','required','string','max:60',$unique('crm_segments','code')],
                'name' => 'sometimes|required|string|max:180',
                'mode' => ['sometimes',Rule::in(['static','dynamic'])],
                'criteria' => 'nullable|array', 'criteria.field' => ['required_if:mode,dynamic',Rule::in(['name','email_domain'])],
                'criteria.value' => 'required_if:mode,dynamic|string|max:100',
            ],
        };
    }

    private function checkReferences(string $resource, array $data): void
    {
        foreach (['customer_id'=>'customers','prospect_id'=>'crm_prospects',
                  'contact_id'=>'crm_contacts','opportunity_id'=>'crm_opportunities'] as $column => $table) {
            if (array_key_exists($column, $data)) $this->owned($table, $data[$column]);
        }
        if (isset($data['owner_id'])) $this->owner((int)$data['owner_id']);
        if ($resource === 'segments' && ($data['mode'] ?? '') === 'dynamic') {
            abort_unless(isset($data['criteria']['field'], $data['criteria']['value']), 422,
                'Critères de segmentation nécessaires.');
        }
    }

    private function audit(string $event, string $resource, int $id, array $extra = []): void
    {
        Audit::record('crm.'.$resource.'.'.$event, null, array_merge(['record_id'=>$id], $extra));
    }

    public function index(Request $request, string $resource)
    {
        Gate::authorize('crmRead');
        $this->table($resource);
        $filters = $request->validate([
            'search'=>'nullable|string|max:100', 'status'=>'nullable|string|max:30',
            'page'=>'sometimes|integer|min:1', 'per_page'=>'sometimes|integer|between:1,100',
        ]);
        $query = $this->scope($resource);
        if (!empty($filters['search'])) {
            $field = $resource === 'contacts' ? 'first_name' : ($resource === 'segments' ? 'name' : ($resource === 'activities' || $resource === 'opportunities' ? 'title' : 'name'));
            $needle = mb_strtolower($filters['search']);
            $query->whereRaw("LOWER(\"{$field}\") LIKE ?", ['%'.str_replace(['%','_'],['\%','\_'],$needle).'%']);
        }
        if (!empty($filters['status']) && in_array($resource, ['prospects','opportunities','activities'], true)) {
            $column = $resource === 'opportunities' ? 'stage' : 'status';
            $query->where($column, $filters['status']);
        }
        if ($resource === 'contacts' && app(TenantRoles::class)->role($request->user())?->name === 'reader') {
            $query->select('id','first_name','last_name','job_title','active','revision');
        }
        return $query->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
    }

    public function show(Request $request, string $resource, int $id)
    {
        Gate::authorize('crmRead');
        $row=$this->row($resource,$id);
        if ($resource === 'contacts' && app(TenantRoles::class)->role($request->user())?->name === 'reader') {
            return response()->json([
                'id'=>$row->id,'first_name'=>$row->first_name,'last_name'=>$row->last_name,
                'job_title'=>$row->job_title,'active'=>$row->active,'revision'=>$row->revision,
            ]);
        }
        return response()->json($row);
    }

    public function store(Request $request, string $resource)
    {
        Gate::authorize('crm');
        $this->table($resource);
        $data = $request->validate(array_merge($this->rules($resource), [
            'company_id'=>'prohibited','status'=>'prohibited','stage'=>'prohibited',
            'revision'=>'prohibited','converted_customer_id'=>'prohibited',
        ]));
        foreach (['name', 'title', 'first_name', 'last_name', 'code', 'due_at'] as $required) {
            if (in_array($required, self::FIELDS[$resource], true) &&
                (($resource === 'contacts' && in_array($required,['first_name','last_name'],true)) ||
                ($resource === 'activities' && in_array($required,['title','due_at'],true)) ||
                ($resource === 'prospects' && $required === 'name') ||
                ($resource === 'opportunities' && $required === 'title') ||
                ($resource === 'segments' && in_array($required,['name','code'],true)))) {
                if (!array_key_exists($required, $data)) throw ValidationException::withMessages([$required=>'Champ obligatoire.']);
            }
        }
        if (in_array($resource,['opportunities','activities'],true) && empty($data['owner_id'])) {
            $data['owner_id'] = (int)$request->user()->id;
        }
        $this->checkReferences($resource, $data);
        if ($resource === 'segments') $data['criteria'] = json_encode($data['criteria'] ?? [], JSON_THROW_ON_ERROR);
        return DB::transaction(function () use ($resource,$data) {
            $now = now();
            $id = DB::table($this->table($resource))->insertGetId(array_merge($data,[
                'company_id'=>$this->company(),'created_at'=>$now,'updated_at'=>$now,
            ]));
            $this->audit('created',$resource,$id);
            return response()->json($this->row($resource,$id),201);
        });
    }

    public function update(Request $request, string $resource, int $id)
    {
        Gate::authorize('crm');
        $this->table($resource);
        $data = $request->validate(array_merge($this->rules($resource, $id), [
            'company_id'=>'prohibited','status'=>'prohibited','stage'=>'prohibited',
            'revision'=>'prohibited','converted_customer_id'=>'prohibited',
        ]));
        abort_if($data === [],422,'Aucune modification.');
        $this->checkReferences($resource,$data);
        return DB::transaction(function () use ($request,$resource,$id,$data) {
            $row = $this->row($resource,$id,true);
            if ($resource === 'prospects') abort_if(in_array($row->status,['converted','lost'],true),409,'Prospect clôturé.');
            if ($resource === 'opportunities') abort_if(in_array($row->stage,['won','lost'],true),409,'Opportunité clôturée.');
            if ($resource === 'activities') abort_if($row->status !== 'planned',409,'Activité clôturée.');
            if ($resource === 'segments') abort_if($row->archived_at !== null,409,'Segment archivé.');
            $revision = (int)$request->input('expected_revision',0);
            abort_unless($revision === (int)$row->revision,409,'Fiche modifiée par un autre utilisateur.');
            if ($resource === 'segments' && isset($data['criteria'])) {
                $data['criteria'] = json_encode($data['criteria'],JSON_THROW_ON_ERROR);
            }
            DB::table($this->table($resource))->where('id',$id)->where('company_id',$this->company())
                ->update(array_merge($data,['revision'=>$row->revision+1,'updated_at'=>now()]));
            $this->audit('updated',$resource,$id);
            return response()->json($this->row($resource,$id));
        });
    }

    public function transition(Request $request, string $resource, int $id)
    {
        Gate::authorize('crm');
        abort_unless(in_array($resource,['prospects','opportunities','activities'],true),404);
        $data = $request->validate([
            'target'=>'required|string|max:24','reason'=>'nullable|string|max:1000',
            'expected_revision'=>'required|integer|min:1',
        ]);
        $transitions = [
            'prospects'=>['new'=>['qualified','lost'],'qualified'=>['lost'],'lost'=>['qualified']],
            'opportunities'=>['new'=>['qualified','lost'],'qualified'=>['proposal','lost'],'proposal'=>['won','lost']],
            'activities'=>['planned'=>['completed','cancelled']],
        ];
        return DB::transaction(function () use ($request,$resource,$id,$data,$transitions) {
            $row = $this->row($resource,$id,true);
            $field = $resource === 'opportunities' ? 'stage' : 'status';
            abort_unless((int)$data['expected_revision'] === (int)$row->revision,409,'Version périmée.');
            abort_unless(in_array($data['target'],$transitions[$resource][$row->$field] ?? [],true),409,'Transition interdite.');
            if (in_array($data['target'],['lost','won','completed','cancelled'],true)
                || ($resource === 'prospects' && $row->status === 'lost')) {
                abort_unless(strlen(trim((string)($data['reason']??''))) >= 3,422,'Motif ou résultat obligatoire.');
            }
            $update = [$field=>$data['target'],'revision'=>$row->revision+1,'updated_at'=>now()];
            if ($resource === 'prospects' && $data['target'] === 'lost') $update['lost_reason']=$data['reason'];
            if ($resource === 'opportunities' && in_array($data['target'],['won','lost'],true)) $update['decision_reason']=$data['reason'];
            if ($resource === 'activities' && $data['target']==='completed') {
                $update['completed_at']=now(); $update['result']=$data['reason'];
            }
            DB::table($this->table($resource))->where('company_id',$this->company())->where('id',$id)->update($update);
            $this->audit('transitioned',$resource,$id,['from'=>$row->$field,'to'=>$data['target']]);
            return response()->json($this->row($resource,$id));
        });
    }

    /** Audit every postponement; completion never rewrites its original due date. */
    public function reschedule(Request $request, int $id)
    {
        Gate::authorize('crm');
        $data=$request->validate([
            'due_at'=>'required|date|after:now',
            'reason'=>'required|string|min:3|max:1000',
            'expected_revision'=>'required|integer|min:1',
        ]);
        return DB::transaction(function()use($id,$data){
            $row=$this->row('activities',$id,true);
            abort_unless($row->status==='planned',409,'Activité déjà clôturée.');
            abort_unless((int)$row->revision===(int)$data['expected_revision'],409,'Version périmée.');
            DB::table('crm_activities')->where('company_id',$this->company())->where('id',$id)->update([
                'due_at'=>$data['due_at'],'reschedule_reason'=>$data['reason'],
                'reschedule_count'=>$row->reschedule_count+1,
                'revision'=>$row->revision+1,'updated_at'=>now(),
            ]);
            $this->audit('rescheduled','activities',$id,[
                'previous_due_at'=>$row->due_at,'new_due_at'=>$data['due_at'],
                'reason'=>$data['reason'],
            ]);
            return response()->json($this->row('activities',$id));
        });
    }

    /** Atomic and replay-safe prospect conversion; no loss of existing clients. */
    public function convert(Request $request, int $id)
    {
        Gate::authorize('crm');
        $data=$request->validate(['expected_revision'=>'required|integer|min:1']);
        return DB::transaction(function () use ($request,$id,$data) {
            $prospect=$this->row('prospects',$id,true);
            if ($prospect->status==='converted' && $prospect->converted_customer_id) {
                return response()->json(['prospect'=>$prospect,'customer_id'=>$prospect->converted_customer_id]);
            }
            abort_unless($prospect->status==='qualified',409,'Le prospect doit être qualifié.');
            abort_unless((int)$prospect->revision===(int)$data['expected_revision'],409,'Version périmée.');
            abort_unless($prospect->email,422,'Une adresse email est nécessaire pour convertir.');
            $customer=Customer::query()->where('email',$prospect->email)->lockForUpdate()->first();
            if (!$customer) {
                $customer=Customer::create(['name'=>$prospect->name,'email'=>$prospect->email,'phone'=>$prospect->phone]);
            }
            DB::table('crm_prospects')->where('company_id',$this->company())->where('id',$id)->update([
                'status'=>'converted','converted_customer_id'=>$customer->id,'revision'=>$prospect->revision+1,'updated_at'=>now(),
            ]);
            $this->audit('converted','prospects',$id,['customer_id'=>$customer->id]);
            return response()->json(['prospect'=>$this->row('prospects',$id),'customer_id'=>$customer->id]);
        });
    }

    /** A customer with accounting or sales references must never be hard-deleted. */
    public function archiveCustomer(Request $request, int $id)
    {
        Gate::authorize('crm');
        $data=$request->validate(['reason'=>'required|string|min:3|max:500']);
        return DB::transaction(function () use ($id,$data) {
            $customer=Customer::query()->lockForUpdate()->findOrFail($id);
            if (!$customer->archived_at) {
                $customer->archived_at=now(); $customer->archive_reason=$data['reason'];
                $customer->save();
                Audit::record('crm.customer.archived',$customer);
            }
            return response()->json($customer);
        });
    }

    public function segmentMembers(Request $request, int $id)
    {
        Gate::authorize('crmRead');
        $segment=$this->row('segments',$id);
        if ($segment->mode==='static') {
            return DB::table('crm_segment_memberships as m')
                ->join('customers as c',function($join){$join->on('c.id','=','m.customer_id')->on('c.company_id','=','m.company_id');})
                ->where('m.company_id',$this->company())->where('m.segment_id',$id)->where('m.active',true)
                ->whereNull('c.archived_at')->select('c.id','c.name','c.email')->orderBy('c.name')->paginate(30);
        }
        $criteria=json_decode($segment->criteria?:'{}',true);
        $field=$criteria['field']??'';
        $value=$criteria['value']??'';
        abort_unless(in_array($field,['name','email_domain'],true) && is_string($value) && strlen($value)<=100,422,'Critères invalides.');
        $query=Customer::query()->whereNull('archived_at');
        if ($field==='name') $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($value).'%']);
        if ($field==='email_domain') $query->whereRaw('LOWER(email) LIKE ?', ['%@'.mb_strtolower(ltrim($value,'@'))]);
        return $query->select('id','name','email')->orderBy('name')->paginate(30);
    }

    public function setSegmentMember(Request $request, int $id)
    {
        Gate::authorize('crm');
        $data=$request->validate(['customer_id'=>'required|integer|min:1','active'=>'required|boolean']);
        $this->owned('customers',(int)$data['customer_id']);
        return DB::transaction(function()use($id,$data){
            $segment=$this->row('segments',$id,true);
            abort_unless($segment->mode==='static' && !$segment->archived_at,409,'Segment statique actif obligatoire.');
            DB::table('crm_segment_memberships')->updateOrInsert(
                ['company_id'=>$this->company(),'segment_id'=>$id,'customer_id'=>$data['customer_id']],
                ['active'=>$data['active'],'updated_at'=>now(),'created_at'=>now()]
            );
            $this->audit('member_changed','segments',$id,['customer_id'=>$data['customer_id'],'active'=>$data['active']]);
            return response()->json(['ok'=>true]);
        });
    }
}
