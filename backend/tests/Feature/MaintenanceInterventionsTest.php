<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceInterventionsTest extends TestCase
{
    use RefreshDatabase;

    private function asRole(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name' => $suffix, 'email' => 'di-'.$suffix.'@example.test',
            'password' => 'SyntheticPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_intervention_transitions_are_audited_and_non_repeatable(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->asRole($a, 'admin', 'admin-a');
        $site = $this->postJson('/api/maintenance/sites', ['code'=>'S-A', 'name'=>'Plant Alpha'])
            ->assertCreated()->json('id');
        $asset = $this->postJson('/api/maintenance/assets', [
            'site_id'=>$site, 'asset_code'=>'PUMP-A', 'name'=>'Valve',
        ])->assertCreated()->json('id');
        $id = $this->postJson('/api/maintenance/interventions', [
            'site_id'=>$site, 'asset_id'=>$asset, 'title'=>'Leak in hydraulic line',
            'category'=>'breakdown', 'priority'=>'high',
        ])->assertCreated()->assertJsonPath('status', 'new')->json('id');

        $this->postJson("/api/maintenance/interventions/$id/transition", [
            'status'=>'accepted', 'reason'=>'Skipping qualification',
        ])->assertStatus(409);
        $this->postJson("/api/maintenance/interventions/$id/transition", [
            'status'=>'qualified', 'reason'=>'Visual inspection',
        ])->assertOk()->assertJsonPath('status', 'qualified');
        $this->postJson("/api/maintenance/interventions/$id/transition", [
            'status'=>'accepted', 'reason'=>'Technician required',
        ])->assertOk()->assertJsonPath('status', 'accepted');
        $this->postJson("/api/maintenance/interventions/$id/transition", [
            'status'=>'rejected', 'reason'=>'Too late',
        ])->assertStatus(409);
        $this->getJson("/api/maintenance/interventions/$id/history")->assertOk()
            ->assertJsonCount(3)->assertJsonPath('2.to_status', 'accepted');
        $this->assertDatabaseCount('maintenance_intervention_events', 3);
        $this->assertDatabaseHas('maintenance_interventions', [
            'id'=>$id, 'company_id'=>$a->id, 'status'=>'accepted',
        ]);
    }

    public function test_cross_site_cross_company_and_role_injections_are_blocked(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code'=>'DI_B', 'name'=>'Second tenant']);
        $this->asRole($a, 'admin', 'admin-main');
        $siteA = $this->postJson('/api/maintenance/sites', [
            'code'=>'A', 'name'=>'Site A',
        ])->assertCreated()->json('id');
        $siteA2 = $this->postJson('/api/maintenance/sites', [
            'code'=>'A2', 'name'=>'Site A2',
        ])->assertCreated()->json('id');
        $assetA = $this->postJson('/api/maintenance/assets', [
            'site_id'=>$siteA, 'asset_code'=>'ASSET-A', 'name'=>'Pump',
        ])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/interventions', [
            'site_id'=>$siteA2, 'asset_id'=>$assetA, 'title'=>'Wrong site',
            'category'=>'inspection', 'priority'=>'normal',
        ])->assertNotFound();
        $id = $this->postJson('/api/maintenance/interventions', [
            'site_id'=>$siteA, 'title'=>'Inspection checklist',
            'category'=>'inspection', 'priority'=>'normal',
        ])->assertCreated()->json('id');

        $this->asRole($b, 'admin', 'admin-b');
        $this->getJson("/api/maintenance/interventions/$id")->assertNotFound();
        $this->postJson("/api/maintenance/interventions/$id/transition", [
            'status'=>'qualified', 'reason'=>'Foreign tenant',
        ])->assertNotFound();
        $this->postJson('/api/maintenance/interventions', [
            'site_id'=>$siteA, 'title'=>'Cross company', 'category'=>'breakdown', 'priority'=>'high',
        ])->assertNotFound();
        $this->asRole($a, 'reader', 'reader');
        $this->getJson('/api/maintenance/interventions')->assertForbidden();
        $this->postJson("/api/maintenance/interventions/$id/transition", [
            'status'=>'qualified', 'reason'=>'No privilege',
        ])->assertForbidden();
    }
}
