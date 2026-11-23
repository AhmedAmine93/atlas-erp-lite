<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** M04 reporting: allowlisted company-only read models, no raw query/table names. */
final class ProcurementReportingController extends Controller
{
    private const SCREENS = [
        'suppliers'=>['suppliers',['id','name','email','tax_registration','procurement_status','created_at']],
        'purchase-requests'=>['purchase_requests',['id','reference','title','estimated_total_cents','currency','revision','status','created_at']],
        'supplier-quotes'=>['supplier_quotes',['id','reference','supplier_id','purchase_request_id','total_cents','currency','delivery_days','valid_until','status','created_at']],
        'purchase-orders'=>['purchase_orders',['id','reference','supplier_id','purchase_request_id','total_cents','currency','status','created_at']],
        'purchase-receipts'=>['purchase_receipts',['id','reference','purchase_order_id','received_by','received_at']],
        'supplier-invoices'=>['supplier_invoices',['id','reference','supplier_id','purchase_order_id','total_cents','matched_cents','dispute_cents','currency','status','created_at']],
    ];

    public function summary()
    {
        Gate::authorize('admin');
        $company=app(TenantContext::class)->requireId();
        $results=[];
        foreach(self::SCREENS as $screen=>[$table]) {
            $results[$screen]=(int)DB::table($table)->where('company_id',$company)->count();
        }
        $results['disputed_invoice_cents']=(int)DB::table('supplier_invoices')
            ->where('company_id',$company)->sum('dispute_cents');
        $results['pending_bank_changes']=(int)DB::table('supplier_bank_changes')
            ->where('company_id',$company)->where('status','pending')->count();
        $results['pending_amendments']=(int)DB::table('purchase_order_amendments')
            ->where('company_id',$company)->where('status','pending')->count();
        return response()->json($results)->header('Cache-Control','no-store');
    }

    public function export(Request $request,string $screen)
    {
        Gate::authorize('admin');
        abort_unless(isset(self::SCREENS[$screen]),404);
        $data=$request->validate([
            'status'=>'nullable|string|max:30',
            'search'=>'nullable|string|max:100',
        ]);
        [$table,$columns]=self::SCREENS[$screen];
        $company=app(TenantContext::class)->requireId();
        $query=DB::table($table)->where('company_id',$company);
        if(!empty($data['status']) && in_array('status',$columns,true)) {
            $query->where('status',$data['status']);
        }
        if(!empty($data['search'])) {
            $term=mb_strtolower($data['search']);
            $searchFields=array_values(array_intersect(['reference','name','title','email'],$columns));
            if($searchFields) $query->where(function($q)use($searchFields,$term){
                foreach($searchFields as $field) {
                    $q->orWhereRaw('LOWER("'.$field.'") LIKE ?', ['%'.str_replace(['%','_'],['\\%','\\_'],$term).'%']);
                }
            });
        }
        $count=(clone $query)->count();
        abort_if($count>10000,422,'Export limité à 10 000 lignes ; affinez les filtres.');
        Audit::record('purchase.export',null,['screen'=>$screen,'count'=>$count]);
        $filename='atlas-m04-'.$screen.'-'.now()->format('Ymd-His').'.csv';
        return response()->streamDownload(function()use($query,$columns) {
            $output=fopen('php://output','w');
            fwrite($output,"\xEF\xBB\xBF");
            fputcsv($output,$columns,';');
            foreach($query->select($columns)->orderBy('id')->cursor() as $row) {
                $values=[];
                foreach($columns as $column) {
                    $value=(string)($row->$column??'');
                    // Prevent spreadsheet formula execution upon opening the CSV.
                    if(preg_match('/^[\s]*[=+\-@]/u',$value)) $value="'".$value;
                    $values[]=$value;
                }
                fputcsv($output,$values,';');
            }
            fclose($output);
        },$filename,['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store']);
    }
}
