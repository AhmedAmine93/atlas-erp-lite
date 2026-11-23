<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Synthetic/customer-provided CSV with strict schema, bounded parsing,
 * per-row rejection and digest-based replay. No external connectors.
 */
final class CrmCustomerImportController extends Controller
{
    public function store(Request $request)
    {
        Gate::authorize('crm');
        $request->validate(['file'=>'required|file|max:1024|mimes:csv,txt']);
        $contents=file_get_contents($request->file('file')->getRealPath());
        abort_unless(is_string($contents) && strlen($contents)<=1048576,422,'CSV trop volumineux.');
        abort_unless(mb_check_encoding($contents,'UTF-8'),422,'CSV non UTF-8.');
        $company=app(TenantContext::class)->requireId();
        $digest=hash('sha256',$contents);
        $previous=DB::table('crm_import_batches')->where('company_id',$company)->where('sha256',$digest)->first();
        if ($previous) {
            return response()->json([
                'id'=>$previous->id,'accepted_rows'=>$previous->accepted_rows,
                'rejected_rows'=>$previous->rejected_rows,
                'rejections'=>json_decode($previous->rejections,true),'replayed'=>true,
            ]);
        }

        $stream=fopen('php://temp','w+');
        fwrite($stream,$contents);rewind($stream);
        $header=fgetcsv($stream,0,',','"','');
        if ($header && isset($header[0]))$header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);
        if ($header!==['name','email','phone']) {
            fclose($stream);
            abort(422,'En-tête exigé : name,email,phone');
        }
        $valid=[];$errors=[];$line=1;
        while (($fields=fgetcsv($stream,0,',','"',''))!==false) {
            $line++;
            if ($line>1001){fclose($stream);abort(422,'Limite de 1000 lignes.');}
            if ($fields===[null])continue;
            if (count($fields)!==3){
                $errors[]=['line'=>$line,'reason'=>'Nombre de colonnes invalide'];continue;
            }
            [$name,$email,$phone]=array_map('trim',$fields);
            $check=Validator::make(['name'=>$name,'email'=>$email,'phone'=>$phone],[
                'name'=>'required|string|max:255','email'=>'required|email|max:255',
                'phone'=>'nullable|string|max:40',
            ]);
            if($check->fails()){$errors[]=['line'=>$line,'reason'=>'Nom, email ou téléphone invalide'];continue;}
            $key=mb_strtolower($email);
            if(isset($valid[$key])){
                $errors[]=['line'=>$line,'reason'=>'Email déjà présent dans le même CSV'];continue;
            }
            $valid[$key]=['name'=>$name,'email'=>$email,'phone'=>$phone?:null];
        }
        fclose($stream);

        return DB::transaction(function()use($company,$digest,$valid,$errors,$request){
            $accepted=0;
            foreach($valid as $data){
                $existing=Customer::query()->where('email',$data['email'])->first();
                if($existing){
                    $errors[]=['email'=>$data['email'],'reason'=>'Client déjà existant : aucune écriture'];
                    continue;
                }
                $customer=Customer::create($data);
                Audit::record('crm.customer.imported',$customer);
                $accepted++;
            }
            $id=DB::table('crm_import_batches')->insertGetId([
                'company_id'=>$company,'sha256'=>$digest,'accepted_rows'=>$accepted,
                'rejected_rows'=>count($errors),'rejections'=>json_encode($errors,JSON_THROW_ON_ERROR),
                'actor_id'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            Audit::record('crm.customer.import.completed',null,['batch_id'=>$id,'accepted'=>$accepted,'rejected'=>count($errors)]);
            return response()->json(['id'=>$id,'accepted_rows'=>$accepted,
                'rejected_rows'=>count($errors),'rejections'=>$errors,'replayed'=>false],201);
        });
    }
}
