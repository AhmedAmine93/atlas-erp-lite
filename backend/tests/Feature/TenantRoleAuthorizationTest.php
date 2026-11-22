<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function createMember(string $email, string $globalRole, array $memberships): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', $globalRole)->value('id'),
        ]);

        foreach ($memberships as $companyId => $roleName) {
            $user->companies()->attach($companyId, [
                'role_id' => Role::where('name', $roleName)->value('id'),
            ]);
        }

        return $user;
    }

    public function test_global_admin_role_grants_no_permissions_in_a_reader_tenant(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'F06_B', 'name' => 'F06 Company B']);
        $user = $this->createMember('roles-test@example.test', 'admin', [
            $a->id => 'admin',
            $b->id => 'reader',
        ]);
        $this->actingAs($user);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('role.name', 'admin');
        $this->getJson('/api/users')->assertOk();
        $this->postJson('/api/customers', ['name' => 'A Customer', 'email' => 'a-f06@example.test'])->assertCreated();

        $this->postJson('/api/tenant/switch', ['company_id' => $b->id])->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role.name', 'reader')
            ->assertJsonPath('active_company_id', $b->id);
        $this->getJson('/api/customers')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/companies')->assertForbidden();
        $this->postJson('/api/customers', ['name' => 'Forbidden', 'email' => 'forbidden@example.test'])
            ->assertForbidden();
        $this->postJson('/api/products', [])->assertForbidden();
        $this->postJson('/api/companies', ['code' => 'ATTACK', 'name' => 'Attack'])->assertForbidden();

        $this->postJson('/api/tenant/switch', ['company_id' => $a->id])->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role.name', 'admin');
        $this->getJson('/api/users')->assertOk();
    }

    public function test_admin_can_change_only_target_tenant_membership_role(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'F06_B', 'name' => 'F06 Company B']);
        $target = $this->createMember('target-f06@example.test', 'admin', [
            $a->id => 'admin',
            $b->id => 'reader',
        ]);
        $adminB = $this->createMember('admin-b-f06@example.test', 'admin', [$b->id => 'admin']);
        $this->actingAs($adminB)->withSession(['active_company_id' => $b->id]);

        $this->putJson('/api/users/'.$target->id, [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => Role::where('name', 'stock')->value('id'),
        ])->assertOk()->assertJsonPath('role.name', 'stock');

        $this->assertDatabaseHas('company_user', [
            'company_id' => $a->id,
            'user_id' => $target->id,
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $b->id,
            'user_id' => $target->id,
            'role_id' => Role::where('name', 'stock')->value('id'),
        ]);
        // The deprecated global field is retained, but no longer determines permissions.
        $this->assertSame('admin', $target->fresh()->role->name);

        $this->actingAs($target)->withSession(['active_company_id' => $b->id]);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role.name', 'stock');
        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/products', [
            'reference' => 'F06-STOCK', 'name' => 'Stock B',
            'category' => 'Test', 'price_cents' => 1000,
            'vat_bps' => 2000, 'alert_threshold' => 1,
        ])->assertCreated();

        $this->postJson('/api/tenant/switch', ['company_id' => $a->id])->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role.name', 'admin');
        $this->getJson('/api/users')->assertOk();
    }

    public function test_last_company_admin_cannot_be_demoted(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $admin = $this->createMember('sole-admin-f06@example.test', 'admin', [$a->id => 'admin']);
        $this->actingAs($admin);

        $this->putJson('/api/users/'.$admin->id, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => Role::where('name', 'reader')->value('id'),
        ])->assertConflict();
        $this->deleteJson('/api/users/'.$admin->id)->assertConflict();
        $this->assertDatabaseHas('company_user', [
            'company_id' => $a->id,
            'user_id' => $admin->id,
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
    }

    public function test_new_company_grants_creator_admin_only_after_tenant_switch(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $admin = $this->createMember('creator-f06@example.test', 'admin', [$a->id => 'admin']);
        $this->actingAs($admin);

        $b = $this->postJson('/api/companies', [
            'code' => 'CREATED_F06', 'name' => 'Created Company F06',
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('company_user', [
            'company_id' => $b,
            'user_id' => $admin->id,
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);

        $this->postJson('/api/establishments', [
            'company_id' => $b, 'code' => 'SITE', 'name' => 'Site',
        ])->assertNotFound();
        $this->putJson('/api/companies/'.$b, [
            'code' => 'CREATED_F06', 'name' => 'Cross-tenant update',
        ])->assertNotFound();

        $this->postJson('/api/tenant/switch', ['company_id' => $b])->assertOk();
        $this->postJson('/api/establishments', [
            'company_id' => $b, 'code' => 'SITE', 'name' => 'Site',
        ])->assertCreated();
        $this->getJson('/api/establishments?company_id='.$a->id)->assertNotFound();
        $this->getJson('/api/establishments')->assertOk()->assertJsonCount(1);
    }

    public function test_login_response_already_contains_active_membership_role(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->createMember('login-f06@example.test', 'admin', [$a->id => 'reader']);
        $this->postJson('/api/login', [
            'email' => 'login-f06@example.test', 'password' => 'PasswordTest2026!',
        ])->assertOk()->assertJsonPath('role.name', 'reader')
            ->assertJsonPath('active_company_id', $a->id);
        $this->getJson('/api/users')->assertForbidden();
    }
}
