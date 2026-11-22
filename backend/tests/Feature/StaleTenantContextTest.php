<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P066.05: session tenant changes across tabs must fail closed for stale
 * requests, especially mutations. Legacy clients remain compatible.
 */
class StaleTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_company_header_blocks_business_reads_and_writes_after_switch(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'STALE_CTX_B', 'name' => 'Second demo company']);
        $user = User::create([
            'name' => 'Context tester',
            'email' => 'stale-tenant@example.test',
            'password' => 'TestPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $admin = Role::where('name', 'admin')->value('id');
        $user->companies()->attach($a->id, ['role_id' => $admin]);
        $user->companies()->attach($b->id, ['role_id' => $admin]);
        $this->actingAs($user)->withSession(['active_company_id' => $a->id]);

        $this->withHeader('X-Atlas-Expected-Company', (string) $a->id);
        $this->getJson('/api/customers')->assertOk();

        // Reconciliation and switching remain possible regardless of the stale
        // header. The server still validates the target company membership.
        $this->postJson('/api/tenant/switch', ['company_id' => $b->id])
            ->assertOk()->assertJsonPath('active_company_id', $b->id);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('active_company_id', $b->id);

        $this->getJson('/api/customers')->assertStatus(409)
            ->assertHeader('X-Atlas-Context-Stale', '1');
        $this->getJson('/api/dashboard')->assertStatus(409)
            ->assertHeader('X-Atlas-Context-Stale', '1');
        $this->get('/api/invoices/1/pdf')->assertStatus(409)
            ->assertHeader('X-Atlas-Context-Stale', '1');
        $this->postJson('/api/customers', [
            'name' => 'SHOULD NOT EXIST', 'email' => 'cross-company@example.test',
        ])->assertStatus(409)->assertHeader('X-Atlas-Context-Stale', '1');
        $this->assertDatabaseMissing('customers', [
            'name' => 'SHOULD NOT EXIST', 'company_id' => $b->id,
        ]);

        $this->withHeader('X-Atlas-Expected-Company', (string) $b->id);
        $this->getJson('/api/customers')->assertOk();
        $this->postJson('/api/customers', [
            'name' => 'B Authorized', 'email' => 'company-b@example.test',
        ])->assertCreated();
        $this->assertDatabaseHas('customers', [
            'name' => 'B Authorized', 'company_id' => $b->id,
        ]);
    }

    public function test_invalid_expected_company_header_is_rejected_without_leaking_business_data(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $user = User::create([
            'name' => 'Invalid header tester',
            'email' => 'invalid-expected@example.test',
            'password' => 'TestPassword2026!',
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);
        $user->companies()->attach($a->id, [
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $a->id]);

        foreach (['0', 'not-an-id', '9999999999999999999999999', '-1'] as $header) {
            $this->withHeader('X-Atlas-Expected-Company', $header);
            $this->getJson('/api/customers')->assertStatus(409);
        }
        $this->withHeader('X-Atlas-Expected-Company', (string) $a->id);
        $this->getJson('/api/customers')->assertOk();
    }
}
