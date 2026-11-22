<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MaintenancePreventiveTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(Company $company, string $role, string $suffix): void
    {
        $roleId = Role::where('name', $role)->value('id');
        $user = User::create([
            'name' => 'PM Tester',
            'email' => 'pm-'.$suffix.'@example.test',
            'password' => 'TestingPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, ['role_id' => $roleId]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_due_plan_generates_qualified_DI_and_unique_BT_without_duplicate_rerun(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'PM_SECOND', 'name' => 'Fictive tenant B']);
        $this->getJson('/api/maintenance/preventive/plans')->assertUnauthorized();
        $this->asRole($a, 'reader', 'reader');
        $this->getJson('/api/maintenance/preventive/plans')->assertForbidden();
        $this->asRole($a, 'admin', 'a');
        $site = $this->postJson('/api/maintenance/sites', [
            'code' => 'PM-SITE', 'name' => 'Usine fictive',
        ])->assertCreated()->json('id');
        $asset = $this->postJson('/api/maintenance/assets', [
            'site_id' => $site, 'asset_code' => 'PM-PUMP', 'name' => 'Pompe fictive',
        ])->assertCreated()->json('id');

        $this->postJson('/api/maintenance/preventive/plans', [
            'code' => 'PM-001', 'title' => 'Contrôle de la pompe',
            'asset_id' => $asset, 'priority' => 'high',
            'interval_days' => 30,
            'next_due_on' => today()->subDay()->toDateString(),
            'company_id' => $b->id,
        ])->assertUnprocessable();
        $plan = $this->postJson('/api/maintenance/preventive/plans', [
            'code' => 'PM-001',
            'title' => 'Contrôle de la pompe',
            'asset_id' => $asset,
            'priority' => 'high',
            'interval_days' => 30,
            'next_due_on' => today()->subDay()->toDateString(),
        ])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/preventive/run')
            ->assertOk()->assertJsonPath('generated_count', 1);
        $this->postJson('/api/maintenance/preventive/run')
            ->assertOk()->assertJsonPath('generated_count', 0);
        $this->assertDatabaseCount('maintenance_preventive_occurrences', 1);
        $this->assertDatabaseCount('maintenance_work_orders', 1);
        $this->assertDatabaseHas('maintenance_requests', [
            'company_id' => $a->id, 'status' => 'qualified', 'priority' => 'high',
        ]);
        $this->assertDatabaseHas('maintenance_preventive_plans', [
            'company_id' => $a->id, 'id' => $plan,
            'next_due_on' => today()->subDay()->addDays(30)->toDateString(),
        ]);
        $this->asRole($b, 'admin', 'b');
        $this->getJson('/api/maintenance/preventive/plans')
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/maintenance/preventive/occurrences')
            ->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/maintenance/preventive/plans', [
            'code' => 'PM-CROSS', 'title' => 'Injection',
            'asset_id' => $asset, 'priority' => 'normal',
            'interval_days' => 7, 'next_due_on' => today()->toDateString(),
        ])->assertNotFound();
    }
}
