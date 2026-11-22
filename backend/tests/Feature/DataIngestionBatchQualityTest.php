<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DataIngestionBatchQualityTest extends TestCase
{
    use RefreshDatabase;

    private function login(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name' => 'Data quality synthetic operator',
            'email' => 'quality-'.$suffix.'@example.test',
            'password' => 'SyntheticPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    private function batch(string $code, string $key, string $csv, int $expected): int
    {
        $source = $this->postJson('/api/data/sources', [
            'code' => $code,
            'name' => 'Synthetic source '.$code,
            'source_type' => 'csv',
            'classification' => 'internal',
        ])->assertCreated()->json('id');
        $this->postJson("/api/data/sources/$source/validate")->assertOk();

        return $this->postJson('/api/data/ingestion-batches', [
            'source_id' => $source,
            'batch_key' => $key,
            'sha256' => hash('sha256', $csv),
            'expected_rows' => $expected,
        ])->assertCreated()->json('id');
    }

    public function test_quality_stats_are_derived_from_persisted_rows_and_pending_is_not_success(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->login($a, 'admin', 'a');

        $csv = "external_id,amount_cents\nid-1,1200\nid-2,-200\nid-3,0\n";
        $processed = $this->batch('QUALITY_OK', 'quality-1', $csv, 3);
        $this->post('/api/data/ingestion-batches/'.$processed.'/ingest-csv', [
            'file' => UploadedFile::fake()->createWithContent('quality.csv', $csv),
        ])->assertOk();

        $pendingCsv = "external_id,amount_cents\nid-4,550\n";
        $pending = $this->batch('QUALITY_PENDING', 'quality-2', $pendingCsv, 1);
        $rows = collect($this->getJson('/api/data/ingestion-batches')->assertOk()->json('data'));
        $actual = $rows->firstWhere('id', $processed);
        $this->assertNotNull($actual);
        $this->assertSame('CONSISTENT', $actual['quality_summary']['state']);
        $this->assertSame(3, $actual['quality_summary']['actual_rows']);
        $this->assertSame(3, $actual['quality_summary']['expected_rows']);
        $this->assertTrue($actual['quality_summary']['row_count_matches_manifest']);
        $this->assertSame(1000, $actual['quality_summary']['amount_total_cents']);
        $this->assertSame(1, $actual['quality_summary']['positive_rows']);
        $this->assertSame(1, $actual['quality_summary']['negative_rows']);
        $this->assertSame(1, $actual['quality_summary']['zero_rows']);
        $this->assertArrayNotHasKey('external_id', $actual['quality_summary']);

        $unprocessed = $rows->firstWhere('id', $pending);
        $this->assertNotNull($unprocessed);
        $this->assertSame('PENDING', $unprocessed['quality_summary']['state']);
        $this->assertNull($unprocessed['quality_summary']['row_count_matches_manifest']);
        $this->assertSame(0, $unprocessed['quality_summary']['actual_rows']);

        // An altered persisted batch must report a discrepancy, not a
        // fabricated success based on its declared manifest.
        DB::table('data_ingested_csv_rows')->where('batch_id', $processed)
            ->where('external_id', 'id-3')->delete();
        $rows = collect($this->getJson('/api/data/ingestion-batches')->assertOk()->json('data'));
        $changed = $rows->firstWhere('id', $processed);
        $this->assertSame('ROW_COUNT_MISMATCH', $changed['quality_summary']['state']);
        $this->assertFalse($changed['quality_summary']['row_count_matches_manifest']);
        $this->assertSame(2, $changed['quality_summary']['actual_rows']);
    }

    public function test_quality_summary_is_access_controlled_and_company_scoped(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'QUALITY_TENANT_B', 'name' => 'Tenant B synthetic']);
        $this->getJson('/api/data/ingestion-batches')->assertUnauthorized();

        $this->login($a, 'admin', 'admina');
        $csvA = "external_id,amount_cents\na-1,1200\n";
        $batchA = $this->batch('QUALITY_A', 'a', $csvA, 1);
        $this->post('/api/data/ingestion-batches/'.$batchA.'/ingest-csv', [
            'file' => UploadedFile::fake()->createWithContent('a.csv', $csvA),
        ])->assertOk();

        $this->login($b, 'admin', 'adminb');
        $this->getJson('/api/data/ingestion-batches')->assertOk()
            ->assertJsonPath('total', 0);
        $csvB = "external_id,amount_cents\nb-1,250000\n";
        $batchB = $this->batch('QUALITY_B', 'b', $csvB, 1);
        $this->post('/api/data/ingestion-batches/'.$batchB.'/ingest-csv', [
            'file' => UploadedFile::fake()->createWithContent('b.csv', $csvB),
        ])->assertOk();

        $response = $this->getJson('/api/data/ingestion-batches')->assertOk();
        $response->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $batchB)
            ->assertJsonPath('data.0.quality_summary.amount_total_cents', 250000);
        $this->assertStringNotContainsString('"a-1"', $response->getContent());
        $this->assertStringNotContainsString('"batch_key":"a"', $response->getContent());

        $this->login($a, 'reader', 'readera');
        $this->getJson('/api/data/ingestion-batches')->assertForbidden();
    }
}
