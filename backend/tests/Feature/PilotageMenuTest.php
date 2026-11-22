<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PilotageMenuTest extends TestCase
{
    use RefreshDatabase;

    private function member(Company $company, string $role, string $email): User
    {
        $user = User::create([
            'name' => 'Pilotage '.$role,
            'email' => $email,
            'password' => 'PilotageSynthetic2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);

        return $user;
    }

    private function authenticate(User $user, Company $company): void
    {
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    public function test_five_submenus_are_available_to_authorized_roles_only(): void
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $admin = $this->member($company,'admin','pilotage-admin@example.test');
        $stock = $this->member($company,'stock','pilotage-stock@example.test');
        $support = $this->member($company,'support_n2','pilotage-support@example.test');

        $this->authenticate($admin,$company);
        foreach (['direction','sales','finance','logistics','alerts'] as $section) {
            $this->getJson('/api/pilotage/'.$section)->assertOk()
                ->assertJsonPath('scope','Entreprise active uniquement');
        }
        $this->authenticate($stock,$company);
        foreach (['direction','sales','finance'] as $section) {
            $this->getJson('/api/pilotage/'.$section)->assertForbidden();
        }
        $this->getJson('/api/pilotage/logistics')->assertOk();
        $this->getJson('/api/pilotage/alerts')->assertOk();

        $this->authenticate($support,$company);
        foreach (['direction','sales','finance','logistics','alerts'] as $section) {
            $this->getJson('/api/pilotage/'.$section)->assertForbidden();
        }
        $this->authenticate($admin,$company);
        $this->getJson('/api/pilotage/unknown')->assertNotFound();
    }

    public function test_alert_rules_require_admin_and_are_tenant_isolated(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M01_B','name'=>'M01 tenant B']);
        $adminA=$this->member($a,'admin','m01-a@example.test');
        $adminB=$this->member($b,'admin','m01-b@example.test');
        $stockA=$this->member($a,'stock','m01-stock@example.test');

        $this->authenticate($adminA,$a);
        $rule=$this->postJson('/api/pilotage/alerts/rules',[
            'name'=>'Ruptures à surveiller', 'metric'=>'low_stock',
            'threshold'=>5,'effective_from'=>now()->subDay()->toDateString(),
            'owner_id'=>$adminA->id,
        ])->assertCreated()->json('id');

        $product=Product::create([
            'reference'=>'M01-LOW','name'=>'Produit M01',
            'category'=>'Tests','price_cents'=>1000,'vat_bps'=>2000,
            'stock'=>2,'alert_threshold'=>5,
        ]);
        $this->getJson('/api/pilotage/alerts')
            ->assertOk()->assertJsonFragment(['entity_id'=>$product->id,'rule_id'=>$rule]);
        $ack=['rule_id'=>$rule,'entity_type'=>'product','entity_id'=>$product->id,'comment'=>'Pris en charge'];
        $this->postJson('/api/pilotage/alerts/acknowledge',$ack)
            ->assertOk()->assertJsonPath('already_acknowledged',false);
        $this->postJson('/api/pilotage/alerts/acknowledge',$ack)
            ->assertOk()->assertJsonPath('already_acknowledged',true);
        $this->assertDatabaseCount('pilotage_alert_acknowledgements',1);
        $this->assertDatabaseHas('audit_logs',['company_id'=>$a->id,'action'=>'pilotage.alert_acknowledged']);

        $this->authenticate($stockA,$a);
        $this->postJson('/api/pilotage/alerts/rules',[
            'name'=>'Injection interdite','metric'=>'low_stock','threshold'=>1,
            'effective_from'=>now()->toDateString(),'owner_id'=>$stockA->id,
        ])->assertForbidden();
        $this->postJson('/api/pilotage/alerts/acknowledge',$ack)->assertForbidden();

        $this->authenticate($adminB,$b);
        $this->getJson('/api/pilotage/alerts/rules')->assertOk()->assertExactJson([]);
        $this->postJson('/api/pilotage/alerts/acknowledge',$ack)->assertNotFound();
        $this->postJson('/api/pilotage/alerts/rules',[
            'name'=>'Propriétaire étranger','metric'=>'low_stock','threshold'=>1,
            'effective_from'=>now()->toDateString(),'owner_id'=>$adminA->id,
        ])->assertUnprocessable();
        $this->getJson('/api/pilotage/logistics')->assertJsonPath('cards.0.value',0);
    }

    public function test_metrics_do_not_conflate_orders_invoices_and_stocks(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->member($company,'admin','m01-metrics@example.test');
        $this->authenticate($admin,$company);

        $this->getJson('/api/pilotage/direction')
            ->assertJsonPath('cards.0.value',0)
            ->assertJsonPath('cards.1.value',0);
        $this->getJson('/api/pilotage/finance')
            ->assertJsonPath('cards.2.value',0);
        $this->getJson('/api/pilotage/sales')->assertJsonPath('cards.0.value',0);
        $this->getJson('/api/pilotage/logistics')->assertJsonPath('cards.0.value',0);
        $this->getJson('/api/pilotage/sales?from=2026-11-23&to=2026-11-22')
            ->assertUnprocessable();
        $this->getJson('/api/pilotage/sales?from=2024-01-01&to=2026-11-22')
            ->assertUnprocessable();
    }

    public function test_alert_input_validation_blocks_missing_owner_and_cross_metric_ack(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->member($company,'admin','m01-invalid@example.test');
        $this->authenticate($admin,$company);
        $this->postJson('/api/pilotage/alerts/rules',[
            'name'=>'Dates','metric'=>'low_stock','threshold'=>-1,
            'effective_from'=>'2026-11-22','effective_to'=>'2026-11-21',
            'owner_id'=>$admin->id,
        ])->assertUnprocessable();
        $rule=$this->postJson('/api/pilotage/alerts/rules',[
            'name'=>'Règle valide','metric'=>'low_stock','threshold'=>5,
            'effective_from'=>now()->toDateString(),'owner_id'=>$admin->id,
        ])->assertCreated()->json('id');
        $this->postJson('/api/pilotage/alerts/acknowledge',[
            'rule_id'=>$rule,'entity_type'=>'invoice','entity_id'=>1,
            'comment'=>'Cross metric',
        ])->assertStatus(422);
    }
}
