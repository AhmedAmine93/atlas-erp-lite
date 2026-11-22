<?php

namespace App\Http\Controllers;

use App\Models\DataSourceRegistry;
use App\Models\DataIngestionBatch;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DataIngestionBatchController extends Controller {
    /**
     * Page-bounded data-quality telemetry: aggregates only persisted CSV rows
     * in the verified tenant, never exposes external_id or raw uploaded data.
     *
     * Each statistic is calculated from the actual stored rows (not the
     * declared manifest). A registered lot is PENDING, not an empty success.
     */
    public function index() {
        Gate::authorize('admin');
        $page = DataIngestionBatch::query()->latest('id')->paginate(20);
        $ids = $page->getCollection()->pluck('id')->all();
        if ($ids === []) {
            return $page;
        }

        $tenantId = app(TenantContext::class)->requireId();
        $stats = DB::table('data_ingested_csv_rows')
            ->where('company_id', $tenantId)
            ->whereIn('batch_id', $ids)
            ->selectRaw(
                'batch_id, COUNT(*) AS actual_rows, '.
                'COALESCE(SUM(amount_cents), 0) AS amount_total_cents, '.
                'SUM(CASE WHEN amount_cents > 0 THEN 1 ELSE 0 END) AS positive_rows, '.
                'SUM(CASE WHEN amount_cents < 0 THEN 1 ELSE 0 END) AS negative_rows, '.
                'SUM(CASE WHEN amount_cents = 0 THEN 1 ELSE 0 END) AS zero_rows'
            )
            ->groupBy('batch_id')
            ->get()
            ->keyBy('batch_id');

        foreach ($page->getCollection() as $batch) {
            $aggregate = $stats->get($batch->id);
            $actual = (int) ($aggregate?->actual_rows ?? 0);
            $processed = $batch->status === 'processed';
            $matches = $processed ? $actual === (int) $batch->expected_rows : null;

            $batch->setAttribute('quality_summary', [
                'state' => ! $processed ? 'PENDING'
                    : ($matches ? 'CONSISTENT' : 'ROW_COUNT_MISMATCH'),
                'actual_rows' => $actual,
                'expected_rows' => (int) $batch->expected_rows,
                'row_count_matches_manifest' => $matches,
                'amount_total_cents' => (int) ($aggregate?->amount_total_cents ?? 0),
                'positive_rows' => (int) ($aggregate?->positive_rows ?? 0),
                'negative_rows' => (int) ($aggregate?->negative_rows ?? 0),
                'zero_rows' => (int) ($aggregate?->zero_rows ?? 0),
            ]);
        }

        return $page;
    }
    public function store(Request $request) {
        Gate::authorize('admin');
        $data=$request->validate([
            'source_id'=>'required|integer|min:1',
            'batch_key'=>'required|string|max:100|regex:/^[A-Za-z0-9_.:-]+$/',
            'sha256'=>'required|string|size:64|regex:/^[a-f0-9]{64}$/',
            'expected_rows'=>'required|integer|min:0|max:100000000',
            'status'=>'prohibited',
            'company_id'=>'prohibited',
            'created_by'=>'prohibited',
            'payload'=>'prohibited',
            'credentials'=>'prohibited',
        ]);
        $source=DataSourceRegistry::query()->findOrFail($data['source_id']);
        abort_unless($source->status==='validated',409,'Source non validée pour ingestion.');
        return DB::transaction(function()use($request,$data){
            // UNIQUE(company_id,source_id,batch_key) + insertOrIgnore provide
            // a safe winning insert under concurrent duplicate submissions.
            // Do not rely on locking an absent row: that cannot serialize
            // concurrent writers in PostgreSQL READ COMMITTED.
            $created=DB::table('data_ingestion_batches')->insertOrIgnore([
                'company_id'=>app(TenantContext::class)->requireId(),
                'source_id'=>$data['source_id'],
                'batch_key'=>$data['batch_key'],
                'sha256'=>$data['sha256'],
                'expected_rows'=>$data['expected_rows'],
                'status'=>'registered',
                'created_by'=>$request->user()->id,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
            $batch=DataIngestionBatch::query()
                ->where('source_id',$data['source_id'])
                ->where('batch_key',$data['batch_key'])->firstOrFail();
            abort_unless(hash_equals($batch->sha256,$data['sha256'])
                && (int)$batch->expected_rows===(int)$data['expected_rows'],
                409,'Clé de lot déjà utilisée avec un contenu différent.');
            if ($created) Audit::record('data.batch.registered',$batch);
            return response()->json($batch,$created ? 201 : 200);
        });
    }
}
