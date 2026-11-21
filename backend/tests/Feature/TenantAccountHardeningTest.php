<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAccountHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email, string $globalRole, array $memberships): User
    {
        $user = User::create([
            'name' => 'Account '.$email,
            'email' => $email,
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', $globalRole)->value('id'),
        ]);

        foreach ($memberships as $companyId => $role) {
            $user->companies()->attach($companyId, [
                'role_id' => Role::where('name', $role)->value('id'),
            ]);
        }

        return $user;
    }

    public function test_global_identity_cannot_be_modified_by_a_single_company_admin(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'F13_OTHER', 'name' => 'Other company']);
        $adminA = $this->member('admin-f13@example.test', 'admin', [$a->id => 'admin']);
        $target = $this->member('shared-f13@example.test', 'admin', [
            $a->id => 'reader',
            $b->id => 'admin',
        ]);
        $readerRole = Role::where('name', 'reader')->firstOrFail();
        $stockRole = Role::where('name', 'stock')->firstOrFail();
        $adminRole = Role::where('name', 'admin')->firstOrFail();
        $this->actingAs($adminA);

        $users = $this->getJson('/api/users')->assertOk()->json('data');
        $targetProfile = collect($users)->firstWhere('id', $target->id);
        $this->assertSame($readerRole->id, $targetProfile['role_id']);
        $this->assertSame('reader', $targetProfile['role']['name']);

        // Global identity must not be changed through an affiliation editor.
        $this->putJson('/api/users/'.$target->id, [
            'role_id' => $stockRole->id, 'name' => 'Hijacked',
        ])->assertUnprocessable();
        $this->putJson('/api/users/'.$target->id, [
            'role_id' => $stockRole->id, 'email' => 'attacker@example.test',
        ])->assertUnprocessable();
        $this->putJson('/api/users/'.$target->id, [
            'role_id' => $stockRole->id, 'password' => 'HijackedPassword2026!',
        ])->assertUnprocessable();

        $this->assertSame('Account shared-f13@example.test', $target->fresh()->name);
        $this->assertSame('shared-f13@example.test', $target->fresh()->email);
        $this->assertSame($adminRole->id, $target->fresh()->role_id);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $a->id, 'user_id' => $target->id, 'role_id' => $readerRole->id,
        ]);

        // Updating ONLY A's membership is allowed and B's role is unchanged.
        $this->putJson('/api/users/'.$target->id, [
            'role_id' => $stockRole->id,
        ])->assertOk()->assertJsonPath('role.name', 'stock')
            ->assertJsonPath('role_id', $stockRole->id);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $a->id, 'user_id' => $target->id, 'role_id' => $stockRole->id,
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $b->id, 'user_id' => $target->id, 'role_id' => $adminRole->id,
        ]);
        $this->assertSame('Account shared-f13@example.test', $target->fresh()->name);
        $this->assertSame($adminRole->id, $target->fresh()->role_id);
    }

    public function test_removing_company_membership_preserves_global_user_and_other_tenants(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'F13_B', 'name' => 'Company B']);
        $adminA = $this->member('admin-remove-f13@example.test', 'admin', [$a->id => 'admin']);
        $shared = $this->member('shared-remove-f13@example.test', 'admin', [
            $a->id => 'reader', $b->id => 'support_n3',
        ]);
        $this->actingAs($adminA);

        $this->deleteJson('/api/users/'.$shared->id)->assertNoContent();
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $a->id, 'user_id' => $shared->id,
        ]);
        $this->assertDatabaseHas('company_user', [
            'company_id' => $b->id, 'user_id' => $shared->id,
            'role_id' => Role::where('name', 'support_n3')->value('id'),
        ]);
        $this->assertDatabaseHas('users', ['id' => $shared->id]);
        $this->getJson('/api/users')->assertOk()->assertJsonMissing(['id' => $shared->id]);

        // Tenant B still owns this user's membership; no accidental global deletion.
        $this->actingAs($shared)->withSession(['active_company_id' => $b->id]);
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('active_company_id', $b->id)
            ->assertJsonPath('role.name', 'support_n3');
    }

    public function test_removing_last_membership_preserves_historical_identity_but_blocks_login(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $admin = $this->member('admin-last-f13@example.test', 'admin', [$a->id => 'admin']);
        $reader = $this->member('reader-last-f13@example.test', 'reader', [$a->id => 'reader']);
        $this->actingAs($admin);

        $this->deleteJson('/api/users/'.$reader->id)->assertNoContent();
        $this->assertDatabaseHas('users', ['id' => $reader->id]);
        $this->assertDatabaseMissing('company_user', [
            'company_id' => $a->id, 'user_id' => $reader->id,
        ]);

        auth('web')->logout();
        // auth:sanctum sets the test application's default guard during the
        // previous request. Reset it to the session guard before a new login.
        \Illuminate\Support\Facades\Auth::shouldUse('web');
        $this->postJson('/api/login', [
            'email' => 'reader-last-f13@example.test',
            'password' => 'PasswordTest2026!',
        ])->assertUnprocessable();

        // A stale authenticated session must still be rejected by
        // tenant middleware once its last membership is revoked.
        $this->actingAs($reader);
        $this->getJson('/api/me')->assertForbidden();
    }
}
