<?php

namespace App\Http\Controllers;

use App\Services\Audit;
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
                Rule::exists('company_user', 'company_id')->where('user_id', $request->user()->id),
            ],
        ]);
        $oldCompanyId = (int) $request->session()->get('active_company_id');
        $newCompanyId = (int) $data['company_id'];

        $request->session()->put('active_company_id', $newCompanyId);
        $request->attributes->set('atlas_company_id', $newCompanyId);
        Audit::record('tenant.switched', null, ['from' => $oldCompanyId, 'to' => $newCompanyId]);

        return response()->json([
            'active_company_id' => $newCompanyId,
            'company' => DB::table('companies')->select('id', 'name', 'code')->find($newCompanyId),
        ]);
    }
}
