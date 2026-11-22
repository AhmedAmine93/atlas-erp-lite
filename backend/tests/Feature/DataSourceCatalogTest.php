<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataSourceCatalogTest extends TestCase
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
        $b = Company::create(['code' => 'DATA_B', 'name' => 'Registry B']);

        $this->getJson('/api/data/sources')->assertUnauthorized();
        $this->become($a, 'reader', 'reader');
        $this->getJson('/api/data/sources')->assertForbidden();
        $this->postJson('/api/data/sources', ['code'=>'PMS_01','name'=>'PMS synthetic feed','source_type'=>'pms','classification'=>'internal'])->assertForbidden();

        $this->become($a, 'admin', 'a');
        $this->postJson('/api/data/sources', ['code'=>'PMS_01','name'=>'PMS synthetic feed','source_type'=>'pms','classification'=>'internal','password'=>'secret'])->assertUnprocessable();
        $this->postJson('/api/data/sources', ['code'=>'PMS_01','name'=>'PMS synthetic feed','source_type'=>'pms','classification'=>'internal'])->assertCreated()->assertJsonPath('status','draft');
        $this->postJson('/api/data/sources', ['code'=>'PMS_01','name'=>'Duplicate','source_type'=>'pms','classification'=>'internal'])->assertUnprocessable();
        $this->become($b, 'admin', 'b');
        $this->getJson('/api/data/sources')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/data/sources', ['code'=>'PMS_01','name'=>'Other tenant feed','source_type'=>'csv','classification'=>'restricted'])->assertCreated();
        $this->getJson('/api/data/sources')->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseCount('data_source_registry', 2);
    }
}
