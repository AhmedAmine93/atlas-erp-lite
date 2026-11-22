<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An audience is only a preview of contacts that explicitly opted in.
 * No sending, enrichment or automated campaigns are performed here.
 */
final class CrmSegmentAudienceController extends Controller
{
    public function index(int $id)
    {
        Gate::authorize('crm');
        $company=app(TenantContext::class)->requireId();
        $segment=DB::table('crm_segments')->where('company_id',$company)
            ->where('id',$id)->whereNull('archived_at')->first();
        abort_unless($segment,404,'Segment introuvable.');

        $query=DB::table('crm_contacts as contact')
            ->join('customers as customer', function($j){
                $j->on('customer.id','=','contact.customer_id')
                    ->on('customer.company_id','=','contact.company_id');
            })
            ->where('contact.company_id',$company)
            ->where('contact.consent','granted')
            ->where('contact.active',true)
            ->whereNull('customer.archived_at')
            ->where(function($q) {
                $q->whereNull('contact.valid_from')
                    ->orWhere('contact.valid_from','<=',today());
            })
            ->where(function($q) {
                $q->whereNull('contact.valid_to')
                    ->orWhere('contact.valid_to','>=',today());
            })
            ->whereNotNull('contact.email');

        if($segment->mode==='static'){
            $query->whereExists(function($sub)use($company,$id){
                $sub->selectRaw('1')->from('crm_segment_memberships as member')
                    ->whereColumn('member.customer_id','customer.id')
                    ->whereColumn('member.company_id','customer.company_id')
                    ->where('member.company_id',$company)
                    ->where('member.segment_id',$id)
                    ->where('member.active',true);
            });
        }elseif($segment->mode==='dynamic'){
            $criteria=json_decode($segment->criteria?:'{}',true);
            $field=$criteria['field']??'';
            $value=$criteria['value']??'';
            abort_unless(is_string($value) && mb_strlen($value)<=100
                && in_array($field,['name','email_domain'],true),422,'Critères non autorisés.');
            if($field==='name'){
                $escaped=str_replace(['\\','%','_'],['\\\\','\%','\_'],mb_strtolower($value));
                $query->whereRaw('LOWER(customer.name) LIKE ? ESCAPE ?', ['%'.$escaped.'%','\\']);
            }else{
                $domain=mb_strtolower(ltrim($value,'@'));
                abort_unless(filter_var('user@'.$domain,FILTER_VALIDATE_EMAIL),422,'Domaine invalide.');
                $query->whereRaw('LOWER(customer.email) LIKE ?', ['%@'.$domain]);
            }
        }else{
            abort(422,'Mode de segment inconnu.');
        }
        $data=$query->select('contact.id','contact.customer_id','contact.first_name',
            'contact.last_name','contact.email')
            ->distinct()->orderBy('contact.id')->paginate(30);

        Audit::record('crm.segment.marketing_audience.previewed',null,[
            'segment_id'=>$id,'result_count'=>$data->total(),
        ]);
        return $data;
    }
}
