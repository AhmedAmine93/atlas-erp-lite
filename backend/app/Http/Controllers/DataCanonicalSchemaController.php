<?php

namespace App\Http\Controllers;

use App\Models\DataCanonicalSchema;
use App\Models\DataSourceRegistry;
use App\Services\Audit;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class DataCanonicalSchemaController extends Controller
{
    private const TYPES = [
        'string', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'money_cents',
    ];

    public function index(int $sourceId)
    {
        Gate::authorize('admin');
        DataSourceRegistry::query()->findOrFail($sourceId);
        return DataCanonicalSchema::query()
            ->where('source_id', $sourceId)
            ->orderByDesc('version')
            ->paginate(20);
    }

    /** Immutable declarations only; never executes SQL, API calls or uploaded code. */
    public function store(Request $request, int $sourceId)
    {
        Gate::authorize('admin');
        $source = DataSourceRegistry::query()->findOrFail($sourceId);
        $company = app(TenantContext::class)->requireId();
        $data = $request->validate([
            'version' => [
                'required', 'integer', 'min:1', 'max:1000000',
                Rule::unique('data_canonical_schemas', 'version')
                    ->where('company_id', $company)->where('source_id', $source->id),
            ],
            'name' => ['required', 'string', 'max:120'],
            'fields' => ['required', 'array', 'min:1', 'max:32'],
            'fields.*' => ['required', 'array:key,type,required,pii'],
            'fields.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct'],
            'fields.*.type' => ['required', Rule::in(self::TYPES)],
            'fields.*.required' => ['required', 'boolean'],
            'fields.*.pii' => ['required', 'boolean'],
            'company_id' => ['prohibited'],
            'source_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'sql' => ['prohibited'],
            'expression' => ['prohibited'],
            'password' => ['prohibited'],
            'token' => ['prohibited'],
            'endpoint' => ['prohibited'],
        ]);

        if ($source->classification === 'public' &&
            collect($data['fields'])->contains(fn ($f) => (bool) $f['pii'])) {
            throw ValidationException::withMessages([
                'fields' => 'Une source publique ne peut pas déclarer de champ personnel.',
            ]);
        }

        $fields = array_map(static fn ($field) => [
            'key' => $field['key'],
            'type' => $field['type'],
            'required' => (bool) $field['required'],
            'pii' => (bool) $field['pii'],
        ], $data['fields']);

        return DB::transaction(function () use ($request, $source, $data, $fields) {
            $record = DataCanonicalSchema::create([
                'source_id' => $source->id,
                'version' => $data['version'],
                'name' => $data['name'],
                'fields' => $fields,
                'fingerprint' => hash('sha256', json_encode([
                    'version' => (int) $data['version'], 'fields' => $fields,
                ], JSON_THROW_ON_ERROR)),
                'created_by' => $request->user()->id,
            ]);
            Audit::record('data.canonical_schema.created', $record);
            return response()->json($record, 201);
        });
    }
}
