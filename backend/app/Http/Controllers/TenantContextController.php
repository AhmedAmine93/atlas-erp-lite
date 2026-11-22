<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\TenantDatabaseContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantContextController extends Controller
{
    public function switch(Request $request)
    {
        $data = $request->validate([
            'company_id' => [
                'required', 'integer',
                Rule::exists('company_user', 'company_id')->where('user_id', $request->user()->id)->whereNull('suspended_at'),
            ],
        ]);
        $oldCompanyId = (int) $request->session()->get('active_company_id');
        $newCompanyId = (int) $data['company_id'];

        // ResolveTenant already verified membership and opened a tenant-
        // scoped transaction. Rebind immediately for writes in this request.
        TenantDatabaseContext::set($newCompanyId);
        $request->session()->put('active_company_id', $newCompanyId);
        $request->attributes->set('atlas_company_id', $newCompanyId);
        Audit::record('tenant.switched', null, ['from' => $oldCompanyId, 'to' => $newCompanyId]);

        return response()->json([
            'active_company_id' => $newCompanyId,
            'company' => DB::table('companies')->select('id', 'name', 'code')->find($newCompanyId),
        ]);
    }
}
