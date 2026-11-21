<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function become(Company $company, string $role, string $suffix): void
    {
        $user = User::create([
            'name' => 'Registry '.$suffix,
            'email' => 'registry-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!',
            // Deliberately privileged legacy role: only tenant membership counts.
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_registry_rejects_unauthorized_roles_and_isolates_companies(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'MAINT_B', 'name' => 'Registry B']);

        $this->getJson('/api/maintenance/sites')->assertUnauthorized();
        $this->become($a, 'reader', 'reader');
        $this->getJson('/api/maintenance/sites')->assertForbidden();
        $this->postJson('/api/maintenance/sites', ['code'=>'S-01','name'=>'Plant A'])->assertForbidden();

        $this->become($a, 'admin', 'a');
        $siteId = $this->postJson('/api/maintenance/sites', ['code'=>'S-01','name'=>'Plant A'])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/assets', ['site_id'=>$siteId,'asset_code'=>'EQ-01','name'=>'Pump P1'])->assertCreated()->assertJsonPath('status','in_service');
        $this->postJson('/api/maintenance/assets', ['site_id'=>$siteId,'asset_code'=>'EQ-01','name'=>'Duplicate'])->assertUnprocessable();
        $this->become($b, 'admin', 'b');
        $this->getJson('/api/maintenance/sites')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/maintenance/assets', ['site_id'=>$siteId,'asset_code'=>'EQ-99','name'=>'Cross-tenant'])->assertNotFound();
        $this->postJson('/api/maintenance/sites', ['code'=>'S-01','name'=>'Plant B'])->assertCreated();
        $this->getJson('/api/maintenance/assets')->assertOk()->assertJsonPath('total', 0);
    }
}
