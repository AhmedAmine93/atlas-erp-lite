<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdministrationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function userIn(Company $company, string $roleName, string $email): User
    {
        $roleId = Role::where('name', $roleName)->value('id');
        $user = User::create([
            'name' => 'Security Fixture', 'email' => $email,
            'password' => 'PasswordTest2026!', 'role_id' => $roleId,
        ]);
        $user->companies()->attach($company->id, ['role_id' => $roleId]);

        return $user;
    }

    public function test_roles_settings_and_series_are_authorized_only_with_active_tenant_admin(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code'=>'M17_B','name'=>'M17 company B']);
        $admin = $this->userIn($a,'admin','m17-admin@example.test');
        $reader = $this->userIn($b,'reader','m17-reader@example.test');

        $this->actingAs($admin)->withSession(['active_company_id'=>$a->id]);
        $this->getJson('/api/admin/roles')->assertOk()->assertJsonPath('policy_mode','read-only-application-gates');
        $this->postJson('/api/admin/settings', [
            'key'=>'timezone','value'=>'Europe/Paris',
            'effective_at'=>now()->addDays(2)->toDateString(),'expected_version'=>0,
        ])->assertCreated()->assertJsonPath('version',1);
        $this->postJson('/api/admin/settings', [
            'key'=>'timezone','value'=>'Europe/London',
            'effective_at'=>now()->addDays(3)->toDateString(),'expected_version'=>0,
        ])->assertConflict();
        $this->postJson('/api/admin/series', [
            'document_type'=>'invoice','period_year'=>(int)now()->format('Y'),'prefix'=>'FAC',
        ])->assertCreated();
        $this->getJson('/api/admin/series')->assertOk()->assertJsonPath('total',1);

        $this->actingAs($reader)->withSession(['active_company_id'=>$b->id]);
        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->postJson('/api/admin/series', [
            'document_type'=>'invoice','period_year'=>(int)now()->format('Y'),'prefix'=>'FAC',
        ])->assertForbidden();
    }

    public function test_document_series_idempotency_collision_and_tenant_isolation(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code'=>'M17_B','name'=>'Company B']);
        $adminA = $this->userIn($a,'admin','m17-a@example.test');
        $adminB = $this->userIn($b,'admin','m17-b@example.test');
        $this->actingAs($adminA)->withSession(['active_company_id'=>$a->id]);

        $seriesId = $this->postJson('/api/admin/series', [
            'document_type'=>'invoice','period_year'=>(int)now()->format('Y'),'prefix'=>'A',
        ])->assertCreated()->json('id');
        $first = $this->postJson('/api/admin/series/'.$seriesId.'/allocate',[
            'operation_key'=>'m17:invoice:001',
        ])->assertCreated()->json('document_number');
        $replayed = $this->postJson('/api/admin/series/'.$seriesId.'/allocate',[
            'operation_key'=>'m17:invoice:001',
        ])->assertOk()->json('document_number');
        $this->assertSame($first,$replayed);
        $this->assertDatabaseCount('admin_document_allocations',1);

        $this->actingAs($adminB)->withSession(['active_company_id'=>$b->id]);
        $this->getJson('/api/admin/allocations')->assertOk()->assertJsonPath('total',0);
        $this->postJson('/api/admin/series/'.$seriesId.'/allocate', [
            'operation_key'=>'m17:invoice:002',
        ])->assertNotFound();
    }

    public function test_suspension_revokes_access_without_destroying_historical_identity(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code'=>'M17_B','name'=>'Company B']);
        $admin = $this->userIn($a,'admin','m17-admin2@example.test');
        $member = $this->userIn($a,'reader','m17-member@example.test');
        $member->companies()->attach($b->id, ['role_id'=>Role::where('name','reader')->value('id')]);

        $this->actingAs($admin)->withSession(['active_company_id'=>$a->id]);
        $this->postJson('/api/admin/memberships/'.$member->id.'/suspend',[
            'reason'=>'Suspension administrative documentée',
        ])->assertOk()->assertJsonPath('status','suspended');
        $this->assertNotNull(DB::table('company_user')->where('company_id',$a->id)->where('user_id',$member->id)->value('suspended_at'));
        $this->assertDatabaseHas('users',['id'=>$member->id]);

        $this->actingAs($member)->withSession(['active_company_id'=>$a->id]);
        $this->getJson('/api/me')->assertForbidden();
        $this->actingAs($member)->withSession(['active_company_id'=>$b->id]);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role.name','reader');
    }

    public function test_last_admin_cannot_suspend_themself_or_be_suspended(): void
    {
        $a = Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin = $this->userIn($a,'admin','m17-sole@example.test');
        $this->actingAs($admin)->withSession(['active_company_id'=>$a->id]);
        $this->postJson('/api/admin/memberships/'.$admin->id.'/suspend',[
            'reason'=>'Suspension interdite',
        ])->assertConflict();
    }
}
