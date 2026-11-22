<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M07 V5.1 integration recipes. Requires the actual PostgreSQL migrations,
 * the restricted runtime role, and the parent V5 test fixtures.
 */
class MaintenanceOperationsV51Test extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $company, string $suffix): void
    {
        $role = Role::where('name','admin')->value('id');
        $user = User::create([
            'name'=>'M07 test '.$suffix,
            'email'=>'m07-'. $suffix .'@example.test',
            'password'=>'SafeTestPassword2026!',
            'role_id'=>$role,
        ]);
        $user->companies()->attach($company->id, ['role_id'=>$role]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }

    private function setupOrder(string $suffix): array
    {
        $site=$this->postJson('/api/maintenance/sites',[
            'code'=>'M07-S-'.$suffix,'name'=>'Site fictif '.$suffix,
        ])->assertCreated()->json('id');
        $asset=$this->postJson('/api/maintenance/assets',[
            'site_id'=>$site,'asset_code'=>'M07-A-'.$suffix,'name'=>'Pompe '.$suffix,
        ])->assertCreated()->json('id');
        $di=$this->postJson('/api/maintenance/requests',[
            'asset_id'=>$asset,'reference'=>'M07-DI-'.$suffix,
            'title'=>'Incident fictif','priority'=>'high',
        ])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/requests/'.$di.'/qualify')->assertOk();
        $wo=$this->postJson('/api/maintenance/requests/'.$di.'/work-orders')
            ->assertCreated()->json('id');
        return compact('site','asset','di','wo');
    }

    public function test_dashboard_and_warehouse_reservations_do_not_debit_until_consumption(): void
    {
        $c=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->admin($c,'stock');
        $o=$this->setupOrder('STOCK');
        $product=$this->postJson('/api/products',[
            'reference'=>'M07-P','name'=>'Joint fictif','category'=>'Pieces',
            'price_cents'=>1000,'vat_bps'=>2000,'alert_threshold'=>2,
        ])->assertCreated()->json('id');
        $this->postJson('/api/products/'.$product.'/stock',[
            'quantity'=>5,'reason'=>'Stock fictif M07',
        ])->assertOk();
        $reservation=$this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/spares',[
            'product_id'=>$product,'quantity'=>3,'operation_key'=>'reserve-once-0001',
        ])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/spares',[
            'product_id'=>$product,'quantity'=>3,'operation_key'=>'reserve-once-0001',
        ])->assertOk()->assertJsonPath('id',$reservation);
        $this->getJson('/api/products')->assertOk();
        $this->assertDatabaseHas('products',['id'=>$product,'stock'=>5]);
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/start')->assertOk();
        $this->postJson('/api/maintenance/spares/'.$reservation.'/consume',[
            'quantity'=>2,'operation_key'=>'consume-once-0001',
        ])->assertCreated()->assertJsonPath('total_cents',2000);
        $this->postJson('/api/maintenance/spares/'.$reservation.'/consume',[
            'quantity'=>2,'operation_key'=>'consume-once-0001',
        ])->assertOk();
        $this->assertDatabaseHas('products',['id'=>$product,'stock'=>3]);
        $this->assertDatabaseCount('maintenance_spare_consumptions',1);
        $this->postJson('/api/maintenance/spares/'.$reservation.'/consume',[
            'quantity'=>2,'operation_key'=>'consume-twice-0001',
        ])->assertStatus(409);
        $this->postJson('/api/maintenance/spares/'.$reservation.'/release',[
            'quantity'=>1,'reason'=>'Pièce réservée non utilisée',
        ])->assertOk()->assertJsonPath('released_quantity',1);
        $this->getJson('/api/maintenance/work-orders/'.$o['wo'].'/costs')
            ->assertOk()->assertJsonPath('spares_cost_cents',2000);
        $this->getJson('/api/maintenance/dashboard')->assertOk()->assertJsonPath('spare_cost_cents',2000);
    }

    public function test_location_movement_field_version_and_labor_are_tenant_scoped(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M07_TENANT_B','name'=>'Entreprise B fictive']);
        $this->admin($a,'a');
        $o=$this->setupOrder('TENANT');
        $location=$this->postJson('/api/maintenance/locations',[
            'site_id'=>$o['site'],'code'=>'A01','name'=>'Salle technique',
        ])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/assets/'.$o['asset'].'/move',[
            'location_id'=>$location,'reason'=>'Déplacement documenté pour maintenance',
        ])->assertOk();
        $this->assertDatabaseHas('maintenance_asset_location_events',[
            'company_id'=>$a->id,'asset_id'=>$o['asset'],'to_location_id'=>$location,
        ]);
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/field-events',[
            'operation_key'=>'mobile-event-once-0001','kind'=>'note',
            'base_version'=>1,'note'=>'Arrivée sur équipement',
        ])->assertCreated()->assertJsonPath('result_version',2);
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/field-events',[
            'operation_key'=>'mobile-event-once-0001','kind'=>'note',
            'base_version'=>1,'note'=>'Arrivée sur équipement',
        ])->assertOk()->assertJsonPath('result_version',2);
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/field-events',[
            'operation_key'=>'mobile-event-two-0002','kind'=>'note',
            'base_version'=>1,'note'=>'Donnée obsolète',
        ])->assertStatus(409);
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/start')->assertOk();
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/labor',[
            'operation_key'=>'labor-once-0001','minutes'=>90,'hourly_rate_cents'=>6000,
        ])->assertCreated()->assertJsonPath('cost_cents',9000);
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/labor',[
            'operation_key'=>'labor-once-0001','minutes'=>90,'hourly_rate_cents'=>6000,
        ])->assertOk();
        $this->assertDatabaseCount('maintenance_work_order_time_entries',1);
        $this->admin($b,'b');
        $this->getJson('/api/maintenance/locations')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/maintenance/contracts')->assertOk()->assertJsonPath('total',0);
        $this->postJson('/api/maintenance/assets/'.$o['asset'].'/move',[
            'location_id'=>$location,'reason'=>'Interdit pour autre entreprise',
        ])->assertNotFound();
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/labor',[
            'operation_key'=>'labor-other-0001','minutes'=>60,'hourly_rate_cents'=>6000,
        ])->assertNotFound();
    }

    public function test_contract_sla_requires_local_site_and_asset_restoration_requires_inspection(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->admin($a,'contract');
        $o=$this->setupOrder('CONTRACT');
        $contract=$this->postJson('/api/maintenance/contracts',[
            'code'=>'M07-C-01','name'=>'Contrat maintenance fictif',
            'site_id'=>$o['site'],'response_hours'=>8,
            'starts_on'=>'2026-01-01','ends_on'=>'2027-12-31',
            'calendar_timezone'=>'Europe/Paris',
        ])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/contracts/'.$contract.'/sla-events',[
            'work_order_id'=>$o['wo'],'event'=>'started',
            'reason'=>'Début de prise en charge technique',
        ])->assertCreated();
        $this->postJson('/api/maintenance/assets/'.$o['asset'].'/stop',[
            'reason'=>'Arrêt fictif de sécurité validé',
        ])->assertCreated();
        $this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/start')->assertOk();
        $inspection=$this->postJson('/api/maintenance/work-orders/'.$o['wo'].'/inspect',[
            'passed'=>true,'note'=>'Contrôle de conformité technique réalisé',
        ])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/assets/'.$o['asset'].'/restore',[
            'inspection_id'=>$inspection,'reason'=>'Retour en service après contrôle',
        ])->assertForbidden(); // Four-eyes rule
        $this->getJson('/api/maintenance/dashboard')->assertOk()
            ->assertJsonPath('critical_assets_out_of_service',0);
    }
}
