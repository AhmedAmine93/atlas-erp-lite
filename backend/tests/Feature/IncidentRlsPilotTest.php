<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\IncidentRlsReadProbe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IncidentRlsPilotTest extends TestCase
{
    use RefreshDatabase;

    private function pgOnly(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('F15 RLS security tests run on PostgreSQL only.');
        }
    }

    private function userFor(Company $company, string $email): User
    {
        $roleId = Role::where('name', 'support_n3')->value('id');
        $user = User::create([
            'name' => 'F15 actor', 'email' => $email,
            'password' => 'TestPassword2026!', 'role_id' => $roleId,
        ]);
        $user->companies()->attach($company->id, ['role_id' => $roleId]);

        return $user;
    }

    private function incident(Company $company, User $user, string $ref): int
    {
        return DB::table('support_incidents')->insertGetId([
            'company_id' => $company->id,
            'reference' => $ref,
            'title' => 'Fully synthetic SQL probe',
            'description' => 'Synthetic tenant data',
            'severity' => 'P2', 'status' => 'open',
            'environment' => 'demo',
            'reported_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_reader_role_is_read_only_and_policy_has_no_default_tenant(): void
    {
        $this->pgOnly();

        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $user = $this->userFor($a, 'rls-default@example.test');
        $this->incident($a, $user, 'INC-F15-DEFAULT');

        // The restricted role receives no tenant GUC until explicitly set.
        DB::transaction(function () {
            DB::statement('SET LOCAL ROLE atlas_f15_incident_reader');

            try {
                DB::selectOne("SELECT set_config('atlas.tenant_id', '', true)");
                $this->assertSame(
                    0,
                    (int) DB::selectOne('SELECT COUNT(*) AS n FROM public.support_incidents')->n
                );
                $this->assertSame('atlas_f15_incident_reader',
                    DB::selectOne('SELECT current_user AS role_name')->role_name);

                // Restricted SQL role cannot read users or write incidents.
                try {
                    DB::transaction(fn () => DB::selectOne('SELECT COUNT(*) FROM public.users'));
                    $this->fail('Restricted reader must not SELECT users.');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0] ?? null);
                }

                try {
                    DB::transaction(fn () => DB::table('support_incidents')
                        ->where('id', 1)->delete());
                    $this->fail('Restricted reader must not DELETE incidents.');
                } catch (QueryException $e) {
                    $this->assertSame('42501', $e->errorInfo[0] ?? null);
                }
            } finally {
                DB::statement('RESET ROLE');
                DB::selectOne("SELECT set_config('atlas.tenant_id', '', true)");
            }
        });
    }

    public function test_transaction_scoped_reader_sees_only_current_tenant_and_restores_context(): void
    {
        $this->pgOnly();
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'F15_B', 'name' => 'F15 Company B']);
        $ua = $this->userFor($a, 'rls-a@example.test');
        $ub = $this->userFor($b, 'rls-b@example.test');
        $this->incident($a, $ua, 'INC-F15-A1');
        $this->incident($a, $ua, 'INC-F15-A2');
        $this->incident($b, $ub, 'INC-F15-B1');

        $owner = DB::selectOne('SELECT current_user AS role_name')->role_name;
        $original = DB::selectOne("SELECT current_setting('atlas.tenant_id', true) AS tenant_id")
            ->tenant_id ?? '';

        $this->actingAs($ua)->withSession(['active_company_id' => $a->id]);
        $this->assertSame(2, app(IncidentRlsReadProbe::class)->metrics()['active_count']);
        $this->assertSame($owner, DB::selectOne('SELECT current_user AS role_name')->role_name);
        $this->assertSame($original,
            DB::selectOne("SELECT current_setting('atlas.tenant_id', true) AS tenant_id")->tenant_id ?? '');

        $this->actingAs($ub)->withSession(['active_company_id' => $b->id]);
        $this->assertSame(1, app(IncidentRlsReadProbe::class)->metrics()['active_count']);
        $this->assertSame($owner, DB::selectOne('SELECT current_user AS role_name')->role_name);

        // The external API still authorizes N3 and incident ownership.
        $id = DB::table('support_incidents')->where('reference', 'INC-F15-B1')->value('id');
        $this->postJson('/api/support/incidents/'.$id.'/escalate', [
            'reason' => 'Synthetic N3 investigation',
        ])->assertOk();
        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'tenant_incidents',
        ])->assertOk()->assertJsonPath('metrics.active_count', 1);
    }

    public function test_unauthorized_user_does_not_gain_sql_reader_context(): void
    {
        $this->pgOnly();
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $reader = User::create([
            'name' => 'F15 reader', 'email' => 'rls-reader@example.test',
            'password' => 'TestPassword2026!',
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);
        $reader->companies()->attach($a->id, [
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);
        $this->actingAs($reader);
        $this->postJson('/api/support/incidents/1/diagnostics', [
            'check' => 'tenant_incidents',
        ])->assertForbidden();
    }
}
