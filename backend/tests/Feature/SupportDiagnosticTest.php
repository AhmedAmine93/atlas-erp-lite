<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\SupportDiagnosticRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email, Company $company, string $membershipRole, string $globalRole = 'reader'): User
    {
        $user = User::create([
            'name' => $email, 'email' => $email, 'password' => 'TestPassword2026!',
            'role_id' => Role::where('name', $globalRole)->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $membershipRole)->value('id'),
        ]);

        return $user;
    }

    private function createN3Incident(User $user): int
    {
        $this->actingAs($user);
        $id = $this->postJson('/api/support/incidents', [
            'title' => 'Synthetic N3 incident',
            'description' => 'No real customer data or credentials.',
            'severity' => 'P2', 'environment' => 'demo',
        ])->assertCreated()->json('id');

        $this->postJson('/api/support/incidents/'.$id.'/escalate', [
            'reason' => 'Requires N3 read-only investigation',
        ])->assertOk();

        return (int) $id;
    }

    public function test_only_active_tenant_n3_or_admin_can_run_diagnostics(): void
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->postJson('/api/support/incidents/1/diagnostics', ['check' => 'database'])
            ->assertUnauthorized();

        $n2 = $this->member('n2-diagnostics@example.test', $company, 'support_n2', 'admin');
        $incidentId = $this->createN3Incident($n2);
        $this->postJson('/api/support/incidents/'.$incidentId.'/diagnostics', [
            'check' => 'database',
        ])->assertForbidden();
        $this->getJson('/api/support/incidents/'.$incidentId.'/diagnostics')->assertForbidden();

        $n3 = $this->member('n3-diagnostics@example.test', $company, 'support_n3');
        $this->actingAs($n3);
        $this->postJson('/api/support/incidents/'.$incidentId.'/diagnostics', [
            'check' => 'database',
        ])->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonPath('metrics.reachable', true);
        $this->getJson('/api/support/incidents/'.$incidentId.'/diagnostics')
            ->assertOk()->assertJsonCount(1);

        $admin = $this->member('admin-diagnostics@example.test', $company, 'admin');
        $this->actingAs($admin);
        $this->postJson('/api/support/incidents/'.$incidentId.'/diagnostics', [
            'check' => 'cache',
        ])->assertOk()->assertJsonPath('metrics.readable', true);
        $this->assertDatabaseCount('support_diagnostic_runs', 2);
    }

    public function test_unknown_check_injected_sql_and_extra_fields_are_rejected_without_execution(): void
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $n3 = $this->member('n3-validation@example.test', $company, 'support_n3');
        $id = $this->createN3Incident($n3);

        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'DROP TABLE companies',
        ])->assertUnprocessable();
        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'database', 'sql' => 'SELECT * FROM users',
        ])->assertUnprocessable();
        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'cache', 'host' => 'localhost',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('support_diagnostic_runs', 0);
        $this->assertNotNull(Company::find($company->id));
    }

    public function test_diagnostics_are_tenant_scoped_and_do_not_reveal_incident_details(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'DIAG_COMPANY_B', 'name' => 'Diagnostic B']);
        $n3A = $this->member('n3-a-diagnostics@example.test', $a, 'support_n3');
        $n3B = $this->member('n3-b-diagnostics@example.test', $b, 'support_n3');

        $idA = $this->createN3Incident($n3A);
        $this->postJson('/api/support/incidents/'.$idA.'/diagnostics', [
            'check' => 'tenant_incidents',
        ])->assertOk()->assertJsonPath('metrics.active_count', 1)
            ->assertDontSee('Synthetic N3 incident');

        $this->actingAs($n3B)->withSession(['active_company_id' => $b->id]);
        $this->getJson('/api/support/incidents/'.$idA.'/diagnostics')->assertNotFound();
        $this->postJson('/api/support/incidents/'.$idA.'/diagnostics', [
            'check' => 'database',
        ])->assertNotFound();

        $idB = $this->createN3Incident($n3B);
        $this->postJson('/api/support/incidents/'.$idB.'/diagnostics', [
            'check' => 'tenant_incidents',
        ])->assertOk()->assertJsonPath('metrics.active_count', 1);

        $this->assertDatabaseHas('support_diagnostic_runs', [
            'company_id' => $a->id,
            'support_incident_id' => $idA,
            'check_name' => 'tenant_incidents',
        ]);
        $this->assertDatabaseHas('support_diagnostic_runs', [
            'company_id' => $b->id,
            'support_incident_id' => $idB,
            'check_name' => 'tenant_incidents',
        ]);
        $this->assertSame(1, DB::table('support_diagnostic_runs')
            ->where('company_id', $a->id)->count());
        $this->actingAs($n3A)->withSession(['active_company_id' => $a->id]);
        $this->getJson('/api/support/incidents/'.$idA.'/diagnostics')
            ->assertOk()->assertJsonCount(1)
            ->assertDontSee('n3-b-diagnostics@example.test');
    }

    public function test_requires_n3_escalation_and_refuses_closed_incidents(): void
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $n3 = $this->member('n3-state@example.test', $company, 'support_n3');
        $this->actingAs($n3);

        $id = $this->postJson('/api/support/incidents', [
            'title' => 'Synthetic pending', 'description' => 'Controlled fake data.',
            'severity' => 'P3', 'environment' => 'demo',
        ])->assertCreated()->json('id');

        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'database',
        ])->assertConflict();

        $this->postJson('/api/support/incidents/'.$id.'/escalate', [
            'reason' => 'Escalade N3 pour diagnostic prédéfini',
        ])->assertOk();

        $this->patchJson('/api/support/incidents/'.$id, [
            'status' => 'investigating',
        ])->assertOk();
        $this->patchJson('/api/support/incidents/'.$id, [
            'status' => 'resolved', 'resolution' => 'Données fictives vérifiées.',
        ])->assertOk();
        $this->patchJson('/api/support/incidents/'.$id, [
            'status' => 'closed',
        ])->assertOk();

        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'cache',
        ])->assertConflict();
        $this->assertDatabaseCount('support_diagnostic_runs', 0);
    }

    public function test_diagnostics_are_rate_limited_per_user_and_tenant(): void
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $n3 = $this->member('n3-throttle@example.test', $company, 'support_n3');
        $id = $this->createN3Incident($n3);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
                'check' => 'cache',
            ])->assertOk();
        }
        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'cache',
        ])->assertTooManyRequests();
        $this->assertDatabaseCount('support_diagnostic_runs', 5);
    }

    public function test_active_tenant_membership_overrides_legacy_admin_role(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'DIAG_ROLES_B', 'name' => 'Diagnostic roles B']);
        $user = $this->member('multi-diagnostics@example.test', $a, 'support_n3', 'admin');
        $user->companies()->attach($b->id, [
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);

        $id = $this->createN3Incident($user);
        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'database',
        ])->assertOk();

        $this->postJson('/api/tenant/switch', ['company_id' => $b->id])->assertOk();
        $this->postJson('/api/support/incidents/'.$id.'/diagnostics', [
            'check' => 'database',
        ])->assertForbidden();
        $this->getJson('/api/support/incidents/'.$id.'/diagnostics')->assertForbidden();
    }
}
