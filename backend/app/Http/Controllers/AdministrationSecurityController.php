<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdministrationSecurityController extends Controller
{
    private function company(): int
    {
        Gate::authorize('admin');
        return app(TenantContext::class)->requireId();
    }

    public function roleCatalog(): array
    {
        $this->company();
        // Existing global role definitions are migration-owned: tenant admins
        // may assign memberships, not modify global privileges.
        return [
            'roles' => Role::query()->orderBy('name')->get(['id','name']),
            'permissions' => [
                ['code' => 'admin', 'roles' => ['admin'], 'description' => 'Administration du tenant'],
                ['code' => 'support', 'roles' => ['admin','support_n2','support_n3'], 'description' => 'Incidents'],
                ['code' => 'supportDiagnostics', 'roles' => ['admin','support_n3'], 'description' => 'Diagnostics'],
                ['code' => 'crmRead', 'roles' => ['admin','commercial','reader'], 'description' => 'Lecture CRM'],
                ['code' => 'sales', 'roles' => ['admin','commercial'], 'description' => 'Écritures commerciales'],
                ['code' => 'stock', 'roles' => ['admin','stock'], 'description' => 'Écritures de stock'],
            ],
            'policy_mode' => 'read-only-application-gates',
        ];
    }

    public function memberships(Request $request)
    {
        $companyId = $this->company();
        $query = DB::table('company_user')
            ->join('users', 'company_user.user_id', '=', 'users.id')
            ->join('roles', 'company_user.role_id', '=', 'roles.id')
            ->where('company_user.company_id', $companyId)
            ->select('users.id','users.name','users.email','roles.name as role','company_user.suspended_at');
        if ($request->filled('search')) {
            $search = trim((string) $request->validate(['search'=>'string|max:100'])['search']);
            $query->where(fn ($q) => $q->where('users.name','like','%'.$search.'%')
                ->orWhere('users.email','like','%'.$search.'%'));
        }
        return $query->orderBy('users.name')->paginate(20);
    }

    public function suspend(Request $request, int $userId)
    {
        return $this->membershipTransition($request, $userId, true);
    }

    public function reactivate(Request $request, int $userId)
    {
        return $this->membershipTransition($request, $userId, false);
    }

    private function membershipTransition(Request $request, int $userId, bool $suspend)
    {
        $companyId = $this->company();
        $request->validate(['reason' => 'required|string|min:5|max:500']);
        abort_if($userId === $request->user()->id, 409, 'Impossible de modifier votre propre affiliation.');
        return DB::transaction(function () use ($companyId,$userId,$suspend,$request) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $member = DB::table('company_user')->where('company_id',$companyId)
                ->where('user_id',$userId)->lockForUpdate()->first();
            abort_unless($member, 404);
            $adminId = Role::where('name','admin')->value('id');
            if ($suspend && (int) $member->role_id === (int) $adminId && !$member->suspended_at) {
                $remaining = DB::table('company_user')->where('company_id',$companyId)
                    ->where('role_id',$adminId)->whereNull('suspended_at')->count();
                abort_if($remaining < 2, 409, 'Le dernier administrateur actif ne peut être suspendu.');
            }
            if ($suspend === (bool) $member->suspended_at) {
                return response()->json(['status'=>$suspend ? 'suspended':'active','changed'=>false]);
            }
            DB::table('company_user')->where('company_id',$companyId)->where('user_id',$userId)
                ->update(['suspended_at'=>$suspend ? now():null]);
            // Session deletion makes existing browser cookies unusable.
            DB::table('sessions')->where('user_id',$userId)->delete();
            Audit::record($suspend ? 'member.suspended' : 'member.reactivated',
                User::findOrFail($userId), ['reason'=>$request->input('reason')]);
            return response()->json(['status'=>$suspend ? 'suspended':'active','changed'=>true]);
        }, 3);
    }

    public function settings(Request $request)
    {
        $companyId = $this->company();
        $request->validate(['key'=>'nullable|string|max:64']);
        $versions = DB::table('admin_setting_versions')->where('company_id',$companyId)
            ->when($request->query('key'),fn ($q,$key)=>$q->where('key',$key))
            ->orderBy('key')->orderByDesc('version')->paginate(50);
        return $versions;
    }

    public function publishSetting(Request $request)
    {
        $companyId = $this->company();
        $data = $request->validate([
            'key'=>['required','in:locale,timezone,reference_currency'],
            'value'=>'required|string|max:80',
            'effective_at'=>'required|date|after_or_equal:today',
            'expected_version'=>'required|integer|min:0',
        ]);
        if ($data['key'] === 'locale' && !in_array($data['value'], ['fr','en','ar'], true)) {
            throw ValidationException::withMessages(['value'=>'Langue invalide.']);
        }
        if ($data['key'] === 'timezone' && !in_array($data['value'], timezone_identifiers_list(), true)) {
            throw ValidationException::withMessages(['value'=>'Fuseau IANA invalide.']);
        }
        if ($data['key'] === 'reference_currency' && !preg_match('/^[A-Z]{3}$/D',$data['value'])) {
            throw ValidationException::withMessages(['value'=>'Devise ISO 4217 attendue.']);
        }
        return DB::transaction(function () use ($companyId,$data) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $version = (int) DB::table('admin_setting_versions')->where('company_id',$companyId)
                ->where('key',$data['key'])->max('version');
            abort_if($version !== (int) $data['expected_version'], 409, 'Version de configuration obsolète.');
            $id = DB::table('admin_setting_versions')->insertGetId([
                'company_id'=>$companyId, 'key'=>$data['key'],'value_type'=>'string',
                'value'=>json_encode($data['value']), 'version'=>$version+1,
                'effective_at'=>$data['effective_at'],'created_by'=>auth()->id(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            Audit::record('admin.setting.published', null, ['key'=>$data['key'],'version'=>$version+1]);
            return response()->json(DB::table('admin_setting_versions')->where('company_id',$companyId)->find($id),201);
        },3);
    }

    public function series(Request $request)
    {
        $companyId = $this->company();
        return DB::table('admin_document_series')->where('company_id',$companyId)
            ->orderByDesc('period_year')->orderBy('document_type')->paginate(40);
    }

    public function createSeries(Request $request)
    {
        $companyId = $this->company();
        $data = $request->validate([
            'document_type'=>'required|in:quote,order,invoice',
            'establishment_id'=>'nullable|integer',
            'period_year'=>'required|integer|min:2026|max:2100',
            'prefix'=>'required|string|max:32|regex:/^[A-Z0-9_-]+$/D',
        ]);
        if (isset($data['establishment_id'])) {
            abort_unless(DB::table('establishments')->where('company_id',$companyId)
                ->where('id',$data['establishment_id'])->where('active',true)->exists(),404);
        }
        $scope = isset($data['establishment_id']) ? 'site:'.$data['establishment_id'] : 'company';
        return DB::transaction(function () use ($companyId,$data,$scope) {
            $values = array_merge($data,[
                'company_id'=>$companyId,'scope_key'=>$scope,'created_by'=>auth()->id(),
                'next_number'=>1,'version'=>1,'active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            $id = DB::table('admin_document_series')->insertGetId($values);
            Audit::record('admin.series.created',null,['series_id'=>$id]);
            return response()->json(DB::table('admin_document_series')->where('company_id',$companyId)->find($id),201);
        },3);
    }

    public function allocate(Request $request, int $seriesId)
    {
        $companyId = $this->company();
        $data = $request->validate([
            'operation_key'=>'required|string|between:8,80|regex:/^[A-Za-z0-9:._-]+$/D',
        ]);
        // This reservation API is for controlled integrations only: it is NOT
        // an alternative path for issuing invoices in the existing ERP.
        return DB::transaction(function () use ($companyId,$seriesId,$data) {
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $series = DB::table('admin_document_series')->where('company_id',$companyId)
                ->where('id',$seriesId)->lockForUpdate()->first();
            abort_unless($series,404);
            abort_unless($series->active,409,'Série désactivée.');
            abort_unless((int)$series->period_year === (int)now()->format('Y'),409,'Série hors période.');
            $digest = hash('sha256',json_encode([$seriesId,$data['operation_key']]));
            $existing = DB::table('admin_document_allocations')->where('company_id',$companyId)
                ->where('operation_key',$data['operation_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_sha256,$digest),409,'Clé déjà utilisée pour une autre série.');
                return response()->json($existing);
            }
            $number = $series->prefix.'-'.$series->period_year.'-'.str_pad((string)$series->next_number,5,'0',STR_PAD_LEFT);
            $id = DB::table('admin_document_allocations')->insertGetId([
                'company_id'=>$companyId,'series_id'=>$seriesId,'sequence_value'=>$series->next_number,
                'document_number'=>$number,'operation_key'=>$data['operation_key'],
                'payload_sha256'=>$digest,'created_by'=>auth()->id(),
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('admin_document_series')->where('company_id',$companyId)->where('id',$seriesId)
                ->update(['next_number'=>$series->next_number+1,'version'=>$series->version+1,'updated_at'=>now()]);
            Audit::record('admin.series.allocated',null,['series_id'=>$seriesId,'allocation_id'=>$id]);
            return response()->json(DB::table('admin_document_allocations')->where('company_id',$companyId)->find($id),201);
        },3);
    }

    public function allocations(Request $request)
    {
        $companyId = $this->company();
        return DB::table('admin_document_allocations')->where('company_id',$companyId)
            ->orderByDesc('id')->paginate(30);
    }

    public function sessions(Request $request)
    {
        $this->company();
        return DB::table('sessions')->where('user_id',$request->user()->id)
            ->select('id','ip_address','user_agent','last_activity')->orderByDesc('last_activity')->paginate(20);
    }

    public function revokeSession(Request $request, string $sessionId)
    {
        $this->company();
        abort_unless(preg_match('/^[A-Za-z0-9_-]{10,128}$/D',$sessionId),404);
        $count = DB::table('sessions')->where('user_id',$request->user()->id)
            ->where('id',$sessionId)->delete();
        abort_unless($count,404);
        Audit::record('admin.session.revoked',null,['session_id_sha256'=>hash('sha256',$sessionId)]);
        return response()->noContent();
    }

    public function invitations()
    {
        $companyId=$this->company();
        return DB::table('admin_invitations')->where('company_id',$companyId)
            ->select('id','email','role_id','expires_at','accepted_at','invited_by','created_at')
            ->orderByDesc('id')->paginate(20);
    }

    public function createInvitation(Request $request)
    {
        $companyId=$this->company();
        $data=$request->validate([
            'email'=>'required|email|max:255',
            'role_id'=>'required|integer|exists:roles,id',
            'expires_at'=>'required|date|after:now|before_or_equal:+7 days',
        ]);
        // Invitations must not disclose whether a global identity exists.
        // An out-of-band delivery channel has not been installed, so no
        // redeemable invitation is returned to the browser.
        return response()->json(['message'=>'Envoi des invitations indisponible : aucun service de livraison vérifié.'],503);
    }
}
