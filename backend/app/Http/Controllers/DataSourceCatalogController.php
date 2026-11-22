<?php

namespace App\Http\Controllers;

use App\Models\DataSourceRegistry;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class DataSourceCatalogController extends Controller {
    public function index() {
        Gate::authorize('admin');
        return DataSourceRegistry::query()
            ->select('id','code','name','source_type','classification','status','schema_version','purpose','created_at')
            ->orderBy('code')->paginate(20);
    }
    public function validateSource(int $id) {
        Gate::authorize('admin');
        return DB::transaction(function()use($id){
            $source=DataSourceRegistry::query()->lockForUpdate()->findOrFail($id);
            abort_unless($source->status==='draft',409,'Source déjà traitée.');
            $source->status='validated';
            $source->save();
            Audit::record('data.source.validated',$source);
            return response()->json($source);
        });
    }

    public function store(Request $request) {
        Gate::authorize('admin');
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'code'=>['required','string','max:60',Rule::unique('data_source_registry')->where('company_id',$company)],
            'name'=>'required|string|max:180',
            'source_type'=>['required',Rule::in(['api','postgresql','oracle','csv','pms','kafka'])],
            'classification'=>['required',Rule::in(['public','internal','confidential','restricted'])],
            'schema_version'=>'nullable|string|max:40',
            'purpose'=>'nullable|string|max:1000',
            // Reject credentials, URLs, arbitrary connection strings and SQL.
            'status'=>'prohibited',
            'company_id'=>'prohibited',
            'credentials'=>'prohibited',
            'password'=>'prohibited',
            'token'=>'prohibited',
            'endpoint'=>'prohibited',
            'connection_string'=>'prohibited',
        ]);
        return DB::transaction(function () use ($data) {
            $source = DataSourceRegistry::create(array_merge($data,['status'=>'draft']));
            Audit::record('data.source.registered',$source);
            return response()->json($source,201);
        });
    }
}
