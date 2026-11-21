<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardLayoutPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private function userIn(Company $company, string $role, string $email): User
    {
        $user = User::create([
            'name' => 'Preferences '.$role,
            'email' => $email,
            'password' => 'SyntheticPassword2026!',
            // Legacy global role is intentionally privileged.
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        return $user;
    }

    public function test_layout_is_private_to_user_and_tenant(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'DASH_LAYOUT_B', 'name' => 'Dashboard B']);
        $user = $this->userIn($a, 'admin', 'dashboard-tenant@example.test');
        $user->companies()->attach($b->id, [
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $other = $this->userIn($a, 'admin', 'dashboard-other@example.test');
        $payload = [
            'version' => 1,
            'layout' => [['id' => 'revenue', 'enabled' => false]],
        ];
        $this->actingAs($user)->withSession(['active_company_id' => $a->id]);
        $this->getJson('/api/dashboard/layout')->assertOk()->assertJsonPath('layout', null);
        $this->putJson('/api/dashboard/layout', $payload)->assertOk()
            ->assertJsonPath('layout.0.enabled', false);
        $this->getJson('/api/dashboard/layout')->assertJsonPath('layout.0.id', 'revenue');
        $this->withSession(['active_company_id' => $b->id]);
        $this->getJson('/api/dashboard/layout')->assertJsonPath('layout', null);

        $this->actingAs($other)->withSession(['active_company_id' => $a->id]);
        $this->getJson('/api/dashboard/layout')->assertJsonPath('layout', null);
        $this->assertDatabaseCount('dashboard_layout_preferences', 1);
    }

    public function test_schema_disallows_injections_unknown_widgets_and_privilege_escalation(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $stock = $this->userIn($a, 'stock', 'dashboard-stock@example.test');
        $this->actingAs($stock)->withSession(['active_company_id' => $a->id]);
        $this->putJson('/api/dashboard/layout', [
            'version' => 1, 'layout' => [['id' => 'revenue', 'enabled' => true]],
        ])->assertForbidden();
        $this->putJson('/api/dashboard/layout', [
            'version' => 1, 'layout' => [['id' => '<script>alert(1)</script>', 'enabled' => true]],
        ])->assertUnprocessable();
        $this->putJson('/api/dashboard/layout', [
            'version' => 2, 'layout' => [['id' => 'low-stock', 'enabled' => true]],
        ])->assertUnprocessable();
        $this->putJson('/api/dashboard/layout', [
            'version' => 1, 'layout' => [['id' => 'low-stock', 'enabled' => true, 'expression' => 'DROP TABLE users']],
        ])->assertUnprocessable();
        $this->putJson('/api/dashboard/layout', [
            'version' => 1, 'layout' => [
                ['id' => 'low-stock', 'enabled' => true],
                ['id' => 'low-stock', 'enabled' => false],
            ],
        ])->assertUnprocessable();
        $this->putJson('/api/dashboard/layout', [
            'version' => 1, 'layout' => [['id' => 'low-stock', 'enabled' => true]],
        ])->assertOk();
        $support = $this->userIn($a, 'support_n2', 'dashboard-support@example.test');
        $this->actingAs($support)->withSession(['active_company_id' => $a->id]);
        $this->putJson('/api/dashboard/layout', [
            'version' => 1, 'layout' => [['id' => 'low-stock', 'enabled' => true]],
        ])->assertForbidden();
    }
}
