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

    /**
     * Every transition is serialized on its row. Only a submitted request
     * may be approved; creator and approver must be different users.
     */
    public function submit(int $id) {
        Gate::authorize('admin');
        return DB::transaction(function () use ($id) {
            $item = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless($item->status === 'draft', 409, 'La demande n’est plus en brouillon.');
            $item->status = 'submitted';
            $item->submitted_at = now();
            $item->save();
            Audit::record('purchase.request.submitted', $item);
            return response()->json($item);
        });
    }

    public function approve(Request $request, int $id) {
        Gate::authorize('admin');
        return DB::transaction(function () use ($request,$id) {
            $item = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless($item->status === 'submitted', 409, 'Seule une demande soumise peut être approuvée.');
            abort_if((int) $item->requested_by === (int) $request->user()->id,
                403, 'Le demandeur ne peut pas approuver sa propre demande.');
            // Explicit limits apply when the tenant configures delegations for this currency.
            // Legacy companies without any policy keep their existing four-eyes workflow.
            $tenant=app(TenantContext::class)->requireId();
            $policies=DB::table('purchase_approval_delegations')
                ->where('company_id',$tenant)->where('currency',$item->currency);
            if((clone $policies)->exists()){
                $grant=(clone $policies)->where('user_id',$request->user()->id)
                    ->where('active',true)
                    ->where(function($query){
                        $query->whereNull('valid_until')->orWhereDate('valid_until','>=',now()->toDateString());
                    })->lockForUpdate()->first();
                abort_unless($grant && (int)$grant->max_amount_cents >= (int)$item->estimated_total_cents,
                    403, 'Plafond de délégation insuffisant ou expiré.');
            }
            $item->status = 'approved';
            $item->approved_by = $request->user()->id;
            $item->approved_at = now();
            $item->decided_by = $request->user()->id;
            $item->decided_at = now();
            $item->decision_reason = null;
            $item->save();
            Audit::record('purchase.request.approved',$item);
            return response()->json($item);
        });
    }

    /** Return a submitted request with a mandatory decision reason. */
    public function reject(Request $request, int $id)
    {
        Gate::authorize('admin');
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        return DB::transaction(function () use ($request, $id, $data) {
            $item = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless($item->status === 'submitted', 409, 'Seule une demande soumise peut être refusée.');
            abort_if((int) $item->requested_by === (int) $request->user()->id,
                403, 'Décision par un autre administrateur requise.');
            $item->status = 'rejected';
            $item->decision_reason = $data['reason'];
            $item->decided_by = $request->user()->id;
            $item->decided_at = now();
            $item->save();
            Audit::record('purchase.request.rejected', $item);
            return response()->json($item);
        });
    }

    /** Changing a submitted/rejected request invalidates the old decision. */
    public function revise(Request $request, int $id)
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:180',
            'justification' => 'sometimes|nullable|string|max:2000',
            'estimated_total_cents' => 'sometimes|required|integer|min:0|max:100000000000',
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['EUR','USD','GBP'])],
            'company_id' => 'prohibited',
            'requested_by' => 'prohibited',
            'status' => 'prohibited',
            'approved_by' => 'prohibited',
            'approved_at' => 'prohibited',
        ]);
        abort_if($data === [], 422, 'Aucune modification.');
        return DB::transaction(function () use ($request, $id, $data) {
            $item = PurchaseRequest::query()->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($item->status, ['draft', 'submitted', 'rejected'], true),
                409, 'Une demande approuvée ne peut pas être modifiée.');
            abort_unless((int) $item->requested_by === (int) $request->user()->id,
                403, 'Seul le demandeur peut réviser.');
            $item->fill($data);
            $item->status = 'draft';
            $item->submitted_at = null;
            $item->approved_by = null;
            $item->approved_at = null;
            $item->decision_reason = null;
            $item->decided_by = null;
            $item->decided_at = null;
            $item->revision = (int) $item->revision + 1;
            $item->save();
            Audit::record('purchase.request.revised', $item);
            return response()->json($item);
        });
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
