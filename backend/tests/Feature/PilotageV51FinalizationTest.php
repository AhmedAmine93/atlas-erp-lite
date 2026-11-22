<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Services\SalesService;
use App\Services\InvoiceService;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PilotageV51FinalizationTest extends TestCase
{
    use RefreshDatabase;

    private function login(Company $company, string $role, string $suffix): User
    {
        $user=User::create([
            'name'=>'M01 final '.$suffix,
            'email'=>'m01-final-'.$suffix.'@example.test',
            'password'=>'SyntheticM01Password2026!',
            'role_id'=>Role::where('name','admin')->value('id'),
        ]);
        $user->companies()->attach($company->id,[
            'role_id'=>Role::where('name',$role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
        return $user;
    }

    public function test_targets_are_append_only_versioned_and_separate_from_accounting(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->login($company,'admin','target-admin');
        $url='/api/pilotage/targets';
        $base=['metric'=>'issued_ht_cents','period_from'=>'2026-11-01',
            'period_to'=>'2026-11-30','target_value'=>9000,
            'reason'=>'Budget initial approuvé fictif'];
        $this->postJson($url,[...$base,'company_id'=>99])->assertUnprocessable();
        $this->postJson($url,[...$base,'metric'=>'unapproved_metric'])->assertUnprocessable();
        $this->postJson($url,[...$base,'period_to'=>'2026-10-01'])->assertUnprocessable();
        $id=$this->postJson($url,$base)->assertCreated()->assertJsonPath('version',1)->json('id');
        $this->postJson($url,[...$base,'target_value'=>11000,'reason'=>'Révision direction approuvée'])
            ->assertCreated()->assertJsonPath('version',2);
        $this->assertDatabaseHas('pilotage_target_versions',[
            'id'=>$id,'company_id'=>$company->id,'version'=>1,'target_value'=>9000,
        ]);
        $this->postJson($url,[...$base,'target_value'=>11000,'reason'=>'Révision direction approuvée'])
            ->assertOk()->assertJsonPath('version',2)->assertJsonPath('replayed',true);
        $this->assertDatabaseCount('pilotage_target_versions',2);
        $this->getJson('/api/pilotage/direction?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertJsonFragment(['key'=>'target_issued_ht_cents','value'=>11000])
            ->assertJsonFragment(['key'=>'target_issued_ht_delta_cents','value'=>-11000])
            ->assertJsonFragment(['key'=>'target_version','value'=>2]);
        $this->getJson('/api/pilotage/targets?from=2026-11-01&to=2026-11-30')
            ->assertOk()->assertJsonCount(2);
        $this->assertDatabaseHas('audit_logs',[
            'company_id'=>$company->id,'action'=>'pilotage.target.version.created',
        ]);
        $this->assertDatabaseCount('invoices',0);
        $this->assertDatabaseCount('orders',0);
    }

    public function test_cost_centers_and_target_reads_follow_tenant_membership_not_global_role(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M01FINAL_B','name'=>'M01 fictive société B']);
        $this->login($a,'admin','center-a');
        $this->postJson('/api/pilotage/centers',[
            'code'=>'DIRECTION','name'=>'Direction fictive',
        ])->assertCreated();
        $this->postJson('/api/pilotage/centers',[
            'code'=>'DIRECTION','name'=>'Duplicata',
        ])->assertUnprocessable();
        $this->postJson('/api/pilotage/targets',[
            'metric'=>'issued_ht_cents','period_from'=>'2026-11-01',
            'period_to'=>'2026-11-30','target_value'=>1000,
            'reason'=>'Budget financier fictif',
        ])->assertCreated();
        $this->postJson('/api/pilotage/targets',[
            'metric'=>'low_stock_count','period_from'=>'2026-11-01',
            'period_to'=>'2026-11-30','target_value'=>3,
            'reason'=>'Seuil global fictif',
        ])->assertCreated();

        $this->login($a,'stock','center-stock');
        $this->getJson('/api/pilotage/targets')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.metric','low_stock_count');
        $this->postJson('/api/pilotage/centers',[
            'code'=>'FORBIDDEN','name'=>'Mutation interdite',
        ])->assertForbidden();
        $this->postJson('/api/pilotage/targets',[
            'metric'=>'issued_ht_cents','period_from'=>'2026-11-01',
            'period_to'=>'2026-11-30','target_value'=>100,
            'reason'=>'Mutation non autorisée',
        ])->assertForbidden();
        $this->get('/api/pilotage/finance/export?from=2026-11-01&to=2026-11-30')
            ->assertForbidden();
        $this->login($b,'admin','center-b');
        $this->getJson('/api/pilotage/centers')->assertOk()->assertExactJson([]);
        $this->getJson('/api/pilotage/targets')->assertOk()->assertExactJson([]);
        $this->postJson('/api/pilotage/centers',[
            'code'=>'DIRECTION','name'=>'Direction société B',
        ])->assertCreated();
        $this->getJson('/api/pilotage/centers')->assertJsonCount(1);
        $this->assertDatabaseCount('pilotage_cost_centers',2);
    }

    public function test_hotel_occupancy_is_exactly_eight_of_ten_nights_without_invented_revenue(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->login($a,'admin','hotel-a');
        $hotel=DB::table('hotel_properties')->insertGetId([
            'company_id'=>$a->id,'code'=>'H-A','name'=>'Hôtel fictif A',
            'timezone'=>'Europe/Paris','active'=>true,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $room=DB::table('hotel_rooms')->insertGetId([
            'company_id'=>$a->id,'hotel_id'=>$hotel,
            'room_number'=>'101','category'=>'Double','room_status'=>'available',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('hotel_bookings')->insert([
            'company_id'=>$a->id,'hotel_id'=>$hotel,'room_id'=>$room,
            'reference'=>'M01-BOOK-8','guest_display_name'=>'Invité fictif',
            'arrival_date'=>'2026-11-01','departure_date'=>'2026-11-09',
            'status'=>'confirmed','created_by'=>$admin->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->getJson('/api/pilotage/direction?from=2026-11-01&to=2026-11-10')
            ->assertOk()->assertJsonPath('rows.0.theoretical_room_nights',10)
            ->assertJsonPath('rows.0.occupied_room_nights',8)
            ->assertJsonPath('rows.0.indicative_occupancy_pct',80)
            ->assertJsonPath('rows.0.adr_cents',null)
            ->assertJsonPath('rows.0.revpar_cents',null);
        $this->getJson('/api/pilotage/direction?from=2026-11-09&to=2026-11-10')
            ->assertJsonPath('rows.0.occupied_room_nights',0)
            ->assertJsonPath('rows.0.theoretical_room_nights',2);
    }

    public function test_alert_escalation_requires_active_anomaly_and_resolution_requires_ack_and_clearance(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M01FINAL_ALERT_B','name'=>'Fictive société B']);
        $admin=$this->login($a,'admin','alert-a');
        $product=Product::create([
            'reference'=>'M01-FINAL-LOW','name'=>'Produit critique fictif',
            'category'=>'Test','price_cents'=>150,'vat_bps'=>2000,
            'stock'=>1,'alert_threshold'=>5,
        ]);
        $rule=$this->postJson('/api/pilotage/alerts/rules',[
            'name'=>'Stock critique M01','metric'=>'low_stock','threshold'=>3,
            'effective_from'=>now()->subDay()->toDateString(),'owner_id'=>$admin->id,
        ])->assertCreated()->json('id');
        $action=['rule_id'=>$rule,'entity_type'=>'product','entity_id'=>$product->id,
            'action'=>'escalated','reason'=>'Urgence opérationnelle fictive'];
        $this->postJson('/api/pilotage/alerts/transition',$action)->assertCreated();
        $this->postJson('/api/pilotage/alerts/transition',$action)->assertOk()
            ->assertJsonPath('replayed',true);
        $this->postJson('/api/pilotage/alerts/transition',[
            ...$action,'reason'=>'Motif incompatible',
        ])->assertStatus(409);
        $this->postJson('/api/pilotage/alerts/transition',[
            ...$action,'action'=>'resolved','reason'=>'Stock vérifié par inventaire',
        ])->assertStatus(409);
        $this->postJson('/api/pilotage/alerts/acknowledge',[
            'rule_id'=>$rule,'entity_type'=>'product','entity_id'=>$product->id,
            'comment'=>'Prise en charge du stock',
        ])->assertOk();
        $this->getJson('/api/pilotage/alerts')->assertJsonPath('rows.0.is_active',true)
            ->assertJsonPath('rows.0.acknowledged_at',fn ($value) => $value !== null);
        $product->update(['stock'=>10]);
        $this->getJson('/api/pilotage/alerts')->assertOk()
            ->assertJsonPath('cards.3.value',1)
            ->assertJsonPath('rows.0.is_active',false);
        $this->postJson('/api/pilotage/alerts/transition',[
            ...$action,'action'=>'resolved','reason'=>'Stock vérifié par inventaire',
        ])->assertCreated();
        $this->getJson('/api/pilotage/alerts')->assertJsonPath('rows',[]);
        $this->assertDatabaseCount('pilotage_alert_events',2);
        $this->login($b,'admin','alert-b');
        $this->postJson('/api/pilotage/alerts/transition',$action)->assertNotFound();
        $this->assertDatabaseCount('pilotage_alert_events',2);
    }

    public function test_csv_export_is_audited_and_escapes_spreadsheet_formulas(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->login($company,'admin','export');
        DB::table('hotel_properties')->insert([
            'company_id'=>$company->id,'code'=>'CSV-01',
            'name'=>'=HYPERLINK("https://example.invalid")',
            'timezone'=>'Europe/Paris','active'=>true,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $response=$this->get('/api/pilotage/direction/export?from=2026-11-01&to=2026-11-10')
            ->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $csv=$response->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('indicative_occupancy_pct', $csv);
        $this->assertDatabaseHas('audit_logs',[
            'company_id'=>$company->id,'action'=>'pilotage.export',
        ]);
    }
    public function test_invoice_revenue_is_reconciled_with_issued_documents_and_cancelled_excluded(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->login($company,'admin','reconcile');
        $customer=Customer::create([
            'name'=>'Client fictif M01',
            'email'=>'m01-revenue@example.test',
        ]);
        $product=Product::create([
            'reference'=>'M01-REV','name'=>'Produit facturable fictif',
            'category'=>'Recette','price_cents'=>5000,'vat_bps'=>2000,
            'stock'=>10,'alert_threshold'=>1,
        ]);
        $day=now()->toDateString();
        $path="/api/pilotage/finance?from={$day}&to={$day}";
        $quote=app(SalesService::class)->create('quotes',[
            'customer_id'=>$customer->id,
            'items'=>[['product_id'=>$product->id,'quantity'=>2]],
        ]);
        $this->getJson($path)->assertJsonPath('cards.0.value',0);
        $order=app(SalesService::class)->convert($quote->id);
        app(SalesService::class)->validateOrder($order->id);
        $this->getJson($path)->assertJsonPath('cards.0.value',0);
        $invoice=app(InvoiceService::class)->create($order->id);
        $this->getJson($path)->assertJsonPath('cards.0.value',0);
        app(InvoiceService::class)->transition($invoice->id,'issued');
        $this->getJson($path)->assertJsonPath('cards.0.value',10000)
            ->assertJsonPath('cards.1.value',12000)
            ->assertJsonPath('cards.2.value',12000)
            ->assertJsonPath('cards.3.value',0);
        $this->getJson("/api/pilotage/direction?from={$day}&to={$day}")
            ->assertJsonPath('cards.0.value',10000)
            ->assertJsonPath('cards.1.value',12000);
        app(InvoiceService::class)->transition($invoice->id,'cancelled');
        $this->getJson($path)->assertJsonPath('cards.0.value',0)
            ->assertJsonPath('cards.2.value',0)
            ->assertJsonPath('cards.4.value',1);
        $this->assertDatabaseHas('invoices',[
            'id'=>$invoice->id,'status'=>'cancelled','subtotal_cents'=>10000,
            'total_cents'=>12000,
        ]);
    }

}
