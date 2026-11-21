<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RuntimeDbPrivilegeTest extends TestCase
{
    use RefreshDatabase;

    private function postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('F16 privilege boundaries require real PostgreSQL.');
        }
    }

    /**
     * A denied statement aborts a PostgreSQL transaction until the
     * savepoint is rolled back, so use a nested Laravel transaction.
     */
    private function denied(string $sql, string $sqlState = '42501'): void
    {
        try {
            DB::transaction(function () use ($sql) {
                DB::statement($sql);
            });
            $this->fail("Runtime role unexpectedly allowed SQL: {$sql}");
        } catch (QueryException $e) {
            $this->assertSame($sqlState, $e->errorInfo[0] ?? null);
        }
    }

    public function test_runtime_role_has_no_login_owner_or_schema_privileges(): void
    {
        $this->postgres();

        $role = DB::selectOne(<<<'SQL'
SELECT rolcanlogin::int AS can_login, rolsuper::int AS superuser,
       rolbypassrls::int AS bypass_rls, rolcreatedb::int AS create_db,
       rolcreaterole::int AS create_role,
       has_schema_privilege('atlas_app_runtime','public','CREATE')::int AS create_schema,
       has_table_privilege('atlas_app_runtime','public.migrations','SELECT')::int AS read_migrations,
       has_table_privilege('atlas_app_runtime','public.users','SELECT')::int AS read_users,
       has_table_privilege('atlas_app_runtime','public.companies','INSERT')::int AS create_company,
       has_table_privilege('atlas_app_runtime','public.support_incidents','UPDATE')::int AS update_incident
FROM pg_roles WHERE rolname = 'atlas_app_runtime'
SQL);

        $this->assertNotNull($role);
        $this->assertSame(0, (int) $role->can_login);
        $this->assertSame(0, (int) $role->superuser);
        $this->assertSame(0, (int) $role->bypass_rls);
        $this->assertSame(0, (int) $role->create_db);
        $this->assertSame(0, (int) $role->create_role);
        $this->assertSame(0, (int) $role->create_schema);
        $this->assertSame(0, (int) $role->read_migrations);
        $this->assertSame(1, (int) $role->read_users);
        $this->assertSame(1, (int) $role->create_company);
        $this->assertSame(1, (int) $role->update_incident);
    }

    public function test_restricted_runtime_can_perform_dml_but_cannot_modify_schema(): void
    {
        $this->postgres();

        $original = DB::selectOne('SELECT current_user AS role_name')->role_name;
        DB::transaction(function () {
            DB::statement('SET LOCAL ROLE atlas_app_runtime');
            try {
                $this->assertSame('atlas_app_runtime',
                    DB::selectOne('SELECT current_user AS role_name')->role_name);

                $this->assertGreaterThanOrEqual(1,
                    (int) DB::selectOne('SELECT COUNT(*) AS n FROM public.companies')->n);

                // This exercises table INSERT and generated sequence USAGE.
                $created = DB::table('companies')->insertGetId([
                    'code' => 'F16_DML_TEST',
                    'name' => 'F16 fictitious, discarded after PHPUnit rollback',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->assertGreaterThan(0, $created);

                $this->denied('SELECT COUNT(*) FROM public.migrations');
                $this->denied('CREATE TABLE public.atlas_f16_unauthorized (id integer)');
                $this->denied('ALTER TABLE public.companies ADD COLUMN f16_unauthorized integer');
                $this->denied('DROP TABLE public.companies');
            } finally {
                DB::statement('RESET ROLE');
            }
        });

        $this->assertSame($original,
            DB::selectOne('SELECT current_user AS role_name')->role_name);
        $this->assertDatabaseHas('companies', ['code' => 'F16_DML_TEST']);
    }

    public function test_runtime_can_use_n3_reader_without_inheriting_raw_user_access(): void
    {
        $this->postgres();

        $owner = DB::selectOne('SELECT current_user AS role_name')->role_name;
        DB::transaction(function () {
            DB::statement('SET LOCAL ROLE atlas_app_runtime');

            try {
                $this->assertSame(1, (int) DB::selectOne(<<<'SQL'
SELECT pg_has_role(current_user, 'atlas_f15_incident_reader', 'SET')::int AS can_set
SQL)->can_set);

                DB::statement('SET LOCAL ROLE atlas_f15_incident_reader');
                try {
                    $this->assertSame('atlas_f15_incident_reader',
                        DB::selectOne('SELECT current_user AS role_name')->role_name);

                    // No tenant selection means zero rows.
                    DB::selectOne("SELECT set_config('atlas.tenant_id','',true)");
                    $this->assertSame(0, (int) DB::selectOne(
                        'SELECT COUNT(*) AS n FROM public.support_incidents'
                    )->n);
                    $this->denied('SELECT COUNT(*) FROM public.users');
                    $this->denied('DELETE FROM public.support_incidents WHERE 1 = 0');
                } finally {
                    DB::statement('RESET ROLE');
                }
            } finally {
                // RESET ROLE restores the database session login, not the
                // intermediate role. All changes stay inside this transaction.
                DB::statement('RESET ROLE');
                DB::selectOne("SELECT set_config('atlas.tenant_id','',true)");
            }
        });
        $this->assertSame($owner,
            DB::selectOne('SELECT current_user AS role_name')->role_name);
    }
}
