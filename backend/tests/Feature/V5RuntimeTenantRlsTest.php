<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class V5RuntimeTenantRlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_has_v5_business_access_but_never_cross_tenant_access(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL RLS integration only.');
        }
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail()->id;
        $b = Company::create(['code' => 'V5_RLS_B', 'name' => 'Fictive second tenant'])->id;
        DB::table('maintenance_sites')->insert([
            ['company_id' => $a, 'code' => 'RLS_A', 'name' => 'Tenant A site',
             'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $b, 'code' => 'RLS_B', 'name' => 'Tenant B site',
             'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::transaction(function () use ($a, $b) {
            DB::statement('SET LOCAL ROLE atlas_app_runtime');
            try {
                DB::selectOne("SELECT set_config('atlas.tenant_id', ?, true)", [(string) $a]);
                $this->assertSame(1, (int) DB::table('maintenance_sites')->whereIn(
                    'code', ['RLS_A', 'RLS_B'])->count());
                $this->assertSame(0, (int) DB::table('maintenance_sites')->where('code', 'RLS_B')->count());

                // Direct SQL cannot forge the company_id even when an API
                // controller is bypassed. The savepoint rolls back the error.
                try {
                    DB::transaction(function () use ($b) {
                        DB::table('maintenance_sites')->insert([
                            'company_id' => $b, 'code' => 'RLS_FOREIGN',
                            'name' => 'Invalid', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    });
                    $this->fail('Cross-tenant write unexpectedly succeeded.');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0] ?? $e->getCode());
                }
                foreach ([
                    'purchase_requests', 'maintenance_assets', 'maintenance_requests',
                    'maintenance_work_orders', 'hotel_properties', 'hotel_rooms',
                    'hotel_bookings', 'data_source_registry', 'data_ingestion_batches',
                    'data_ingested_csv_rows',
                ] as $table) {
                    $this->assertTrue(DB::table($table)->limit(1)->get() instanceof
                        \Illuminate\Support\Collection, "Runtime SELECT failed: {$table}");
                    $this->assertSame(0, (int) DB::selectOne(
                        "SELECT has_table_privilege(current_user, ?, 'DELETE')::int AS allowed",
                        ['public.'.$table]
                    )->allowed);
                }
            } finally {
                DB::statement('RESET ROLE');
                DB::selectOne("SELECT set_config('atlas.tenant_id', '', true)");
            }
        });
    }
}
