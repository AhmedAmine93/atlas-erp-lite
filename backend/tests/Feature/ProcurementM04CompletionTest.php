<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcurementM04CompletionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $company,string $name): User
    {
        $roleId=Role::where('name','admin')->value('id');
        $user=User::create(['name'=>$name,'email'=>'m04-completion-'.$name.'@example.test',
            'password'=>'TestPassword2026!','role_id'=>$roleId]);
        $user->companies()->attach($company->id,['role_id'=>$roleId]);
        return $user;
    }
    private function as(User $user, Company $company): void
    {
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }

    public function test_reaward_amendment_service_acceptance_and_invoice_matching(): void
    {
        $tenant=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $requester=$this->admin($tenant,'Requester');
        $buyer=$this->admin($tenant,'Buyer');
        $this->as($requester,$tenant);
        $supplier1=$this->postJson('/api/suppliers',[
            'name'=>'Supplier One','email'=>'first-m04@example.test'])->assertCreated()->json('id');
        $supplier2=$this->postJson('/api/suppliers',[
            'name'=>'Supplier Two','email'=>'second-m04@example.test'])->assertCreated()->json('id');
        $requestId=$this->postJson('/api/purchasing/requests',[
            'reference'=>'REQ-COMPLETE-01','title'=>'Consulting hours',
            'estimated_total_cents'=>20000
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/requests/$requestId/submit")->assertOk();
        $this->as($buyer,$tenant);
        $this->postJson("/api/purchasing/requests/$requestId/approve")->assertOk();
        $quoteIds=[];
        foreach([$supplier1,$supplier2] as $i=>$supplier){
            $quoteIds[]=$this->postJson('/api/purchasing/quotes',[
                'purchase_request_id'=>$requestId,'supplier_id'=>$supplier,
                'reference'=>'Q-COMPLETE-'.$i,'currency'=>'EUR',
                'subtotal_cents'=>20000,'tax_cents'=>0,'delivery_days'=>4,
                'valid_until'=>now()->addWeek()->toDateString()
            ])->assertCreated()->json('id');
        }
        $comparison=$this->getJson("/api/purchasing/requests/$requestId/quotes/compare")
            ->assertOk()->assertJsonPath('currency','EUR')->json('offers');
        $this->assertCount(2,$comparison);
        $this->postJson("/api/purchasing/quotes/{$quoteIds[0]}/award",[
            'reason'=>'Reliable supplier and lead time'
        ])->assertOk();
        $this->postJson("/api/purchasing/quotes/{$quoteIds[1]}/award",[
            'reason'=>'New evidence on the delivery date'
        ])->assertOk()->assertJsonPath('status','awarded');
        $this->assertDatabaseHas('supplier_quotes',['id'=>$quoteIds[0],'status'=>'superseded']);
        $this->getJson("/api/purchasing/requests/$requestId/awards")
            ->assertOk()->assertJsonCount(2,'data');

        $orderId=$this->postJson('/api/purchasing/orders',[
            'reference'=>'PO-COMPLETE-001','supplier_quote_id'=>$quoteIds[1],
            'lines'=>[['description'=>'Consulting services','quantity'=>10,
                'unit_price_cents'=>2000,'vat_bps'=>0]]
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/orders/$orderId/transition",['action'=>'send'])
            ->assertOk();
        $line=$this->getJson("/api/purchasing/orders/$orderId/lines")
            ->assertOk()->json('data.0.id');

        $amendment=$this->postJson("/api/purchasing/orders/$orderId/amendments",[
            'purchase_order_line_id'=>$line,'new_quantity'=>8,
            'reason'=>'Reduce the remaining service commitment'
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/amendments/$amendment/decision",[
            'decision'=>'approved'
        ])->assertForbidden();
        $this->as($requester,$tenant);
        $this->postJson("/api/purchasing/amendments/$amendment/decision",[
            'decision'=>'approved'
        ])->assertOk();
        $this->getJson('/api/purchasing/orders')
            ->assertOk()->assertJsonPath('data.0.total_cents',16000);
        $this->getJson("/api/purchasing/orders/$orderId/amendments")
            ->assertOk()->assertJsonCount(1,'data');

        $service=[
            'purchase_order_line_id'=>$line,'reference'=>'PV-SVC-001',
            'accepted_quantity'=>6,'evidence_reference'=>'SIGNED-PV-001'
        ];
        $this->postJson("/api/purchasing/orders/$orderId/services",$service)
            ->assertCreated();
        $this->postJson("/api/purchasing/orders/$orderId/services",$service)
            ->assertOk();
        $this->assertDatabaseCount('purchase_service_acceptances',1);
        $this->postJson("/api/purchasing/orders/$orderId/services",[
            ...$service,'accepted_quantity'=>7
        ])->assertStatus(409);
        $this->getJson("/api/purchasing/orders/$orderId/services")
            ->assertOk()->assertJsonCount(1,'data');

        $invoiceId=$this->postJson('/api/purchasing/invoices',[
            'purchase_order_id'=>$orderId,'reference'=>'CONSULT-001',
            'total_cents'=>16000,
        ])->assertCreated()->assertJsonPath('matched_cents',12000)
            ->assertJsonPath('dispute_cents',4000)
            ->assertJsonPath('status','disputed')->json('id');
        $this->postJson("/api/purchasing/invoices/$invoiceId/authorize-payment")
            ->assertStatus(409);
        $this->postJson("/api/purchasing/orders/$orderId/services",[
            'purchase_order_line_id'=>$line,'reference'=>'PV-SVC-002',
            'accepted_quantity'=>2,'evidence_reference'=>'SIGNED-PV-002'
        ])->assertCreated();
        $this->postJson("/api/purchasing/invoices/$invoiceId/rematch")
            ->assertOk()->assertJsonPath('matched_cents',16000)
            ->assertJsonPath('dispute_cents',0)->assertJsonPath('status','matched');
        $this->postJson("/api/purchasing/invoices/$invoiceId/authorize-payment")
            ->assertForbidden();
        $this->as($buyer,$tenant);
        $this->postJson("/api/purchasing/invoices/$invoiceId/authorize-payment")
            ->assertCreated()->assertJsonPath('authorized_amount_cents',16000)
            ->assertJsonPath('status','ready_for_payment');
        $this->getJson("/api/purchasing/invoices/$invoiceId/authorizations")
            ->assertOk()->assertJsonCount(1,'data');
        $this->postJson("/api/purchasing/invoices/$invoiceId/authorize-payment")
            ->assertStatus(409);
        $this->postJson("/api/purchasing/invoices/$invoiceId/credits",[
            'reference'=>'CREDIT-AFTER-APPROVAL','amount_cents'=>100
        ])->assertStatus(409);
    }

    public function test_service_acceptance_needs_independent_signer_and_cannot_exceed_order(): void
    {
        $tenant=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $requester=$this->admin($tenant,'Req2');
        $buyer=$this->admin($tenant,'Buyer2');
        $this->as($requester,$tenant);
        $supplier=$this->postJson('/api/suppliers',[
            'name'=>'Service supplier','email'=>'service-m04@example.test'
        ])->assertCreated()->json('id');
        $requestId=$this->postJson('/api/purchasing/requests',[
            'reference'=>'SRV-REQ-01','title'=>'Service',
            'estimated_total_cents'=>1000
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/requests/$requestId/submit")->assertOk();
        $this->as($buyer,$tenant);
        $this->postJson("/api/purchasing/requests/$requestId/approve")->assertOk();
        $quote=$this->postJson('/api/purchasing/quotes',[
            'purchase_request_id'=>$requestId,'supplier_id'=>$supplier,
            'reference'=>'SVC-QUOTE','currency'=>'EUR','subtotal_cents'=>1000,
            'tax_cents'=>0,'delivery_days'=>1,'valid_until'=>now()->addWeek()->toDateString()
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/quotes/$quote/award",[
            'reason'=>'Best documented service response'
        ])->assertOk();
        $order=$this->postJson('/api/purchasing/orders',[
            'reference'=>'SERVICE-PO','supplier_quote_id'=>$quote,
            'lines'=>[['description'=>'Service','quantity'=>2,'unit_price_cents'=>500,'vat_bps'=>0]]
        ])->assertCreated()->json('id');
        $this->postJson("/api/purchasing/orders/$order/transition",['action'=>'send'])->assertOk();
        $line=$this->getJson("/api/purchasing/orders/$order/lines")->json('data.0.id');
        $payload=['purchase_order_line_id'=>$line,'reference'=>'PV-SERVICE-02',
            'accepted_quantity'=>2,'evidence_reference'=>'SIGNED-REPORT-02'];
        $this->postJson("/api/purchasing/orders/$order/services",$payload)->assertForbidden();
        $this->as($requester,$tenant);
        $this->postJson("/api/purchasing/orders/$order/services",$payload)->assertCreated();
        $this->postJson("/api/purchasing/orders/$order/services",[
            ...$payload,'reference'=>'PV-SERVICE-03'
        ])->assertStatus(409);
        $this->assertDatabaseCount('purchase_service_acceptances',1);
    }
}
