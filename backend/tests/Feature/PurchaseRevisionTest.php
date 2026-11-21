<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRevisionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $company, string $suffix): User
    {
        $role = Role::where('name', 'admin')->value('id');
        $user = User::create(['name' => 'Purchases',
            'email' => 'purchase-rev-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!', 'role_id' => $role]);
        $user->companies()->attach($company->id, ['role_id' => $role]);
        return $user;
    }

    public function test_rejection_reason_revision_and_resubmission_require_distinct_decision_maker(): void
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $requester = $this->admin($company, 'requester');
        $approver = $this->admin($company, 'approver');
        $this->actingAs($requester)->withSession(['active_company_id' => $company->id]);
        $id = $this->postJson('/api/purchasing/requests', [
            'reference' => 'REQ-REVISE', 'title' => 'Synthetic pump',
            'estimated_total_cents' => 100000,
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/requests/$id/submit")->assertOk();
        $this->actingAs($approver)->withSession(['active_company_id' => $company->id]);
        $this->postJson("/api/purchasing/requests/$id/reject", [
            'reason' => 'x',
        ])->assertUnprocessable();
        $this->postJson("/api/purchasing/requests/$id/reject", [
            'reason' => 'Budget insuffisant',
        ])->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson("/api/purchasing/requests/$id/reject", [
            'reason' => 'Duplicate rejection',
        ])->assertStatus(409);
        $this->patchJson("/api/purchasing/requests/$id/revise", [
            'estimated_total_cents' => 90000,
        ])->assertForbidden();
        $this->actingAs($requester)->withSession(['active_company_id' => $company->id]);
        $this->patchJson("/api/purchasing/requests/$id/revise", [
            'estimated_total_cents' => 90000,
        ])->assertOk()->assertJsonPath('revision', 2)->assertJsonPath('status', 'draft');
        $this->postJson("/api/purchasing/requests/$id/submit")->assertOk();
        $this->actingAs($approver)->withSession(['active_company_id' => $company->id]);
        $this->postJson("/api/purchasing/requests/$id/approve")->assertOk()
            ->assertJsonPath('status', 'approved');
        $this->actingAs($requester)->withSession(['active_company_id' => $company->id]);
        $this->patchJson("/api/purchasing/requests/$id/revise", [
            'estimated_total_cents' => 80000,
        ])->assertStatus(409);
    }

    public function test_foreign_tenant_cannot_revise(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'PUR_REVISE_B', 'name' => 'Synthetic B']);
        $this->actingAs($this->admin($a, 'a'))->withSession(['active_company_id' => $a->id]);
        $id = $this->postJson('/api/purchasing/requests', [
            'reference' => 'T-A', 'title' => 'A', 'estimated_total_cents' => 500,
        ])->assertCreated()->json('id');
        $this->actingAs($this->admin($b, 'b'))->withSession(['active_company_id' => $b->id]);
        $this->patchJson("/api/purchasing/requests/$id/revise", [
            'title' => 'Illegal',
        ])->assertNotFound();
        $this->postJson("/api/purchasing/requests/$id/reject", [
            'reason' => 'Wrong tenant',
        ])->assertNotFound();
    }
}
