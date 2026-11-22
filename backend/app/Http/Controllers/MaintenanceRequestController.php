<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceAsset;
use App\Models\MaintenanceRequest;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MaintenanceRequestController extends Controller {
    public function index() {
        Gate::authorize('admin');
        return MaintenanceRequest::query()->latest('id')->paginate(20);
    }
    public function store(Request $request) {
        Gate::authorize('admin');
        $data=$request->validate([
            'asset_id'=>'required|integer|min:1',
            'reference'=>['required','string','max:60',Rule::unique('maintenance_requests')->where('company_id',app(TenantContext::class)->requireId())],
            'title'=>'required|string|max:180',
            'description'=>'nullable|string|max:2000',
            'priority'=>['required',Rule::in(['low','normal','high','critical'])],
            'status'=>'prohibited',
            'created_by'=>'prohibited',
            'company_id'=>'prohibited',
        ]);
        MaintenanceAsset::query()->findOrFail($data['asset_id']);
        return DB::transaction(function()use($request,$data){
            $item=MaintenanceRequest::create(array_merge($data,[
                'status'=>'new','created_by'=>$request->user()->id,
            ]));
            Audit::record('maintenance.request.created',$item);
            return response()->json($item,201);
        });
    }
    public function qualify(int $id) {
        Gate::authorize('admin');
        return DB::transaction(function()use($id){
            $item=MaintenanceRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless($item->status==='new',409,'Demande déjà traitée.');
            $item->status='qualified';
            $item->save();
            Audit::record('maintenance.request.qualified',$item);
            return response()->json($item);
        });
    }
}
