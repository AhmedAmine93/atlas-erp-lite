<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\SupportIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportIncidentTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email, Company $company, string $role = 'support_n2', string $global = 'reader'): User
    {
        $user = User::create([
            'name' => $email, 'email' => $email,
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', $global)->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);

        return $user;
    }

    private function incident(string $title = 'Latence SQL (fictif)', array $override = []): array
    {
        return array_merge([
            'title' => $title,
            'description' => 'Une requête lente sur une base de démonstration.',
            'severity' => 'P2',
            'environment' => 'demo',
        ], $override);
    }

    public function test_f07_requires_tenant_support_role_and_preserves_original_lifecycle(): void
    {
        $this->getJson('/api/support/incidents')->assertUnauthorized();

        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $reader = $this->member('support-reader@example.test', $company, 'reader', 'admin');
        $this->actingAs($reader);
        $this->getJson('/api/support/incidents')->assertForbidden();
        $this->postJson('/api/support/incidents', $this->incident())->assertForbidden();

        $n2 = $this->member('support-n2@example.test', $company);
        $this->actingAs($n2);
        $id = $this->postJson('/api/support/incidents', $this->incident())
            ->assertCreated()->assertJsonPath('status', 'open')
            ->assertJsonPath('escalation_level', 2)->json('id');

        $this->getJson('/api/support/incidents?severity=P2')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/support/incidents/'.$id)->assertOk()
            ->assertJsonCount(1, 'events');
        $this->patchJson('/api/support/incidents/'.$id, ['status' => 'closed'])->assertUnprocessable();
        $this->patchJson('/api/support/incidents/'.$id, ['status' => 'investigating'])->assertOk()
            ->assertJsonPath('status', 'investigating');
        $this->patchJson('/api/support/incidents/'.$id, ['status' => 'resolved'])->assertUnprocessable();
        $this->patchJson('/api/support/incidents/'.$id, [
            'status' => 'resolved', 'resolution' => 'Index fictif ajouté après analyse.',
        ])->assertOk()->assertJsonPath('status', 'resolved');
        $this->patchJson('/api/support/incidents/'.$id, ['status' => 'closed'])->assertOk();
        $this->patchJson('/api/support/incidents/'.$id, ['status' => 'closed'])->assertUnprocessable();

        $this->assertDatabaseHas('support_incidents', [
            'id' => $id, 'company_id' => $company->id, 'status' => 'closed',
        ]);
        $this->getJson('/api/support/incidents/'.$id)->assertOk()->assertJsonCount(4, 'events');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'support.incident_transitioned', 'entity_id' => $id,
        ]);
    }

    public function test_f08_denies_cross_tenant_reads_writes_assignment_and_events(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'F08_B', 'name' => 'Support B']);
        $userA = $this->member('support-a@example.test', $a);
        $userB = $this->member('support-b@example.test', $b, 'support_n3');

        $this->actingAs($userA)->withSession(['active_company_id' => $a->id]);
        $aId = $this->postJson('/api/support/incidents', $this->incident('Incident A'))
            ->assertCreated()->json('id');
        $this->postJson('/api/support/incidents', $this->incident('Assigned foreign', [
            'assigned_to' => $userB->id,
        ]))->assertUnprocessable();
        $this->postJson('/api/support/incidents/'.$aId.'/comments', [
            'message' => 'Investigation interne A',
        ])->assertCreated();

        $this->actingAs($userB)->withSession(['active_company_id' => $b->id]);
        $this->getJson('/api/support/incidents')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/support/incidents/'.$aId)->assertNotFound();
        $this->patchJson('/api/support/incidents/'.$aId, ['status' => 'investigating'])->assertNotFound();
        $this->postJson('/api/support/incidents/'.$aId.'/comments', [
            'message' => 'Tentative de fuite',
        ])->assertNotFound();
        $this->postJson('/api/support/incidents/'.$aId.'/escalate', [
            'reason' => 'Escalade non autorisée',
        ])->assertNotFound();
        $this->getJson('/api/support/incidents/summary')->assertOk()
            ->assertJsonPath('open', 0);

        $bId = $this->postJson('/api/support/incidents', $this->incident('Incident B'))
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('support_incidents', [
            'id' => $aId, 'company_id' => $a->id,
        ]);
        $this->assertDatabaseHas('support_incidents', [
            'id' => $bId, 'company_id' => $b->id,
        ]);
        $this->getJson('/api/support/incidents')->assertOk()->assertJsonPath('total', 1);

        $this->actingAs($userA)->withSession(['active_company_id' => $a->id]);
        $this->getJson('/api/support/incidents')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/support/incidents/'.$bId)->assertNotFound();
        $this->getJson('/api/support/incidents/'.$aId)->assertOk()
            ->assertJsonCount(2, 'events');
        $this->assertSame(2, DB::table('support_incident_events')
            ->where('company_id', $a->id)->count());
    }

    public function test_f09_sla_deadlines_escalation_and_timeline(): void
    {
        $this->travelTo(now()->startOfMinute());

        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $n2 = $this->member('sla-n2@example.test', $company);
        $n3 = $this->member('sla-n3@example.test', $company, 'support_n3');
        $this->actingAs($n2);

        $created = $this->postJson('/api/support/incidents', $this->incident('P1 demo', [
            'severity' => 'P1', 'assigned_to' => $n3->id,
        ]))->assertCreated()->assertJsonPath('response_sla_status', 'pending')
            ->assertJsonPath('resolution_sla_status', 'pending')->json();
        $id = $created['id'];

        $this->assertSame(15, (int) round(
            \Carbon\Carbon::parse($created['created_at'])->diffInMinutes(
                \Carbon\Carbon::parse($created['response_due_at']), true
            ),
        ));
        $this->assertSame(240, (int) round(
            \Carbon\Carbon::parse($created['created_at'])->diffInMinutes(
                \Carbon\Carbon::parse($created['resolution_due_at']), true
            ),
        ));

        $this->travel(20)->minutes();
        $this->getJson('/api/support/incidents/summary')->assertOk()
            ->assertJsonPath('response_sla_breached', 1);
        $this->patchJson('/api/support/incidents/'.$id, [
            'status' => 'investigating', 'assigned_to' => $n3->id,
        ])->assertOk()->assertJsonPath('response_sla_status', 'breached');
        $this->postJson('/api/support/incidents/'.$id.'/escalate', [
            'reason' => 'Diagnostic Oracle nécessite N3',
        ])->assertOk()->assertJsonPath('escalation_level', 3);
        $this->postJson('/api/support/incidents/'.$id.'/escalate', [
            'reason' => 'Double escalade',
        ])->assertConflict();
        $this->getJson('/api/support/incidents/summary')->assertOk()
            ->assertJsonPath('escalated_n3', 1);

        $this->postJson('/api/support/incidents/'.$id.'/comments', [
            'message' => 'Trace fictive analysée, requête contrôlée.',
        ])->assertCreated();
        $this->getJson('/api/support/incidents/'.$id)->assertOk()
            ->assertJsonCount(4, 'events');
        $this->patchJson('/api/support/incidents/'.$id, [
            'status' => 'resolved', 'resolution' => 'Correctif validé.',
        ])->assertOk()->assertJsonPath('resolution_sla_status', 'met');
        $this->patchJson('/api/support/incidents/'.$id, ['status' => 'closed'])->assertOk();
        $this->getJson('/api/support/incidents/summary')->assertOk()->assertJsonPath('open', 0);
        $this->postJson('/api/support/incidents/'.$id.'/escalate', [
            'reason' => 'Incident terminé',
        ])->assertConflict();
    }

    public function test_support_user_loses_access_after_switching_to_reader_membership(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'SUPPORT_READER_B', 'name' => 'Support Reader B']);
        $user = $this->member('shared-support@example.test', $a);
        $user->companies()->attach($b->id, [
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);
        $this->actingAs($user);

        $this->postJson('/api/support/incidents', $this->incident())->assertCreated();
        $this->postJson('/api/tenant/switch', ['company_id' => $b->id])->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role.name', 'reader');
        $this->getJson('/api/support/incidents')->assertForbidden();
        $this->postJson('/api/support/incidents', $this->incident())->assertForbidden();
    }
}
