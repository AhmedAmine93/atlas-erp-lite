<?php

namespace App\Http\Controllers;

use App\Models\DataIngestionBatch;
use App\Models\DataSourceRegistry;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class DataCsvIngestionController extends Controller
{
    /**
     * First concrete, deliberately bounded CSV ingestion contract.
     * The registered SHA-256/expected_rows manifest is the immutable input.
     * Schema v1: external_id,amount_cents (integer). Raw files are not stored.
     */
    public function ingest(Request $request, int $id)
    {
        Gate::authorize('admin');
        $request->validate([
            'file' => 'required|file|max:1024',
            'source_id' => 'prohibited',
            'company_id' => 'prohibited',
            'status' => 'prohibited',
        ]);
        $file = $request->file('file');
        $actualHash = hash_file('sha256', $file->getRealPath());
        abort_unless($actualHash !== false, 422, 'Fichier illisible.');

        // Parse outside transaction; never use user CSV contents as SQL.
        $rows = $this->parse($file->getRealPath());
        return DB::transaction(function () use ($id, $rows, $actualHash) {
            $batch = DataIngestionBatch::query()->lockForUpdate()->findOrFail($id);
            $source = DataSourceRegistry::query()->findOrFail($batch->source_id);
            abort_unless($source->status === 'validated' && $source->source_type === 'csv',
                409, 'Source CSV non autorisée.');
            abort_unless(hash_equals($batch->sha256, $actualHash),
                409, 'Empreinte du lot différente du manifeste.');
            abort_unless((int) $batch->expected_rows === count($rows),
                409, 'Nombre de lignes différent du manifeste.');
            if ($batch->status === 'processed') {
                return response()->json($batch);
            }
            abort_unless($batch->status === 'registered', 409, 'Lot non ingérable.');
            foreach ($rows as $row) {
                DB::table('data_ingested_csv_rows')->insert([
                    'company_id' => $batch->company_id,
                    'batch_id' => $batch->id,
                    'external_id' => $row['external_id'],
                    'amount_cents' => $row['amount_cents'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $batch->status = 'processed';
            $batch->save();
            Audit::record('data.csv.ingested', $batch);
            return response()->json($batch);
        }, 3);
    }

    private function parse(string $path): array
    {
        $handle = fopen($path, 'rb');
        abort_unless($handle !== false, 422, 'Fichier illisible.');
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header !== ['external_id','amount_cents']) {
                throw ValidationException::withMessages([
                    'file' => 'En-tête attendu: external_id,amount_cents.',
                ]);
            }
            $rows = [];
            $seen = [];
            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($fields === [null]) {
                    continue;
                }
                if (count($fields) !== 2 || !is_string($fields[0]) ||
                    !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/D', $fields[0]) ||
                    !is_string($fields[1]) || !preg_match('/^-?[0-9]{1,12}$/D', $fields[1])) {
                    throw ValidationException::withMessages(['file' => 'Ligne CSV invalide.']);
                }
                if (isset($seen[$fields[0]])) {
                    throw ValidationException::withMessages(['file' => 'Identifiant dupliqué.']);
                }
                $seen[$fields[0]] = true;
                $rows[] = ['external_id' => $fields[0], 'amount_cents' => (int) $fields[1]];
                if (count($rows) > 10000) {
                    throw ValidationException::withMessages(['file' => 'Lot trop volumineux.']);
                }
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
