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
    public function index() {
        Gate::authorize('admin');
        return DataIngestionBatch::query()->latest('id')->paginate(20);
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
