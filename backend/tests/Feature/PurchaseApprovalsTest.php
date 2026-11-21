<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private function member(Company $company, string $role, string $email): User
    {
        $u = User::create([
            'name' => $email, 'email' => $email, 'password' => 'SyntheticPassword2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $u->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        return $u;
    }

    private function asCompany(User $u, Company $c): void
    {
        $this->actingAs($u)->withSession(['active_company_id' => $c->id]);
    }

    public function test_separation_of_duties_and_atomic_transition_history(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $owner = $this->member($a, 'admin', 'purchase-owner@example.test');
        $approver = $this->member($a, 'admin', 'purchase-approver@example.test');
        $reader = $this->member($a, 'reader', 'purchase-reader@example.test');
        $this->asCompany($owner, $a);
        $id = $this->postJson('/api/purchasing/requests', [
            'reference' => 'BUY-01', 'title' => 'Safety valve', 'estimated_total_cents' => 50000,
        ])->assertCreated()->json('id');

        $this->postJson("/api/purchasing/requests/$id/decision", [
            'decision' => 'approved', 'reason' => 'Validated',
        ])->assertStatus(409);
        $this->postJson("/api/purchasing/requests/$id/submit")->assertOk()
            ->assertJsonPath('status', 'submitted');
        $this->postJson("/api/purchasing/requests/$id/submit")->assertStatus(409);
        $this->postJson("/api/purchasing/requests/$id/decision", [
            'decision' => 'approved', 'reason' => 'Valid business expense',
        ])->assertForbidden();
        $this->assertDatabaseHas('purchase_requests', ['id' => $id, 'status' => 'submitted']);

        $this->asCompany($reader, $a);
        $this->postJson("/api/purchasing/requests/$id/decision", [
            'decision' => 'approved', 'reason' => 'Not authorized',
        ])->assertForbidden();

        $this->asCompany($approver, $a);
        $this->postJson("/api/purchasing/requests/$id/decision", [
            'decision' => 'approved', 'reason' => 'Validated estimate',
        ])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson("/api/purchasing/requests/$id/decision", [
            'decision' => 'rejected', 'reason' => 'Trying duplicate',
        ])->assertStatus(409);
        $this->getJson("/api/purchasing/requests/$id/history")->assertOk()
            ->assertJsonCount(2)->assertJsonPath('1.to_status', 'approved');
        $this->assertDatabaseCount('purchase_approval_events', 2);
        $this->assertDatabaseHas('purchase_requests', ['id' => $id, 'company_id' => $a->id, 'status' => 'approved']);
    }

    public function test_invalid_amount_and_cross_tenant_transition_are_rejected(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'PURCH_APPROVAL_B', 'name' => 'Purchase company B']);
        $maker = $this->member($a, 'admin', 'purchase-maker-b@example.test');
        $other = $this->member($b, 'admin', 'purchase-other-b@example.test');
        $this->asCompany($maker, $a);
        $zero = $this->postJson('/api/purchasing/requests', [
            'reference' => 'BUY-02', 'title' => 'Zero budget', 'estimated_total_cents' => 0,
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/requests/$zero/submit")->assertStatus(422);
        $this->asCompany($other, $b);
        $this->postJson("/api/purchasing/requests/$zero/submit")->assertNotFound();
        $this->postJson("/api/purchasing/requests/$zero/decision", [
            'decision' => 'approved', 'reason' => 'Foreign request',
        ])->assertNotFound();
        $this->getJson("/api/purchasing/requests/$zero/history")->assertNotFound();
    }
}
