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
     * Only the original requester may submit. This is not a financial commitment.
     * Pessimistic locking prevents duplicate transitions on concurrent requests.
     */
    public function submit(Request $request, int $id) {
        Gate::authorize('admin');
        return DB::transaction(function () use ($request, $id) {
            $record = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $record->requested_by === (int) $request->user()->id, 403);
            abort_unless($record->status === 'draft', 409, 'La demande ne peut plus être soumise.');
            abort_unless((int) $record->estimated_total_cents > 0, 422, 'Un budget positif est requis.');
            $record->update(['status' => 'submitted', 'submitted_at' => now()]);
            $this->event($record, 'draft', 'submitted', $request->user()->id);
            Audit::record('purchase.request.submitted', $record);
            return response()->json($record);
        });
    }

    /** A different administrator must approve or reject a submitted request. */
    public function decision(Request $request, int $id) {
        Gate::authorize('admin');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'company_id' => ['prohibited'],
            'actor_id' => ['prohibited'],
        ]);
        return DB::transaction(function () use ($request, $id, $data) {
            $record = PurchaseRequest::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'submitted', 409, 'Demande déjà traitée ou non soumise.');
            abort_if((int) $record->requested_by === (int) $request->user()->id, 403, 'Auto-approbation interdite.');
            $record->update(['status' => $data['decision']]);
            $this->event($record, 'submitted', $data['decision'], $request->user()->id, $data['reason']);
            Audit::record('purchase.request.'.$data['decision'], $record);
            return response()->json($record);
        });
    }

    public function history(int $id) {
        Gate::authorize('admin');
        $record = PurchaseRequest::query()->findOrFail($id);
        return DB::table('purchase_approval_events')
            ->where('company_id', $record->company_id)
            ->where('purchase_request_id', $record->id)
            ->orderBy('id')
            ->get(['id', 'from_status', 'to_status', 'actor_id', 'reason', 'created_at']);
    }

    private function event(PurchaseRequest $record, string $from, string $to, int $actor, ?string $reason = null): void {
        DB::table('purchase_approval_events')->insert([
            'company_id' => $record->company_id,
            'purchase_request_id' => $record->id,
            'from_status' => $from, 'to_status' => $to,
            'actor_id' => $actor, 'reason' => $reason, 'created_at' => now(),
        ]);
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
