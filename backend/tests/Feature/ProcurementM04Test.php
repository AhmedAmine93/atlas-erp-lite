<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementM04Test extends TestCase
{
    use RefreshDatabase;

    private function member(Company $company, string $name, string $role = 'admin'): User
    {
        $adminId = Role::where('name','admin')->value('id');
        $roleId = Role::where('name',$role)->value('id');
        $user = User::create([
            'name' => $name, 'email' => 'm04-'.strtolower($name).'@example.test',
            'password' => 'TestPassword2026!', 'role_id' => $adminId,
        ]);
        $user->companies()->attach($company->id,['role_id'=>$roleId]);
        return $user;
    }

    private function as(User $user, Company $company): void
    {
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }

    private function supplier(): int
    {
        return $this->postJson('/api/suppliers',[
            'name'=>'Fournisseur test M04', 'email'=>'m04-supplier@example.test'
        ])->assertCreated()->json('id');
    }

    private function approvedRequest(User $requester, User $approver, Company $company): int
    {
        $this->as($requester,$company);
        $id = $this->postJson('/api/purchasing/requests',[
            'reference'=>'DA-M04-001', 'title'=>'Pièces d’équipement',
            'estimated_total_cents'=>20000,'currency'=>'EUR'
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/requests/$id/submit")->assertOk();
        $this->postJson("/api/purchasing/requests/$id/approve")->assertForbidden();
        $this->as($approver,$company);
        $this->postJson("/api/purchasing/requests/$id/approve")->assertOk();
        return $id;
    }

    public function test_complete_receipt_replay_three_way_match_and_credit(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $buyer=$this->member($company,'Buyer');
        $approver=$this->member($company,'Approver');
        $this->as($buyer,$company);
        $supplier=$this->supplier();
        $req=$this->approvedRequest($buyer,$approver,$company);
        $quote=$this->postJson('/api/purchasing/quotes',[
            'purchase_request_id'=>$req,'supplier_id'=>$supplier,
            'reference'=>'OFFRE-001','currency'=>'EUR','subtotal_cents'=>20000,
            'tax_cents'=>0,'shipping_cents'=>0,'delivery_days'=>5,
            'valid_until'=>now()->addDays(7)->toDateString(),
        ])->assertCreated()->json('id');
        // Separate approval actor may award, requester may not.
        $this->as($buyer,$company);
        $this->postJson("/api/purchasing/quotes/$quote/award",['reason'=>'Prix et délai'])
            ->assertForbidden();
        $this->as($approver,$company);
        $this->postJson("/api/purchasing/quotes/$quote/award",['reason'=>'Prix et délai favorables'])
            ->assertOk()->assertJsonPath('status','awarded');
        $order=$this->postJson('/api/purchasing/orders',[
            'reference'=>'CMD-001','supplier_quote_id'=>$quote,
            'lines'=>[['description'=>'Pièces', 'quantity'=>10,'unit_price_cents'=>2000,'vat_bps'=>0]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/orders/$order/transition",['action'=>'send'])
            ->assertOk()->assertJsonPath('status','sent');
        $line=$this->getJson("/api/purchasing/orders/$order/lines")->assertOk()->json('data.0.id');
        $receipt=['purchase_order_id'=>$order,'reference'=>'BR-001',
            'lines'=>[['purchase_order_line_id'=>$line,
                'received_quantity'=>8,'accepted_quantity'=>8,'rejected_quantity'=>0]]];
        $this->postJson('/api/purchasing/receipts',$receipt)->assertCreated();
        // Same reference + same request body returns the original receipt, not another stock posting.
        $this->postJson('/api/purchasing/receipts',$receipt)->assertOk();
        $this->assertDatabaseCount('purchase_receipts',1);
        $this->postJson('/api/purchasing/receipts',[
            'purchase_order_id'=>$order,'reference'=>'BR-001',
            'lines'=>[['purchase_order_line_id'=>$line,'received_quantity'=>2,
                'accepted_quantity'=>2,'rejected_quantity'=>0]]
        ])->assertStatus(409);
        $invoice=$this->postJson('/api/purchasing/invoices',[
            'purchase_order_id'=>$order,'reference'=>'F-001','total_cents'=>20000,
        ])->assertCreated()->assertJsonPath('matched_cents',16000)
            ->assertJsonPath('dispute_cents',4000)
            ->assertJsonPath('status','disputed')->json('id');
        $this->postJson('/api/purchasing/invoices',[
            'purchase_order_id'=>$order,'reference'=>' F-001 ','total_cents'=>20000,
        ])->assertStatus(409);
        $this->postJson("/api/purchasing/invoices/$invoice/credits",[
            'reference'=>'AV-001','amount_cents'=>4000
        ])->assertCreated();
        $this->getJson("/api/purchasing/invoices/$invoice/credits")->assertOk()->assertJsonCount(1,'data');
    }

    public function test_cross_tenant_and_reader_denials(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M04_B','name'=>'Seconde entreprise']);
        $buyer=$this->member($a,'TenantA');
        $other=$this->member($b,'TenantB');
        $reader=$this->member($a,'Reader','reader');

        $this->as($buyer,$a);
        $supplier=$this->supplier();
        $this->as($other,$b);
        $this->postJson("/api/purchasing/suppliers/$supplier/status",
            ['status'=>'blocked','reason'=>'Non conforme'])->assertNotFound();
        $this->getJson("/api/purchasing/suppliers/$supplier/bank-changes")->assertNotFound();
        $this->getJson('/api/purchasing/suppliers')->assertJsonPath('total',0);

        $this->as($reader,$a);
        $this->getJson('/api/purchasing/orders')->assertForbidden();
        $this->postJson('/api/purchasing/quotes',[])->assertForbidden();
        $this->getJson('/api/purchasing/invoices')->assertForbidden();
    }

    public function test_bank_change_must_be_approved_by_second_actor_and_never_exposes_iban(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $a=$this->member($company,'BankRequester');
        $b=$this->member($company,'BankApprover');
        $this->as($a,$company);
        $supplier=$this->supplier();
        $id=$this->postJson("/api/purchasing/suppliers/$supplier/bank-changes",[
            'iban'=>'FR7630006000011234567890189'
        ])->assertCreated()->assertJsonMissing(['encrypted_iban'])->json('id');
        $this->getJson("/api/purchasing/suppliers/$supplier/bank-changes")
            ->assertOk()->assertJsonMissing(['encrypted_iban']);
        $this->postJson("/api/purchasing/bank-changes/$id/decision",
            ['decision'=>'approved'])->assertForbidden();
        $this->as($b,$company);
        $this->postJson("/api/purchasing/bank-changes/$id/decision",
            ['decision'=>'approved'])->assertOk()->assertJsonPath('status','approved');
        $this->postJson("/api/purchasing/bank-changes/$id/decision",
            ['decision'=>'approved'])->assertStatus(409);
    }

    public function test_approved_request_revision_forces_new_approval(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $a=$this->member($company,'Reviser');
        $b=$this->member($company,'Validator');
        $id=$this->approvedRequest($a,$b,$company);
        $this->as($a,$company);
        $this->putJson("/api/purchasing/requests/$id/lines",[
            'lines'=>[['description'=>'Compresseur', 'quantity'=>10,
                'unit_price_cents'=>2000,'vat_bps'=>0]]
        ])->assertOk()->assertJsonPath('status','draft')
            ->assertJsonPath('revision',2);
        $this->getJson("/api/purchasing/requests/$id/history")
            ->assertOk()->assertJsonCount(1,'data');
        $this->postJson("/api/purchasing/requests/$id/submit")->assertOk();
        $this->postJson("/api/purchasing/requests/$id/approve")->assertForbidden();
        $this->as($b,$company);
        $this->postJson("/api/purchasing/requests/$id/approve")->assertOk();
    }

    public function test_expired_quote_cannot_be_awarded_and_supplier_block_prevents_order(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $a=$this->member($company,'Requester');
        $b=$this->member($company,'Decider');
        $this->as($a,$company); $supplier=$this->supplier();
        $req=$this->approvedRequest($a,$b,$company);
        $quote=$this->postJson('/api/purchasing/quotes',[
            'purchase_request_id'=>$req,'supplier_id'=>$supplier,'reference'=>'EXPIRED',
            'currency'=>'EUR','subtotal_cents'=>20000,'tax_cents'=>0,
            'delivery_days'=>4,'valid_until'=>now()->subDay()->toDateString()
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/quotes/$quote/award",['reason'=>'Offre expirée'])
            ->assertStatus(409);
        $this->postJson("/api/purchasing/suppliers/$supplier/status",[
            'status'=>'blocked','reason'=>'Non conformité documentée'
        ])->assertOk();
        $this->postJson('/api/purchasing/quotes',[
            'purchase_request_id'=>$req,'supplier_id'=>$supplier,'reference'=>'BLOCKED',
            'currency'=>'EUR','subtotal_cents'=>20000,'tax_cents'=>0,
            'delivery_days'=>4,'valid_until'=>now()->addWeek()->toDateString()
        ])->assertStatus(409);
    }
}
