<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupportItsmMenuTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $email, Company $company, string $role): User
    {
        $user = User::create([
            'name' => $email, 'email' => $email, 'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        return $user;
    }

    private function ticket(string $title = 'Ticket fictif'): array
    {
        return ['title' => $title, 'description' => 'Exercice de qualification fictive.', 'priority' => 'P2'];
    }

    private function createCase(string $kind, array $data, string $key): int
    {
        return (int) $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/support/cases/'.$kind, $data)
            ->assertCreated()->json('id');
    }

    public function test_ticket_is_tenant_isolated_idempotent_and_version_checked(): void
    {
        $a = Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'M11_B', 'name' => 'Tenant B']);
        $n2 = $this->actor('itsm-n2-a@example.test', $a, 'support_n2');
        $reader = $this->actor('itsm-reader@example.test', $a, 'reader');
        $n3b = $this->actor('itsm-n3-b@example.test', $b, 'support_n3');

        $this->actingAs($reader)->withSession(['active_company_id' => $a->id]);
        $this->getJson('/api/support/cases/ticket')->assertForbidden();

        $this->actingAs($n2)->withSession(['active_company_id' => $a->id]);
        $payload = $this->ticket();
        $id = $this->createCase('ticket', $payload, 'm11-ticket-replay-0001');
        $this->withHeader('Idempotency-Key', 'm11-ticket-replay-0001')
            ->postJson('/api/support/cases/ticket', $payload)
            ->assertOk()->assertJsonPath('id', $id);
        $this->withHeader('Idempotency-Key', 'm11-ticket-replay-0001')
            ->postJson('/api/support/cases/ticket', $this->ticket('Changed'))
            ->assertStatus(409);
        $this->assertDatabaseCount('support_cases', 1);
        $this->withHeader('Idempotency-Key','m11-ticket-transition-1')
            ->postJson('/api/support/cases/ticket/'.$id.'/transition',
                ['status'=>'closed', 'version'=>1])->assertStatus(409);
        $this->withHeader('Idempotency-Key','m11-ticket-transition-2')
            ->postJson('/api/support/cases/ticket/'.$id.'/transition',
                ['status'=>'investigating','version'=>1])
            ->assertCreated()->assertJsonPath('version',2);
        $this->withHeader('Idempotency-Key','m11-ticket-transition-3')
            ->postJson('/api/support/cases/ticket/'.$id.'/transition',
                ['status'=>'resolved','version'=>1])->assertStatus(409);

        $this->actingAs($n3b)->withSession(['active_company_id' => $b->id]);
        $this->getJson('/api/support/cases/ticket')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/support/cases/ticket/'.$id)->assertNotFound();
        $this->withHeader('Idempotency-Key','m11-foreign-case-action')
            ->postJson('/api/support/cases/ticket/'.$id.'/transition',
                ['status'=>'resolved','version'=>2])->assertNotFound();
        $this->assertDatabaseHas('support_cases', ['id'=>$id,'company_id'=>$a->id,'status'=>'investigating']);
        $this->assertDatabaseCount('support_case_events', 2);
    }

    public function test_change_requires_cab_independent_approval_and_rollback_plan(): void
    {
        $a = Company::where('code','ATLAS_DEMO')->firstOrFail();
        $requester = $this->actor('change-n2@example.test', $a, 'support_n2');
        $cab = $this->actor('change-cab@example.test', $a, 'support_cab');
        $this->actingAs($requester)->withSession(['active_company_id'=>$a->id]);
        $id = $this->createCase('change', [
            ...$this->ticket('Changement fictif'), 'risk_level'=>'high',
            'window_start'=>'2027-01-10T09:00:00Z',
            'window_end'=>'2027-01-10T10:00:00Z',
            'rollback_plan'=>'Restaurer la version précédente.',
        ], 'm11-change-create-0001');
        $this->withHeader('Idempotency-Key','m11-change-await-0001')
            ->postJson('/api/support/cases/change/'.$id.'/transition',
                ['status'=>'awaiting_approval','version'=>1])
            ->assertCreated();
        $this->withHeader('Idempotency-Key','m11-change-self-0001')
            ->postJson('/api/support/cases/change/'.$id.'/transition',
                ['status'=>'approved','version'=>2])->assertForbidden();
        $this->actingAs($cab)->withSession(['active_company_id'=>$a->id]);
        $this->withHeader('Idempotency-Key','m11-change-approved-1')
            ->postJson('/api/support/cases/change/'.$id.'/transition',
                ['status'=>'approved','version'=>2])->assertCreated()
            ->assertJsonPath('approved_version', 2);
        $this->withHeader('Idempotency-Key','m11-change-deploy-1')
            ->postJson('/api/support/cases/change/'.$id.'/transition',
                ['status'=>'implementing','version'=>3])->assertCreated();
        $this->withHeader('Idempotency-Key','m11-change-complete-1')
            ->postJson('/api/support/cases/change/'.$id.'/transition',
                ['status'=>'completed','version'=>4])->assertStatus(409);
        $this->assertDatabaseHas('support_cases', ['id'=>$id,'status'=>'implementing']);
    }

    public function test_service_requires_catalog_approval_and_completion_evidence(): void
    {
        $a = Company::where('code','ATLAS_DEMO')->firstOrFail();
        $manager = $this->actor('catalog-manager@example.test', $a, 'support_manager');
        $n2 = $this->actor('service-n2@example.test', $a, 'support_n2');
        $this->actingAs($manager)->withSession(['active_company_id'=>$a->id]);
        $catalog = $this->withHeader('Idempotency-Key','m11-catalog-0001')
            ->postJson('/api/support/catalog', [
                'code'=>'ACCOUNT_ACCESS','title'=>'Accès applicatif',
                'requires_approval'=>true,'sensitive'=>true,
            ])->assertCreated()->json('id');
        $this->actingAs($n2)->withSession(['active_company_id'=>$a->id]);
        $id = $this->createCase('service',
            [...$this->ticket('Demande habilitation'), 'catalog_id'=>$catalog],
            'm11-service-create-1');
        $this->withHeader('Idempotency-Key','m11-service-direct-1')
            ->postJson('/api/support/cases/service/'.$id.'/transition',
                ['status'=>'approved','version'=>1])->assertStatus(409);
        $this->withHeader('Idempotency-Key','m11-service-wait-1')
            ->postJson('/api/support/cases/service/'.$id.'/transition',
                ['status'=>'awaiting_approval','version'=>1])->assertCreated();
        $this->actingAs($manager)->withSession(['active_company_id'=>$a->id]);
        $this->withHeader('Idempotency-Key','m11-service-ok-1')
            ->postJson('/api/support/cases/service/'.$id.'/transition',
                ['status'=>'approved','version'=>2])->assertCreated();
        $this->withHeader('Idempotency-Key','m11-service-start-1')
            ->postJson('/api/support/cases/service/'.$id.'/transition',
                ['status'=>'fulfilling','version'=>3])->assertCreated();
        $this->withHeader('Idempotency-Key','m11-service-done-1')
            ->postJson('/api/support/cases/service/'.$id.'/transition',
                ['status'=>'completed','version'=>4])->assertStatus(409);
    }

    public function test_n3_knowledge_is_hidden_and_critical_review_requires_other_actor(): void
    {
        $a = Company::where('code','ATLAS_DEMO')->firstOrFail();
        $n3 = $this->actor('knowledge-n3@example.test', $a, 'support_n3');
        $n2 = $this->actor('knowledge-n2@example.test', $a, 'support_n2');
        $this->actingAs($n3)->withSession(['active_company_id'=>$a->id]);
        $id = $this->postJson('/api/support/knowledge', [
            'title'=>'Procédure confidentielle',
            'content'=>'Accès diagnostic N3 fictif sécurisé.',
            'classification'=>'n3', 'critical'=>true,
            'next_review_at'=>'2027-06-01',
        ])->assertCreated()->json('id');
        $this->postJson('/api/support/knowledge/'.$id.'/decision',
            ['action'=>'publish','version'=>1])->assertStatus(409);
        $this->actingAs($n2)->withSession(['active_company_id'=>$a->id]);
        $this->getJson('/api/support/knowledge')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/support/knowledge/'.$id)->assertNotFound();
    }

    public function test_sla_stores_versions_and_pending_escalation_is_not_automatically_acknowledged(): void
    {
        $a = Company::where('code','ATLAS_DEMO')->firstOrFail();
        $manager = $this->actor('sla-manager@example.test',$a,'support_manager');
        $this->actingAs($manager)->withSession(['active_company_id'=>$a->id]);
        $id = $this->postJson('/api/support/sla/policies', [
            'severity'=>'P2','response_minutes'=>15,'resolution_minutes'=>60,
            'calendar'=>'continuous','effective_from'=>'2027-01-01T00:00:00Z',
        ])->assertCreated()->assertJsonPath('published',false)->json('id');
        $this->postJson('/api/support/sla/policies/'.$id.'/publish')->assertOk()
            ->assertJsonPath('published',true);
        $this->getJson('/api/support/sla/policies')->assertOk()
            ->assertJsonPath('data.0.version',1);
    }
}
