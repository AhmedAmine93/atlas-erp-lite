<?php

namespace App\Http\Controllers;

use App\Models\DataSourceRegistry;
use App\Models\DataIngestionBatch;
use App\Services\Audit;
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
            $previous=DataIngestionBatch::query()->where('source_id',$data['source_id'])
                ->where('batch_key',$data['batch_key'])->lockForUpdate()->first();
            if ($previous) {
                abort_unless(hash_equals($previous->sha256,$data['sha256'])
                    && (int)$previous->expected_rows===(int)$data['expected_rows'],
                    409,'Clé de lot déjà utilisée avec un contenu différent.');
                return response()->json($previous);
            }
            $batch=DataIngestionBatch::create(array_merge($data,[
                'status'=>'registered','created_by'=>$request->user()->id,
            ]));
            Audit::record('data.batch.registered',$batch);
            return response()->json($batch,201);
        });
    }
}
