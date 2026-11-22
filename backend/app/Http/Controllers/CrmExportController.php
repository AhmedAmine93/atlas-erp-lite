<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Privacy-preserving, bounded CSV export. CRM exports require editing rights,
 * do not include hidden/system attributes and are audited without any PII.
 */
final class CrmExportController extends Controller
{
    private const COLUMNS = [
        'customers'=>['id','name','email','phone','address','archived_at'],
        'prospects'=>['id','name','email','phone','source','status','marketing_consent','opt_out','created_at'],
        'contacts'=>['id','customer_id','first_name','last_name','email','phone','job_title','consent','active'],
        'opportunities'=>['id','customer_id','prospect_id','title','stage','amount_cents','currency','probability_bps','expected_close_at'],
        'activities'=>['id','customer_id','prospect_id','contact_id','opportunity_id','title','type','purpose','status','due_at','completed_at'],
        'segments'=>['id','code','name','mode','archived_at'],
    ];
    private const TABLES = [
        'customers'=>'customers','prospects'=>'crm_prospects','contacts'=>'crm_contacts',
        'opportunities'=>'crm_opportunities','activities'=>'crm_activities','segments'=>'crm_segments',
    ];

    private static function escapeCsv(mixed $value): string
    {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? '1' : '0';
        $s = str_replace(["\r","\n","\t"], ' ', (string) $value);
        // Spreadsheet formula injection: neutralize =, +, -, @ even when preceded by whitespace.
        if (preg_match('/^\s*[=+\-@]/u', $s) === 1) $s = "'".$s;
        return $s;
    }

    public function download(Request $request, string $resource)
    {
        Gate::authorize('crm');
        abort_unless(isset(self::TABLES[$resource]), 404);
        $filters = $request->validate([
            'search'=>'nullable|string|max:100','status'=>'nullable|string|max:30',
            'include_archived'=>'sometimes|boolean',
        ]);
        $company=app(TenantContext::class)->requireId();
        $columns=self::COLUMNS[$resource];
        $query=DB::table(self::TABLES[$resource])
            ->where('company_id',$company);
        if ($resource === 'customers' && empty($filters['include_archived'])) {
            $query->whereNull('archived_at');
        }
        if ($resource === 'segments') $query->whereNull('archived_at');

        if (!empty($filters['search'])) {
            $field = match ($resource) {
                'contacts'=>'first_name',
                'opportunities','activities'=>'title',
                default=>'name',
            };
            $search=mb_strtolower($filters['search']);
            $escaped=str_replace(['\\','%','_'],['\\\\','\%','\_'],$search);
            $query->whereRaw('LOWER("'.$field.'") LIKE ? ESCAPE ?',['%'.$escaped.'%','\\']);
        }
        if (!empty($filters['status'])) {
            abort_unless(in_array($resource,['prospects','opportunities','activities'],true),422);
            $field=$resource==='opportunities'?'stage':'status';
            $query->where($field,$filters['status']);
        }
        $stream=fopen('php://temp/maxmemory:2097152','w+');
        try {
            fwrite($stream,"\xEF\xBB\xBF"); // Excel-compatible UTF-8
            fputcsv($stream,$columns,';','"','');
            $count=0;
            foreach ($query->select($columns)->orderBy('id')->limit(5001)->cursor() as $row) {
                abort_if($count===5000,422,'Export limité à 5000 lignes : affinez votre filtre.');
                fputcsv($stream,array_map(
                    fn($column)=>self::escapeCsv($row->$column),
                    $columns
                ),';','"','');
                $count++;
            }
            rewind($stream);
            $csv=stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        Audit::record('crm.export.downloaded',null,[
            'resource'=>$resource,'row_count'=>$count,'filters_used'=>array_keys($filters),
        ]);
        return response($csv,200,[
            'Content-Type'=>'text/csv; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="atlas_crm_'.$resource.'.csv"',
            'Cache-Control'=>'no-store, private',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }
}
