<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Archive review and controlled restoration; accounting history is immutable. */
final class CrmCustomerLifecycleController extends Controller
{
    public function archived(Request $request)
    {
        Gate::authorize('crm');
        $filters=$request->validate([
            'search'=>'nullable|string|max:100',
            'page'=>'nullable|integer|min:1',
        ]);
        $query=Customer::query()->whereNotNull('archived_at');
        if (!empty($filters['search'])){
            $needle=mb_strtolower($filters['search']);
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.str_replace(['%','_'],['\%','\_'],$needle).'%']);
        }
        return $query->select('id','name','email','archived_at','archive_reason')
            ->orderByDesc('archived_at')->paginate(30);
    }

    public function restore(Request $request,int $id)
    {
        Gate::authorize('crm');
        $data=$request->validate([
            'reason'=>'required|string|min:8|max:500',
        ]);
        return DB::transaction(function()use($request,$id,$data){
            $company=app(TenantContext::class)->requireId();
            $customer=Customer::query()->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($customer->archived_at!==null,409,'Client déjà actif.');
            // No restoration while a logical duplicate link points to a canonical record.
            abort_if(DB::table('crm_customer_links')->where('company_id',$company)
                ->where('duplicate_customer_id',$id)->where('active',true)->exists(),409,
                'Annulez le rapprochement de doublon avant la restauration.');
            $oldReason=$customer->archive_reason;
            $customer->archived_at=null;
            $customer->archive_reason=null;
            $customer->save();
            Audit::record('crm.customer.restored',$customer,[
                'reason'=>$data['reason'],'previous_archive_reason'=>$oldReason,
            ]);
            return response()->json($customer);
        },3);
    }
}
