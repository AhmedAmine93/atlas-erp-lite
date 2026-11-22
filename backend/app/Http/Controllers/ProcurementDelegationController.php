<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Explicit spending limits. Safe default is the existing four-eye admin flow
 * until a company enables at least one delegation for a currency.
 */
final class ProcurementDelegationController extends Controller
{
    private function tenant(): int { return app(TenantContext::class)->requireId(); }

    public function index(Request $request)
    {
        Gate::authorize('admin');
        $request->validate(['page'=>'sometimes|integer|min:1']);
        return DB::table('purchase_approval_delegations as d')
            ->join('users as u','u.id','=','d.user_id')
            ->where('d.company_id',$this->tenant())
            ->select('d.id','d.company_id','d.user_id','u.name as approver_name','d.currency',
                'd.max_amount_cents','d.valid_until','d.active','d.granted_by','d.updated_at')
            ->orderBy('d.currency')->orderBy('u.name')->paginate(50);
    }

    public function save(Request $request)
    {
        Gate::authorize('admin');
        $data=$request->validate([
            'user_id'=>'required|integer|min:1',
            'currency'=>['required',Rule::in(['EUR','USD','GBP'])],
            'max_amount_cents'=>'required|integer|min:1|max:100000000000',
            'valid_until'=>'nullable|date|after_or_equal:today',
            'active'=>'sometimes|boolean',
        ]);
        abort_if((int)$data['user_id']===(int)$request->user()->id,
            403,'Il est interdit de se déléguer un plafond d’approbation.');
        return DB::transaction(function() use($request,$data){
            $tenant=$this->tenant();
            $member=DB::table('company_user as m')->join('roles as r','r.id','=','m.role_id')
                ->where('m.company_id',$tenant)->where('m.user_id',$data['user_id'])
                ->where('r.name','admin')->first();
            abort_unless($member,422,'La personne désignée doit être administratrice de cette entreprise.');
            $existing=DB::table('purchase_approval_delegations')
                ->where('company_id',$tenant)->where('user_id',$data['user_id'])
                ->where('currency',$data['currency'])->lockForUpdate()->first();
            $values=[
                'max_amount_cents'=>$data['max_amount_cents'],
                'valid_until'=>$data['valid_until']??null,
                'active'=>$data['active']??true,
                'granted_by'=>$request->user()->id,
                'updated_at'=>now(),
            ];
            if($existing){
                DB::table('purchase_approval_delegations')->where('id',$existing->id)
                    ->where('company_id',$tenant)->update($values);
                $id=$existing->id;
            } else {
                $id=DB::table('purchase_approval_delegations')->insertGetId($values+[
                    'company_id'=>$tenant,'user_id'=>$data['user_id'],
                    'currency'=>$data['currency'],'created_at'=>now(),
                ]);
            }
            Audit::record($existing?'purchase.delegation.updated':'purchase.delegation.created',null,[
                'record_id'=>$id,'approver_id'=>$data['user_id'],'currency'=>$data['currency'],
                'old_limit_cents'=>$existing?->max_amount_cents,'new_limit_cents'=>$data['max_amount_cents'],
                'active'=>$values['active'],
            ]);
            return response()->json(['id'=>$id,'user_id'=>$data['user_id'],
                'currency'=>$data['currency'],'max_amount_cents'=>$data['max_amount_cents'],
                'active'=>$values['active']],$existing?200:201);
        },3);
    }
}
