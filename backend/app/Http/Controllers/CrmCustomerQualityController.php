<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * M02 customer quality: candidates are hints, not automatic merges. Linking
 * archives only the duplicate; all accounting and sales FK values stay intact.
 */
final class CrmCustomerQualityController extends Controller
{
    private function company():int { return app(TenantContext::class)->requireId(); }

    public function candidates()
    {
        Gate::authorize('crm');
        $company=$this->company();
        // Intentionally bounded; a large tenant must review in batches.
        $rows=Customer::query()->whereNull('archived_at')->orderBy('id')
            ->limit(501)->get(['id','name','email','phone']);
        $byKey=[];$matches=[];$seen=[];
        foreach($rows->take(500) as $row){
            $keys=[];
            $name=mb_strtolower(trim(preg_replace('/\s+/u',' ',$row->name)));
            if(mb_strlen($name)>=4)$keys[]=['name',$name];
            $phone=preg_replace('/\D+/u','',(string)$row->phone);
            if(strlen($phone)>=8)$keys[]=['phone',$phone];
            foreach($keys as [$type,$value]){
                $key=$type.':'.$value;
                foreach(($byKey[$key]??[]) as $earlier){
                    $pair=min($row->id,$earlier->id).':'.max($row->id,$earlier->id);
                    if(isset($seen[$pair]))continue;
                    $seen[$pair]=true;
                    $matches[]=[
                        'left'=>['id'=>$earlier->id,'name'=>$earlier->name,'email'=>$earlier->email],
                        'right'=>['id'=>$row->id,'name'=>$row->name,'email'=>$row->email],
                        'match_on'=>$type,
                    ];
                    if(count($matches)>=100)break 3;
                }
                $byKey[$key][]=$row;
            }
        }
        return response()->json([
            'candidates'=>$matches,
            'scanned'=>min($rows->count(),500),
            'truncated'=>$rows->count()>500 || count($matches)>=100,
        ]);
    }

    public function links(Request $request)
    {
        Gate::authorize('crm');
        $company=$this->company();
        $filters=$request->validate(['active'=>'sometimes|boolean']);
        return DB::table('crm_customer_links as l')
            ->join('customers as d',function($j){$j->on('d.id','=','l.duplicate_customer_id')->on('d.company_id','=','l.company_id');})
            ->join('customers as c',function($j){$j->on('c.id','=','l.canonical_customer_id')->on('c.company_id','=','l.company_id');})
            ->where('l.company_id',$company)
            ->when(array_key_exists('active',$filters),fn($q)=>$q->where('l.active',(bool)$filters['active']))
            ->select('l.id','l.duplicate_customer_id','l.canonical_customer_id','l.active','l.reason','l.revision',
                'l.created_at','l.reversed_at','d.name as duplicate_name','c.name as canonical_name')
            ->orderByDesc('l.id')->paginate(20);
    }

    public function link(Request $request)
    {
        Gate::authorize('crm');
        $data=$request->validate([
            'duplicate_customer_id'=>'required|integer|min:1',
            'canonical_customer_id'=>'required|integer|min:1|different:duplicate_customer_id',
            'reason'=>'required|string|min:8|max:1000',
        ]);
        $company=$this->company();
        return DB::transaction(function()use($data,$company,$request){
            $sourceId=(int)$data['duplicate_customer_id'];
            $targetId=(int)$data['canonical_customer_id'];
            $customers=Customer::query()->whereIn('id',[$sourceId,$targetId])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($customers->count()===2,404,'Clients introuvables pour cette entreprise.');
            $source=$customers[$sourceId];
            $target=$customers[$targetId];
            abort_if($target->archived_at !== null,409,'La fiche de référence est archivée.');
            $existing=DB::table('crm_customer_links')->where('company_id',$company)
                ->where('duplicate_customer_id',$sourceId)->lockForUpdate()->first();
            if($existing && $existing->active){
                abort_unless((int)$existing->canonical_customer_id===$targetId,409,'Fiche déjà rattachée à un autre client.');
                return response()->json($existing);
            }
            abort_if($source->archived_at !== null,409,'Le doublon est déjà archivé.');
            abort_if(DB::table('crm_customer_links')->where('company_id',$company)->where('active',true)
                ->where('duplicate_customer_id',$targetId)->exists(),409,'La référence est elle-même un doublon.');
            abort_if(DB::table('crm_customer_links')->where('company_id',$company)->where('active',true)
                ->where('canonical_customer_id',$sourceId)->exists(),409,'Le doublon est déjà une fiche de référence.');
            abort_if($existing && $existing->canonical_customer_id!=$targetId,409,
                'Une ancienne décision existe : révision manuelle obligatoire.');
            $values=[
                'company_id'=>$company,'duplicate_customer_id'=>$sourceId,'canonical_customer_id'=>$targetId,
                'active'=>true,'reason'=>$data['reason'],'previous_archived_at'=>$source->archived_at,
                'previous_archive_reason'=>$source->archive_reason,'linked_by'=>$request->user()->id,
                'reversed_by'=>null,'reversed_at'=>null,'updated_at'=>now(),
            ];
            if($existing){
                DB::table('crm_customer_links')->where('company_id',$company)->where('id',$existing->id)
                    ->update(array_merge($values,['revision'=>$existing->revision+1]));
                $id=$existing->id;
            }else{
                $id=DB::table('crm_customer_links')->insertGetId(array_merge($values,[
                    'revision'=>1,'created_at'=>now(),
                ]));
            }
            $source->archived_at=now();
            $source->archive_reason='Doublon logique du client #'.$targetId;
            $source->save();
            Audit::record('crm.customer.duplicate.linked',$source,[
                'canonical_id'=>$targetId,'link_id'=>$id,'reason'=>$data['reason'],
            ]);
            return response()->json(DB::table('crm_customer_links')
                ->where('company_id',$company)->where('id',$id)->first(),$existing?200:201);
        },3);
    }

    public function reverse(Request $request,int $id)
    {
        Gate::authorize('crm');
        $data=$request->validate([
            'reason'=>'required|string|min:8|max:1000',
            'expected_revision'=>'required|integer|min:1',
        ]);
        $company=$this->company();
        return DB::transaction(function()use($request,$company,$id,$data){
            $link=DB::table('crm_customer_links')->where('company_id',$company)
                ->where('id',$id)->lockForUpdate()->first();
            abort_unless($link,404);
            abort_unless($link->active,409,'Association déjà annulée.');
            abort_unless((int)$link->revision===(int)$data['expected_revision'],409,'Version périmée.');
            $source=Customer::query()->where('id',$link->duplicate_customer_id)->lockForUpdate()->firstOrFail();
            abort_unless($source->archive_reason==='Doublon logique du client #'.$link->canonical_customer_id,
                409,'L’état du client a changé : intervention manuelle nécessaire.');
            $source->archived_at=$link->previous_archived_at;
            $source->archive_reason=$link->previous_archive_reason;
            $source->save();
            DB::table('crm_customer_links')->where('company_id',$company)->where('id',$id)->update([
                'active'=>false,'reversed_at'=>now(),'reversed_by'=>$request->user()->id,
                'revision'=>$link->revision+1,'updated_at'=>now(),
            ]);
            Audit::record('crm.customer.duplicate.reversed',$source,[
                'link_id'=>$id,'canonical_id'=>$link->canonical_customer_id,'reason'=>$data['reason'],
            ]);
            return response()->json(DB::table('crm_customer_links')
                ->where('company_id',$company)->where('id',$id)->first());
        },3);
    }
}
