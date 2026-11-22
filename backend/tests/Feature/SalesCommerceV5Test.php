<?php

namespace Tests\Feature;

use App\Models\{Company,Customer,Product,Role,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesCommerceV5Test extends TestCase
{
    use RefreshDatabase;

    private function member(string $role,string $email,?Company $company=null): User
    {
        $company ??= Company::where('code','ATLAS_DEMO')->firstOrFail();
        $id=Role::where('name',$role)->value('id');
        $user=User::create(['name'=>$email,'email'=>$email,'password'=>'PasswordTest2026!','role_id'=>$id]);
        $user->companies()->attach($company->id,['role_id'=>$id]);
        return $user;
    }

    private function login(User $user,Company $company): void
    {
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }

    private function requestKey(): array
    {
        return ['Idempotency-Key'=>'m03-'.bin2hex(random_bytes(10))];
    }

    private function fixture(): array
    {
        $customer=Customer::create(['name'=>'Client fictif V5','email'=>'m03-'.bin2hex(random_bytes(4)).'@example.test']);
        $product=Product::create(['reference'=>'M03-'.bin2hex(random_bytes(4)),'name'=>'Produit V5',
            'category'=>'Test','price_cents'=>5000,'vat_bps'=>2000,'stock'=>7,'alert_threshold'=>1]);
        return [$customer,$product];
    }

    private function acceptedRevision(User $maker,User $approver,Company $company): array
    {
        $this->login($maker,$company);
        [$customer,$product]=$this->fixture();
        $quote=$this->postJson('/api/quotes',['customer_id'=>$customer->id,
            'items'=>[['product_id'=>$product->id,'quantity'=>5]]])->assertCreated()->json('id');
        $rev=$this->postJson("/api/sales-v5/quotes/{$quote}/revisions",[
            'discount_bps'=>1000,'valid_until'=>now()->addDays(30)->toDateString()
        ],$this->requestKey())->assertCreated()->json('id');
        $approval=$this->getJson('/api/sales-v5/approvals')->assertOk()->json('data.0.id');
        $this->postJson("/api/sales-v5/revisions/{$rev}/send",[],$this->requestKey())->assertStatus(409);
        $this->postJson("/api/sales-v5/approvals/{$approval}/decide",[
            'decision'=>'approved','reason'=>'Remise commerciale validée'
        ],$this->requestKey())->assertForbidden();
        $this->login($approver,$company);
        $this->postJson("/api/sales-v5/approvals/{$approval}/decide",[
            'decision'=>'approved','reason'=>'Remise commerciale validée'
        ],$this->requestKey())->assertCreated();
        $this->login($maker,$company);
        $this->postJson("/api/sales-v5/revisions/{$rev}/send",[],$this->requestKey())->assertCreated();
        $this->postJson("/api/sales-v5/revisions/{$rev}/accept",[],$this->requestKey())->assertCreated();
        return [$quote,$rev,$product];
    }

    public function test_quote_revision_discount_approval_and_immutable_conversion(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $maker=$this->member('commercial','sales-maker-m03@example.test');
        $approver=$this->member('admin','sales-approver-m03@example.test');
        [$quote,$revision]=$this->acceptedRevision($maker,$approver,$company);
        $this->postJson("/api/quotes/{$quote}/convert")->assertStatus(409);
        $key=$this->requestKey();
        $url="/api/sales-v5/revisions/{$revision}/convert";
        $order=$this->postJson($url,[],$key)->assertCreated()
            ->assertJsonPath('fulfillment_mode','reservation_v2')->json();
        $this->postJson($url,[],$key)->assertOk()->assertJsonPath('id',$order['id']);
        $this->postJson($url,[], $this->requestKey())->assertStatus(409);
        $this->assertSame(22500,$order['subtotal_cents']);
        $this->assertSame(27000,$order['total_cents']);
        $this->assertDatabaseCount('orders',1);
    }

    public function test_reserve_then_partially_ship_then_complete_without_double_debit(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $maker=$this->member('commercial','sales-reserver-m03@example.test');
        $approver=$this->member('admin','sales-checker-m03@example.test');
        [, $revision,$product]=$this->acceptedRevision($maker,$approver,$company);
        $order=$this->postJson("/api/sales-v5/revisions/{$revision}/convert",[],$this->requestKey())
            ->assertCreated()->json();
        $id=$order['id'];$item=$order['items'][0]['id'];
        $this->postJson("/api/sales-v5/orders/{$id}/reserve",[],$this->requestKey())
            ->assertCreated()->assertJsonPath('status','reserved');
        $this->assertDatabaseHas('products',['id'=>$product->id,'stock'=>7]);
        $payload=['recipient'=>'Client V5','carrier'=>'Transport fictif',
            'lines'=>[['order_item_id'=>$item,'quantity'=>3]]];
        $key=$this->requestKey();
        $this->postJson("/api/sales-v5/orders/{$id}/ship",$payload,$key)->assertCreated();
        $this->postJson("/api/sales-v5/orders/{$id}/ship",$payload,$key)->assertOk();
        $this->assertDatabaseHas('products',['id'=>$product->id,'stock'=>4]);
        $this->assertDatabaseHas('orders',['id'=>$id,'status'=>'partially_shipped']);
        $this->postJson("/api/sales-v5/orders/{$id}/ship",[
            'recipient'=>'Client V5','lines'=>[['order_item_id'=>$item,'quantity'=>3]]
        ],$this->requestKey())->assertStatus(409);
        $this->postJson("/api/sales-v5/orders/{$id}/ship",[
            'recipient'=>'Client V5','lines'=>[['order_item_id'=>$item,'quantity'=>2]]
        ],$this->requestKey())->assertCreated();
        $this->assertDatabaseHas('products',['id'=>$product->id,'stock'=>2]);
        $this->assertDatabaseHas('orders',['id'=>$id,'status'=>'shipped']);
        $this->postJson('/api/invoices',['order_id'=>$id])->assertCreated();
    }

    public function test_return_quarantine_and_quality_control_never_auto_restock_defective(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $maker=$this->member('commercial','sales-returner-m03@example.test');
        $approver=$this->member('admin','sales-quality-m03@example.test');
        [, $revision,$product]=$this->acceptedRevision($maker,$approver,$company);
        $order=$this->postJson("/api/sales-v5/revisions/{$revision}/convert",[],$this->requestKey())->assertCreated()->json();
        $this->postJson('/api/sales-v5/orders/'.$order['id'].'/reserve',[],$this->requestKey())->assertCreated();
        $delivery=$this->postJson('/api/sales-v5/orders/'.$order['id'].'/ship',[
            'recipient'=>'Client qualité','lines'=>[['order_item_id'=>$order['items'][0]['id'],'quantity'=>5]]
        ],$this->requestKey())->assertCreated()->json();
        $d=$this->getJson('/api/sales-v5/deliveries/'.$delivery['id'])->assertOk()->json();
        $return=$this->postJson('/api/sales-v5/returns',[
            'delivery_id'=>$delivery['id'],'reason'=>'Produit défectueux réceptionné',
            'lines'=>[['delivery_line_id'=>$d['lines'][0]['id'],'quantity'=>1]]
        ],$this->requestKey())->assertCreated()->json();
        $this->assertDatabaseHas('products',['id'=>$product->id,'stock'=>2]);
        $this->login($approver,$company);
        $this->postJson('/api/sales-v5/returns/'.$return['id'].'/inspect',[
            'decision'=>'defective','credit_eligible'=>true
        ],$this->requestKey())->assertCreated();
        $this->assertDatabaseHas('products',['id'=>$product->id,'stock'=>2]);
        $this->assertDatabaseHas('sales_return_lines',['return_id'=>$return['id'],'quality_status'=>'defective']);
    }

    public function test_multi_tenant_read_write_and_reader_denial(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $other=Company::create(['name'=>'Other Business V5','code'=>'OTHER_V5']);
        $maker=$this->member('commercial','sales-a-m03@example.test',$company);
        $outsider=$this->member('commercial','sales-b-m03@example.test',$other);
        $reader=$this->member('reader','sales-r-m03@example.test',$company);
        $this->login($maker,$company);
        [$customer,$product]=$this->fixture();
        $this->postJson('/api/sales-v5/pricing',[
            'product_id'=>$product->id,'title'=>'Grille A','currency'=>'EUR','priority'=>100,
            'price_cents'=>5000,'discount_bps'=>0,'vat_bps'=>2000,
            'starts_on'=>now()->toDateString(),'ends_on'=>now()->addDays(30)->toDateString()
        ],$this->requestKey())->assertCreated();
        $this->login($outsider,$other);
        $this->getJson('/api/sales-v5/pricing')->assertOk()->assertJsonCount(0,'data');
        $this->postJson('/api/sales-v5/pricing',[
            'product_id'=>$product->id,'title'=>'Cross-tenant price'
        ],$this->requestKey())->assertUnprocessable();
        $this->login($reader,$company);
        $this->getJson('/api/sales-v5/pricing')->assertOk()->assertJsonCount(1,'data');
        $this->postJson('/api/sales-v5/pricing',[],$this->requestKey())->assertForbidden();
    }

    public function test_pricing_publish_conflict_and_same_key_payload_mismatch(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->member('admin','sales-pricing-m03@example.test');
        $this->login($admin,$company);
        [, $product]=$this->fixture();
        $payload=[
            'product_id'=>$product->id,'title'=>'Tarif annuel','currency'=>'EUR','priority'=>10,
            'price_cents'=>5000,'discount_bps'=>0,'vat_bps'=>2000,
            'starts_on'=>now()->toDateString(),'ends_on'=>now()->addDays(30)->toDateString()
        ];
        $key=$this->requestKey();
        $a=$this->postJson('/api/sales-v5/pricing',$payload,$key)->assertCreated()->json('id');
        $this->postJson('/api/sales-v5/pricing',$payload,$key)->assertOk()->assertJsonPath('id',$a);
        $this->postJson('/api/sales-v5/pricing',array_merge($payload,['price_cents'=>6000]),$key)->assertStatus(409);
        $b=$this->postJson('/api/sales-v5/pricing',array_merge($payload,['title'=>'Tarif concurrent']),$this->requestKey())->assertCreated()->json('id');
        $this->postJson("/api/sales-v5/pricing/{$a}/publish",[],$this->requestKey())->assertCreated();
        $this->postJson("/api/sales-v5/pricing/{$b}/publish",[],$this->requestKey())->assertStatus(409);
    }
}
