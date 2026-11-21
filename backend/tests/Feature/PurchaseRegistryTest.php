<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRegistryTest extends TestCase
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
        $b = Company::create(['code' => 'PURCHASE_B', 'name' => 'Registry B']);

        $this->getJson('/api/purchasing/requests')->assertUnauthorized();
        $this->become($a, 'reader', 'reader');
        $this->getJson('/api/purchasing/requests')->assertForbidden();
        $this->postJson('/api/purchasing/requests', ['reference'=>'REQ-01','title'=>'Replace lab equipment','estimated_total_cents'=>25000])->assertForbidden();

        $this->become($a, 'admin', 'a');
        $this->postJson('/api/purchasing/requests', ['reference'=>'REQ-01','title'=>'Replace lab equipment','estimated_total_cents'=>25000,'company_id'=>$b->id])->assertUnprocessable();
        $this->postJson('/api/purchasing/requests', ['reference'=>'REQ-01','title'=>'Replace lab equipment','estimated_total_cents'=>25000])->assertCreated()->assertJsonPath('status','draft');
        $this->postJson('/api/purchasing/requests', ['reference'=>'REQ-01','title'=>'Duplicate','estimated_total_cents'=>25000])->assertUnprocessable();
        $this->postJson('/api/purchasing/requests', ['reference'=>'REQ-02','title'=>'Invalid budget','estimated_total_cents'=>-5])->assertUnprocessable();
        $this->become($b, 'admin', 'b');
        $this->getJson('/api/purchasing/requests')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/purchasing/requests', ['reference'=>'REQ-01','title'=>'Independent need','estimated_total_cents'=>100])->assertCreated();
        $this->assertDatabaseCount('purchase_requests', 2);
    }
}
