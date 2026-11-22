<?php

namespace App\Http\Controllers;

use App\Services\TenantContext;
use App\Services\TenantRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * M09: HR service boundary.
 * Never accepts company_id or actor IDs from client payloads.
 * The tenant middleware creates the PostgreSQL SET LOCAL RLS context.
 */
final class HumanResourcesController extends Controller
{
    private const RESOURCES = [
        'employees' => 'hr_employees',
        'teams' => 'hr_teams',
        'assignments' => 'hr_assignments',
        'schedules' => 'hr_schedules',
        'balances' => 'hr_leave_balances',
        'leaves' => 'hr_leave_requests',
        'timesheets' => 'hr_time_entries',
        'skills' => 'hr_skills',
        'certifications' => 'hr_employee_skills',
        'events' => 'hr_events',
        'sites' => 'establishments',
    ];

    private function company(): int
    {
        return app(TenantContext::class)->requireId();
    }

    private function role(Request $request): string
    {
        return (string) (app(TenantRoles::class)->role($request->user(), $this->company())?->name ?? '');
    }

    private function authorizeHr(Request $request, bool $write = false): void
    {
        // Default-deny until a company-scoped HR role has been explicitly granted.
        abort_unless(in_array($this->role($request), ['admin', 'hr'], true), 403,
            'Accès RH réservé aux administrateurs et responsables RH habilités.');
    }

    private function table(string $resource): string
    {
        abort_unless(isset(self::RESOURCES[$resource]), 404);
        return self::RESOURCES[$resource];
    }

    private function row(string $table, int $id, bool $lock = false): object
    {
        $q = DB::table($table)->where('company_id', $this->company())->where('id', $id);
        if ($lock) $q->lockForUpdate();
        return $q->first() ?? abort(404, 'Enregistrement absent du périmètre autorisé.');
    }

    private function requireRef(string $table, ?int $id): void
    {
        if ($id !== null && ! DB::table($table)->where('company_id', $this->company())
            ->where('id', $id)->exists()) abort(404, 'Référence inaccessible pour cette entreprise.');
    }

    private function event(string $resource, int $id, string $operation, Request $request,
        ?string $from = null, ?string $to = null, ?string $reason = null): void
    {
        DB::table('hr_events')->insert([
            'company_id' => $this->company(), 'entity_type' => $resource,
            'entity_id' => $id, 'operation' => $operation,
            'from_state' => $from, 'to_state' => $to,
            'actor_id' => $request->user()->id, 'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /** Atomic dedupe: same operation/key+payload returns the original result; changed payload rejects. */
    private function command(Request $request, string $operation, string $table, callable $handler): JsonResponse
    {
        $validated = $request->validate([
            'request_key' => 'required|string|min:8|max:100|regex:/^[A-Za-z0-9_-]+$/D',
            'company_id' => 'prohibited',
            'created_by' => 'prohibited',
            'approved_by' => 'prohibited',
            'decided_by' => 'prohibited',
            'published_by' => 'prohibited',
            'validated_by' => 'prohibited',
            'actor_id' => 'prohibited',
            'user_id' => 'prohibited',
            'manager_employee_id_override' => 'prohibited',
        ]);
        $key = $validated['request_key'];
        $data = $request->except(['request_key']);
        ksort($data);
        $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($request, $operation, $table, $handler, $key, $digest): JsonResponse {
            $company = $this->company();
            DB::table('hr_request_keys')->insertOrIgnore([
                'company_id' => $company, 'operation' => $operation,
                'request_key' => $key, 'payload_hash' => $digest,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved = DB::table('hr_request_keys')
                ->where('company_id',$company)->where('operation',$operation)
                ->where('request_key',$key)->lockForUpdate()->first();
            abort_unless(hash_equals($saved->payload_hash, $digest), 409, 'Clé déjà utilisée pour une autre opération.');
            if ($saved->result_id !== null) {
                return response()->json($this->row($table, (int)$saved->result_id));
            }
            $id = $handler();
            DB::table('hr_request_keys')->where('id',$saved->id)
                ->update(['result_id'=>$id,'updated_at'=>now()]);
            return response()->json($this->row($table,$id), 201);
        }, 3);
    }

    public function index(Request $request, string $resource): JsonResponse
    {
        $this->authorizeHr($request);
        $table = $this->table($resource);
        $filters = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'employee_id' => 'sometimes|integer|min:1',
            'establishment_id' => 'sometimes|integer|min:1',
            'status' => 'sometimes|string|max:20',
        ]);
        $query = DB::table($table)->where('company_id',$this->company());
        foreach (['employee_id','establishment_id','status'] as $column) {
            if (array_key_exists($column,$filters) && \Illuminate\Support\Facades\Schema::hasColumn($table,$column)) {
                $query->where($column,$filters[$column]);
            }
        }
        $items = $query->orderByDesc('id')->paginate((int)($filters['per_page'] ?? 20));
        // Do not expose evidence paths to ordinary HR listing; HR role can manage metadata.
        return response()->json($items);
    }

    public function show(Request $request, string $resource, int $id): JsonResponse
    {
        $this->authorizeHr($request);
        return response()->json($this->row($this->table($resource),$id));
    }

    private function rules(string $resource): array
    {
        $date = 'required|date_format:Y-m-d';
        return match($resource) {
            'employees' => [
                'code'=>'required|string|max:60', 'given_name'=>'required|string|max:100',
                'family_name'=>'required|string|max:100','email'=>'nullable|email|max:180',
                'establishment_id'=>'required|integer|min:1','hired_on'=>$date,
            ],
            'teams' => [
                'code'=>'required|string|max:60','name'=>'required|string|max:180',
                'manager_employee_id'=>'nullable|integer|min:1','effective_from'=>$date,
                'effective_to'=>'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            ],
            'assignments' => [
                'employee_id'=>'required|integer|min:1','establishment_id'=>'required|integer|min:1',
                'team_id'=>'nullable|integer|min:1','starts_on'=>$date,
                'ends_on'=>'nullable|date_format:Y-m-d|after_or_equal:starts_on',
                'allocation_percent'=>'required|integer|between:1,100',
            ],
            'schedules' => [
                'employee_id'=>'required|integer|min:1','establishment_id'=>'required|integer|min:1',
                'starts_at'=>'required|date','ends_at'=>'required|date|after:starts_at',
                'timezone'=>'required|timezone',
            ],
            'balances' => [
                'employee_id'=>'required|integer|min:1',
                'year'=>'required|integer|between:2000,2100',
                'allocated_days'=>'required|numeric|min:0|max:365',
            ],
            'leaves' => [
                'employee_id'=>'required|integer|min:1','starts_on'=>$date,
                'ends_on'=>'required|date_format:Y-m-d|after_or_equal:starts_on',
                'days'=>'required|numeric|gt:0|max:365',
            ],
            'timesheets' => [
                'employee_id'=>'required|integer|min:1','work_on'=>$date,
                'duration_minutes'=>'required|integer|between:1,1440',
                'project_ref'=>'required_without:work_order_id|nullable|string|max:80',
                'work_order_id'=>'required_without:project_ref|nullable|integer|min:1',
            ],
            'skills' => [
                'code'=>'required|string|max:60','name'=>'required|string|max:180',
                'safety_critical'=>'sometimes|boolean',
            ],
            'certifications' => [
                'employee_id'=>'required|integer|min:1','skill_id'=>'required|integer|min:1',
                'obtained_on'=>$date,'expires_on'=>'nullable|date_format:Y-m-d|after_or_equal:obtained_on',
                'issuer'=>'nullable|string|max:180','evidence_ref'=>'nullable|string|max:180',
            ],
            default => abort(405, 'Ressource en lecture seule.'),
        };
    }

    /** Only explicitly declared fields may be written; SQL imposes cross-company FKs. */
    public function store(Request $request, string $resource): JsonResponse
    {
        $this->authorizeHr($request, true);
        $table = $this->table($resource);
        $rules = $this->rules($resource);
        $data = $request->validate($rules + ['request_key'=>'required|string|min:8|max:100']);
        unset($data['request_key']);
        if ($resource === 'timesheets') {
            abort_if(!empty($data['project_ref']) === !empty($data['work_order_id']),422,
                'Choisir un projet OU un bon de travail, pas les deux.');
        }
        if ($resource === 'leaves') {
            abort_unless(substr($data['starts_on'],0,4) === substr($data['ends_on'],0,4),
                422, 'Découper les congés qui chevauchent deux années.');
            $data['year'] = (int)substr($data['starts_on'],0,4);
        }
        foreach ([
            'employee_id'=>'hr_employees','manager_employee_id'=>'hr_employees',
            'establishment_id'=>'establishments','team_id'=>'hr_teams',
            'work_order_id'=>'maintenance_work_orders','skill_id'=>'hr_skills',
        ] as $field=>$parent) if (isset($data[$field])) $this->requireRef($parent,(int)$data[$field]);

        if ($resource === 'schedules') {
            $employee = $this->row('hr_employees',(int)$data['employee_id']);
            abort_unless($employee->status === 'active', 409, 'Collaborateur inactif.');
            $this->ensureAssignment((int)$data['employee_id'], (int)$data['establishment_id'],
                substr($data['starts_at'],0,10));
        }
        return $this->command($request, "create:$resource", $table,
            function() use($request,$resource,$table,$data): int {
                $company = $this->company();
                if ($resource === 'timesheets') {
                    $this->assertDailyMinutes((int)$data['employee_id'],$data['work_on'],(int)$data['duration_minutes']);
                }
                $values = $data + ['company_id'=>$company,'created_at'=>now(),'updated_at'=>now()];
                if (in_array($resource,['schedules','leaves','timesheets'],true)) {
                    $values['created_by'] = $request->user()->id;
                }
                if ($resource === 'balances') {
                    $values['used_days'] = 0;
                }
                $id = DB::table($table)->insertGetId($values);
                $this->event($resource,$id,'created',$request);
                return $id;
            });
    }

    private function ensureAssignment(int $employee,int $site,string $day): void
    {
        abort_unless(DB::table('hr_assignments')->where('company_id',$this->company())
            ->where('employee_id',$employee)->where('establishment_id',$site)
            ->where('starts_on','<=',$day)
            ->where(fn($q)=>$q->whereNull('ends_on')->orWhere('ends_on','>=',$day))
            ->exists(), 409, 'Aucune affectation valide sur ce site à cette date.');
    }

    private function assertDailyMinutes(int $employee,string $day,int $incoming): void
    {
        // Serializes all daily time entries for an employee even if none exists yet.
        $this->row('hr_employees',$employee,true);
        $sum = (int)DB::table('hr_time_entries')->where('company_id',$this->company())
            ->where('employee_id',$employee)->where('work_on',$day)
            ->whereNotIn('status',['returned'])->sum('duration_minutes');
        abort_if($sum+$incoming>1440,409,'La somme du temps dépasse 24 h pour ce jour.');
    }

    private function conflictWithSchedule(int $employee,string $from,string $to): bool
    {
        return DB::table('hr_schedules')->where('company_id',$this->company())
            ->where('employee_id',$employee)->where('status','published')
            ->whereDate('starts_at','<=',$to)->whereDate('ends_at','>=',$from)->exists();
    }

    private function approvedLeave(int $employee,string $from,string $to): bool
    {
        return DB::table('hr_leave_requests')->where('company_id',$this->company())
            ->where('employee_id',$employee)->where('status','approved')
            ->where('starts_on','<=',$to)->where('ends_on','>=',$from)->exists();
    }

    public function transition(Request $request,string $resource,int $id): JsonResponse
    {
        $this->authorizeHr($request,true);
        $table = $this->table($resource);
        abort_unless(in_array($resource,['employees','teams','schedules','leaves','timesheets','certifications'],true),
            405);
        $data = $request->validate([
            'status'=>'required|string|max:24', 'version'=>'required|integer|min:1',
            'reason'=>'nullable|string|max:500',
            'effective_to'=>'nullable|date_format:Y-m-d',
            'left_on'=>'nullable|date_format:Y-m-d',
        ]);
        return $this->command($request,"transition:$resource:$id",$table,function () use($request,$resource,$table,$id,$data): int {
            $row=$this->row($table,$id,true);
            abort_unless(!isset($row->version)||(int)$row->version===(int)$data['version'],409,
                'Version périmée : rechargez la fiche.');
            $to=$data['status'];
            $allowed = match($resource) {
                'employees'=>['active'=>['inactive'],'inactive'=>[]],
                'teams'=>[],
                'schedules'=>['draft'=>['published','cancelled'],'published'=>['cancelled'],'cancelled'=>[]],
                'leaves'=>['draft'=>['submitted','cancelled'],'submitted'=>['approved','rejected','cancelled'],
                    'approved'=>['cancelled'],'rejected'=>[],'cancelled'=>[]],
                'timesheets'=>['draft'=>['submitted'],'submitted'=>['approved','returned'],
                    'returned'=>['submitted'],'approved'=>['transferred'],'transferred'=>[]],
                'certifications'=>['pending'=>['approved','revoked'],'approved'=>['revoked'],'revoked'=>[]],
                default=>[],
            };
            abort_unless(in_array($to,$allowed[$row->status]??[],true),409,'Transition interdite.');
            $values=['status'=>$to,'updated_at'=>now()];
            if (property_exists($row,'version')) $values['version']=(int)$row->version+1;
            if ($resource==='employees' && $to==='inactive') {
                $date=$data['left_on']??null;
                abort_unless($date && $date>=$row->hired_on,422,'Date de sortie valide obligatoire.');
                $values['left_on']=$date;
            }
            if ($resource==='schedules' && $to==='published') {
                $this->ensureAssignment((int)$row->employee_id,(int)$row->establishment_id,
                    substr($row->starts_at,0,10));
                abort_if($this->approvedLeave((int)$row->employee_id,substr($row->starts_at,0,10),
                    substr($row->ends_at,0,10)),409,'Congé approuvé en conflit.');
                $values['published_by']=$request->user()->id;
            }
            if ($resource==='leaves') {
                if ($to==='approved') {
                    abort_if($row->created_by===$request->user()->id,403,'Auto-approbation interdite.');
                    $this->row('hr_employees',(int)$row->employee_id,true);
                    abort_if($this->conflictWithSchedule((int)$row->employee_id,$row->starts_on,$row->ends_on),
                        409,'Planning publié en conflit, arbitrage requis.');
                    $balance = DB::table('hr_leave_balances')->where('company_id',$this->company())
                        ->where('employee_id',$row->employee_id)->where('year',$row->year)
                        ->lockForUpdate()->first();
                    abort_unless($balance,409,'Solde de congés annuel absent.');
                    abort_if((float)$balance->used_days+(float)$row->days>(float)$balance->allocated_days,
                        409,'Solde de congés insuffisant.');
                    DB::table('hr_leave_balances')->where('id',$balance->id)
                        ->update(['used_days'=>DB::raw('used_days + '.(float)$row->days),'updated_at'=>now()]);
                    $values['decided_by']=$request->user()->id;
                } elseif ($to==='cancelled' && $row->status==='approved') {
                    $balance=DB::table('hr_leave_balances')->where('company_id',$this->company())
                        ->where('employee_id',$row->employee_id)->where('year',$row->year)
                        ->lockForUpdate()->first();
                    abort_unless($balance,409,'Solde annuel manquant.');
                    DB::table('hr_leave_balances')->where('id',$balance->id)
                        ->update(['used_days'=>DB::raw('used_days - '.(float)$row->days),'updated_at'=>now()]);
                } elseif ($to==='rejected') $values['decided_by']=$request->user()->id;
                if (in_array($to,['rejected','cancelled'],true)) {
                    abort_unless(strlen(trim((string)($data['reason']??'')))>=5,422,'Motif obligatoire (5 caractères).');
                    $values['decision_reason']=$data['reason'];
                }
            }
            if ($resource==='timesheets' && $to==='approved') {
                abort_if($row->created_by===$request->user()->id,403,'Auto-approbation interdite.');
                $values['approved_by']=$request->user()->id;
            }
            if ($resource==='certifications' && $to==='approved') {
                $employee=$this->row('hr_employees',(int)$row->employee_id);
                abort_if($employee->user_id===$request->user()->id,403,'Auto-validation de sécurité interdite.');
                abort_if($row->expires_on!==null && $row->expires_on<date('Y-m-d'),409,
                    'Habilitation expirée.');
                $values['validated_by']=$request->user()->id;
            }
            DB::table($table)->where('company_id',$this->company())->where('id',$id)->update($values);
            $this->event($resource,$id,'transition',$request,$row->status,$to,$data['reason']??null);
            return $id;
        });
    }

    /** Company-scoped, role-protected lightweight metrics: no payroll/medical metadata. */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeHr($request);
        $company=$this->company();
        $count=fn(string $table)=>DB::table($table)->where('company_id',$company)->count();
        return response()->json([
            'employees'=>$count('hr_employees'),'teams'=>$count('hr_teams'),
            'assignments'=>$count('hr_assignments'),'schedules'=>$count('hr_schedules'),
            'pending_leaves'=>DB::table('hr_leave_requests')->where('company_id',$company)
                ->where('status','submitted')->count(),
            'pending_timesheets'=>DB::table('hr_time_entries')->where('company_id',$company)
                ->where('status','submitted')->count(),
            'expiring_skills'=>DB::table('hr_employee_skills')->where('company_id',$company)
                ->where('status','approved')->whereBetween('expires_on',[today(),today()->addDays(30)])->count(),
            'generated_at'=>now()->toIso8601String(),
        ]);
    }
}
