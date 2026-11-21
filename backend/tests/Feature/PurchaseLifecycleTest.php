<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $company, string $email): User
    {
        $roleId=Role::where('name','admin')->value('id');
        $user=User::create(['name'=>$email,'email'=>$email,'password'=>'TestPassword2026!','role_id'=>$roleId]);
        $user->companies()->attach($company->id,['role_id'=>$roleId]);
        return $user;
    }
    public function test_submit_and_approve_require_distinct_admins_and_valid_transition(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $a=$this->admin($company,'purchase-requester@example.test');
        $b=$this->admin($company,'purchase-approver@example.test');
        $this->actingAs($a)->withSession(['active_company_id'=>$company->id]);
        $id=$this->postJson('/api/purchasing/requests',[
            'reference'=>'REQ-DOUBLE','title'=>'New compressor','estimated_total_cents'=>10000,
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/requests/$id/approve")->assertStatus(409);
        $this->postJson("/api/purchasing/requests/$id/submit")->assertOk()
            ->assertJsonPath('status','submitted');
        $this->postJson("/api/purchasing/requests/$id/submit")->assertStatus(409);
        $this->postJson("/api/purchasing/requests/$id/approve")->assertForbidden();
        $this->actingAs($b)->withSession(['active_company_id'=>$company->id]);
        $this->postJson("/api/purchasing/requests/$id/approve")->assertOk()
            ->assertJsonPath('approved_by',$b->id);
        $this->postJson("/api/purchasing/requests/$id/approve")->assertStatus(409);
        $this->assertDatabaseHas('purchase_requests',['id'=>$id,'status'=>'approved','approved_by'=>$b->id]);
    }

    public function test_foreign_company_cannot_transition_request(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'PURCHASE_WORKFLOW_B','name'=>'B']);
        $userA=$this->admin($a,'purchase-company-a@example.test');
        $userB=$this->admin($b,'purchase-company-b@example.test');
        $this->actingAs($userA)->withSession(['active_company_id'=>$a->id]);
        $id=$this->postJson('/api/purchasing/requests',[
            'reference'=>'REQ-ISOLATED','title'=>'A purchase','estimated_total_cents'=>100,
        ])->assertCreated()->json('id');
        $this->actingAs($userB)->withSession(['active_company_id'=>$b->id]);
        $this->postJson("/api/purchasing/requests/$id/submit")->assertNotFound();
        $this->postJson("/api/purchasing/requests/$id/approve")->assertNotFound();
    }
}
