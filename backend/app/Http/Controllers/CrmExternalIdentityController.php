<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * User-managed provenance keys. Same source ID cannot silently refer to two
 * separate records of a single company. No external connectors are called.
 */
final class CrmExternalIdentityController extends Controller
{
    private const ALIASES=[
        'customers'=>['customers','customer_id'],
        'suppliers'=>['suppliers','supplier_id'],
        'contacts'=>['crm_contacts','contact_id'],
        'prospects'=>['crm_prospects','prospect_id'],
    ];
    private function tenant():int {return app(TenantContext::class)->requireId();}

    private function validateOwner(string $kind,int $id):string
    {
        abort_unless(isset(self::ALIASES[$kind]),404);
        [$table,$field]=self::ALIASES[$kind];
        abort_unless(DB::table($table)->where('company_id',$this->tenant())
            ->where('id',$id)->exists(),404,'Fiche cible introuvable.');
        return $field;
    }

    public function index(string $kind,int $id)
    {
        Gate::authorize('crm');
        $field=$this->validateOwner($kind,$id);
        return DB::table('crm_external_identifiers')
            ->where('company_id',$this->tenant())->where($field,$id)
            ->select('id','source_system','external_id','active','revision','created_at','revoked_at')
            ->orderByDesc('id')->paginate(30);
    }

    public function store(Request $request,string $kind,int $id)
    {
        Gate::authorize('crm');
        $field=$this->validateOwner($kind,$id);
        $data=$request->validate([
            'source_system'=>'required|string|min:2|max:50|regex:/^[A-Za-z][A-Za-z0-9_-]*$/',
            'external_id'=>'required|string|min:1|max:180',
        ]);
        $source=strtoupper(trim($data['source_system']));
        $external=trim($data['external_id']);
        abort_unless($external!=='',422,'Identifiant externe vide.');
        return DB::transaction(function()use($request,$id,$field,$source,$external){
            $old=DB::table('crm_external_identifiers')->where('company_id',$this->tenant())
                ->where('source_system',$source)->where('external_id',$external)
                ->lockForUpdate()->first();
            if($old){
                abort_unless($old->active && (int)$old->$field===$id,409,
                    'Identifiant déjà attribué ou révoqué : arbitrage humain obligatoire.');
                return response()->json($old);
            }
            $newId=DB::table('crm_external_identifiers')->insertGetId([
                'company_id'=>$this->tenant(),'source_system'=>$source,'external_id'=>$external,
                $field=>$id,'created_by'=>$request->user()->id,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            Audit::record('crm.external_identifier.linked',null,[
                'alias_id'=>$newId,'source_system'=>$source,'record_id'=>$id,'record_field'=>$field,
            ]);
            return response()->json(DB::table('crm_external_identifiers')
                ->where('company_id',$this->tenant())->where('id',$newId)->first(),201);
        },3);
    }

    public function revoke(Request $request,int $id)
    {
        Gate::authorize('crm');
        $data=$request->validate([
            'reason'=>'required|string|min:8|max:500',
            'expected_revision'=>'required|integer|min:1',
        ]);
        return DB::transaction(function()use($request,$id,$data){
            $row=DB::table('crm_external_identifiers')
                ->where('company_id',$this->tenant())->where('id',$id)
                ->lockForUpdate()->first();
            abort_unless($row,404);
            abort_unless($row->active,409,'Alias déjà révoqué.');
            abort_unless((int)$row->revision===(int)$data['expected_revision'],409,'Version périmée.');
            DB::table('crm_external_identifiers')->where('company_id',$this->tenant())
                ->where('id',$id)->update([
                    'active'=>false,'revision'=>$row->revision+1,
                    'revoked_at'=>now(),'revoked_by'=>$request->user()->id,
                    'updated_at'=>now(),
                ]);
            Audit::record('crm.external_identifier.revoked',null,[
                'alias_id'=>$id,'reason'=>$data['reason'],
            ]);
            return response()->json(['id'=>$id,'active'=>false,'revision'=>$row->revision+1]);
        });
    }
}
