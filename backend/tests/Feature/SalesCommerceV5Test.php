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
        $this->postJson("/api/orders/{$id}/validate")->assertStatus(409);
        $this->postJson("/api/sales-v5/orders/{$id}/reserve",[],$this->requestKey())->assertStatus(409);
        $this->postJson("/api/sales-v5/orders/{$id}/confirm",['document_version'=>0],$this->requestKey())->assertCreated();
        $this->postJson("/api/sales-v5/orders/{$id}/reserve",['document_version'=>0],$this->requestKey())->assertStatus(409);
        $this->postJson("/api/sales-v5/orders/{$id}/reserve",[],$this->requestKey())
            ->assertCreated()->assertJsonPath('status','reserved');
        $this->assertDatabaseHas('products',['id'=>$product->id,'stock'=>7]);
        $payload=['recipient'=>'Client V5','carrier'=>'Transport fictif',
            'lines'=>[['order_item_id'=>$item,'quantity'=>3]]];
        $this->postJson("/api/sales-v5/orders/{$id}/ship",$payload,$this->requestKey())->assertStatus(409);
        $this->postJson("/api/sales-v5/orders/{$id}/prepare",[],$this->requestKey())->assertCreated();
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
        $this->postJson('/api/sales-v5/orders/'.$order['id'].'/confirm',[],$this->requestKey())->assertCreated();
        $this->postJson('/api/sales-v5/orders/'.$order['id'].'/reserve',[],$this->requestKey())->assertCreated();
        $this->postJson('/api/sales-v5/orders/'.$order['id'].'/prepare',[],$this->requestKey())->assertCreated();
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

    public function test_delegated_discount_contract_price_promotion_and_frozen_amounts(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->member('admin','supervisor@example.test');
        $seller=$this->member('commercial','delegate@example.test');
        $this->login($admin,$company);
        [$customer,$product]=$this->fixture();
        $start=now()->toDateString(); $end=now()->addMonth()->toDateString();
        $this->postJson("/api/sales-v5/delegates/{$seller->id}",['discount_limit_bps'=>1000,'site_id'=>null],$this->requestKey())->assertCreated();
        $contract=$this->postJson('/api/sales-v5/contracts',['title'=>'Accord fictif','currency'=>'EUR','customer_id'=>$customer->id,
            'effective_on'=>$start,'expires_on'=>$end,'prices'=>[['product_id'=>$product->id,'price_cents'=>5000]]],$this->requestKey())->assertCreated()->json('id');
        $this->postJson("/api/sales-v5/contracts/{$contract}/publish",[],$this->requestKey())->assertCreated();
        $rule=$this->postJson('/api/sales-v5/pricing',['product_id'=>$product->id,'title'=>'Promo fictive','currency'=>'EUR',
            'priority'=>1,'price_cents'=>6000,'discount_bps'=>1000,'vat_bps'=>2000,'starts_on'=>$start,'ends_on'=>$end],$this->requestKey())->assertCreated()->json('id');
        $this->postJson("/api/sales-v5/pricing/{$rule}/publish",[],$this->requestKey())->assertCreated();
        $this->login($seller,$company);
        $quote=$this->postJson('/api/quotes',['customer_id'=>$customer->id,'items'=>[['product_id'=>$product->id,'quantity'=>2]]])
            ->assertCreated()->assertJsonPath('subtotal_cents',10800)->json('id');
        $rev=$this->postJson("/api/sales-v5/quotes/{$quote}/revisions",['discount_bps'=>0,'contract_id'=>$contract,'valid_until'=>$end],
            $this->requestKey())->assertCreated()->assertJsonPath('subtotal_cents',9000)->assertJsonPath('total_cents',10800)->json('id');
        $this->postJson("/api/sales-v5/revisions/{$rev}/send",[],$this->requestKey())->assertCreated();
        $this->postJson("/api/sales-v5/contracts/{$contract}/amend",['effective_on'=>now()->addDay()->toDateString(),
            'expires_on'=>$end,'terms'=>'Prix futurs','prices'=>[['product_id'=>$product->id,'price_cents'=>7000]]],$this->requestKey())->assertCreated();
        $this->postJson("/api/sales-v5/revisions/{$rev}/accept",[],$this->requestKey())->assertCreated();
        $this->postJson("/api/sales-v5/revisions/{$rev}/convert",[],$this->requestKey())->assertCreated()->assertJsonPath('total_cents',10800);
        $quote=$this->postJson('/api/quotes',['customer_id'=>$customer->id,'items'=>[['product_id'=>$product->id,'quantity'=>2]]])->json('id');
        $rev=$this->postJson("/api/sales-v5/quotes/{$quote}/revisions",['discount_bps'=>1000,'contract_id'=>$contract,'valid_until'=>$end],
            $this->requestKey())->assertCreated()->assertJsonPath('subtotal_cents',8100)->json('id');
        $this->postJson("/api/sales-v5/revisions/{$rev}/send",[],$this->requestKey())->assertCreated();
    }

    public function test_restricted_site_covers_orders_pricing_export_and_idempotent_replays(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->member('admin','regional@example.test');
        $seller=$this->member('commercial','local@example.test');
        $this->login($admin,$company);
        [$customer,$product]=$this->fixture();
        $site=fn($code)=>\Illuminate\Support\Facades\DB::table('establishments')->insertGetId(['company_id'=>$company->id,'name'=>$code,'code'=>$code]);
        $a=$site('M03A'); $b=$site('M03B');
        $this->postJson("/api/sales-v5/delegates/{$seller->id}",['discount_limit_bps'=>0,'site_id'=>$a],$this->requestKey())->assertCreated();
        $data=['establishment_id'=>$b,'customer_id'=>$customer->id,'items'=>[['product_id'=>$product->id,'quantity'=>1]]];
        $order=$this->postJson('/api/orders',$data)->assertCreated()->json('id');
        $key=$this->requestKey();
        $this->postJson('/api/sales-v5/pricing',['establishment_id'=>$b,'product_id'=>$product->id,'title'=>'Secret-site-B','currency'=>'EUR',
            'priority'=>1,'price_cents'=>100,'discount_bps'=>0,'vat_bps'=>2000,'starts_on'=>now()->toDateString(),'ends_on'=>now()->addDay()->toDateString()],$key)->assertCreated();
        $this->login($seller,$company);
        $this->getJson("/api/orders/{$order}")->assertNotFound();
        $this->postJson('/api/orders',$data)->assertForbidden();
        $this->getJson('/api/sales-v5/pricing')->assertOk()->assertJsonCount(0,'data');
        $export=$this->get('/api/sales-v5/pricing/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Secret-site-B',$export);
        $this->getJson('/api/sales-v5/delegates')->assertForbidden();
    }
}
