<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceSite;
use App\Models\MaintenanceAsset;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class MaintenanceRegistryController extends Controller {
    public function sites() {
        Gate::authorize('admin');
        return MaintenanceSite::query()->orderBy('name')->paginate(20);
    }
    public function assets() {
        Gate::authorize('admin');
        return MaintenanceAsset::query()->orderBy('asset_code')->paginate(20);
    }
    public function createSite(Request $request) {
        Gate::authorize('admin');
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'code'=>['required','string','max:50',Rule::unique('maintenance_sites')->where('company_id',$company)],
            'name'=>'required|string|max:180',
            'active'=>'sometimes|boolean',
            'company_id'=>'prohibited',
        ]);
        return DB::transaction(function () use ($data) {
            $site = MaintenanceSite::create($data);
            Audit::record('maintenance.site.created',$site);
            return response()->json($site,201);
        });
    }
    public function createAsset(Request $request) {
        Gate::authorize('admin');
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'site_id'=>'required|integer|min:1',
            'asset_code'=>['required','string','max:60',Rule::unique('maintenance_assets')->where('company_id',$company)],
            'name'=>'required|string|max:180',
            'commissioned_on'=>'nullable|date_format:Y-m-d',
            'status'=>'prohibited',
            'company_id'=>'prohibited',
        ]);
        // A site from another company is deliberately indistinguishable from missing.
        MaintenanceSite::query()->findOrFail($data['site_id']);
        return DB::transaction(function () use ($data) {
            $asset = MaintenanceAsset::create(array_merge($data,['status'=>'in_service']));
            Audit::record('maintenance.asset.created',$asset);
            return response()->json($asset,201);
        });
    }
}
