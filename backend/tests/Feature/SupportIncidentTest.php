<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportIncidentTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(string $role): void
    {
        $user = User::create([
            'name' => 'Demo '.$role,
            'email' => $role.'@support.example',
            'password' => 'TestPassword123!',
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user);
    }

    public function test_only_admin_can_access_incidents(): void
    {
        $this->getJson('/api/support/incidents')->assertUnauthorized();
        $this->loginAs('reader');
        $this->getJson('/api/support/incidents')->assertForbidden();
        $this->postJson('/api/support/incidents', [])->assertForbidden();
    }

    public function test_incident_lifecycle_and_audit(): void
    {
        $this->loginAs('admin');
        $id = $this->postJson('/api/support/incidents', [
            'title' => 'Latence SQL (démonstration)',
            'description' => 'Requête lente sur une base fictive.',
            'severity' => 'P2',
            'environment' => 'demo',
        ])->assertCreated()->assertJsonPath('status', 'open')->json('id');

        $this->getJson('/api/support/incidents?severity=P2')->assertOk()->assertJsonPath('total', 1);
        $this->getJson("/api/support/incidents/$id")->assertOk()->assertJsonPath('severity', 'P2');
        $this->patchJson("/api/support/incidents/$id", ['status' => 'closed'])->assertUnprocessable();
        $this->patchJson("/api/support/incidents/$id", ['status' => 'investigating'])->assertOk();
        $this->patchJson("/api/support/incidents/$id", ['status' => 'resolved'])->assertUnprocessable();
        $this->patchJson("/api/support/incidents/$id", ['status' => 'resolved', 'resolution' => 'Index fictif ajouté après analyse.'])->assertOk();
        $this->patchJson("/api/support/incidents/$id", ['status' => 'closed'])->assertOk();
        $this->assertDatabaseHas('support_incidents', ['id' => $id, 'status' => 'closed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'support.incident_transitioned', 'entity_id' => $id]);
    }

    public function test_invalid_severity_and_environment_are_rejected(): void
    {
        $this->loginAs('admin');
        $this->postJson('/api/support/incidents', [
            'title' => 'Test',
            'description' => 'Description de test',
            'severity' => 'P0',
            'environment' => 'external',
        ])->assertUnprocessable();
    }
}
