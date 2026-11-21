<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class PurchaseRequestController extends Controller {
    public function index(Request $request) {
        Gate::authorize('admin');
        $request->validate(['page'=>'sometimes|integer|min:1']);
        return PurchaseRequest::query()->latest('id')->paginate(20);
    }

    public function store(Request $request) {
        Gate::authorize('admin');
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'reference' => ['required','string','max:50',Rule::unique('purchase_requests')->where('company_id',$company)],
            'title' => ['required','string','max:180'],
            'justification' => ['nullable','string','max:2000'],
            'estimated_total_cents' => ['required','integer','min:0','max:100000000000'],
            'currency' => ['sometimes','string','size:3',Rule::in(['EUR','USD','GBP'])],
            'status' => ['prohibited'],
            'requested_by' => ['prohibited'],
            'company_id' => ['prohibited'],
        ]);
        return DB::transaction(function () use ($request,$data) {
            $record = PurchaseRequest::create(array_merge($data, [
                'requested_by' => $request->user()->id, 'status' => 'draft',
            ]));
            Audit::record('purchase.request.created', $record);
            return response()->json($record, 201);
        });
    }
}
