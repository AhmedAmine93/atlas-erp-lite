<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HotelMinibarTest extends TestCase
{
    use RefreshDatabase;
    private int $company;
    private int $hotel;
    private int $room;
    private int $product;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::where('code','ATLAS_DEMO')->firstOrFail()->id;
        $this->loginFor($this->company,'a');
        $this->hotel = $this->postJson('/api/hotels',['code'=>'MB','name'=>'Hôtel fictif','timezone'=>'UTC'])->assertCreated()->json('id');
        $this->room = $this->postJson('/api/hotel-rooms',['hotel_id'=>$this->hotel,'room_number'=>'101','category'=>'Double'])->assertCreated()->json('id');
        $this->base = "/api/hotels/{$this->hotel}/minibar";
        $this->product = $this->postJson($this->base.'/products',['sku'=>'EAU','name'=>'Eau','effective_on'=>'2026-01-01','unit_cents'=>250])->assertCreated()->json('id');
    }

    private function loginFor(int $company,string $suffix,string $role='admin'): void
    {
        $roleId = Role::where('name',$role)->value('id');
        $user = User::create(['name'=>'Test minibar','email'=>"minibar-$suffix@example.test",
            'role_id'=>$roleId,'password'=>'TestPassword2026!']);
        $user->companies()->attach($company,['role_id'=>$roleId]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company]);
    }

    public function test_catalog_prices_and_room_day_are_scoped(): void
    {
        $prices = "$this->base/products/$this->product/prices";
        $this->postJson($prices,['effective_on'=>'2026-02-01','unit_cents'=>350])->assertCreated();
        $this->postJson($prices,['effective_on'=>'2026-02-01','unit_cents'=>450])->assertConflict();
        $this->getJson("$this->base/products?business_date=2026-01-31")->assertOk()->assertJsonPath('0.unit_cents',250);
        $this->getJson("$this->base/products?business_date=2026-02-01")->assertOk()->assertJsonPath('0.unit_cents',350);
        $day = "/api/hotels/room-day?hotel_id=$this->hotel&business_date=2026-02-01";
        $this->getJson($day)->assertOk()->assertJsonPath('rooms.0.id',$this->room)->assertJsonPath('rooms.0.occupied',0);
        $this->loginFor($this->company,'reader','reader');
        $this->getJson($day)->assertForbidden();
        $foreign = Company::create(['code'=>'MB_B','name'=>'Autre entreprise fictive'])->id;
        $this->loginFor($foreign,'b');
        $this->getJson($day)->assertNotFound();
        $this->getJson("$this->base/products?business_date=2026-02-01")->assertNotFound();
        $this->postJson($prices,['effective_on'=>'2026-03-01','unit_cents'=>400])->assertNotFound();
    }

    private function consume(array $overrides=[])
    {
        return $this->postJson($this->base.'/consumptions',$overrides+[
            'room_id'=>$this->room,'product_id'=>$this->product,'quantity'=>2,
            'business_date'=>'2026-03-03','request_id'=>'00000000-0000-4000-8000-000000000001',
        ]);
    }

    private function importCsv(string $content,array $overrides=[])
    {
        return $this->withHeader('Accept','application/json')->post($this->base.'/pms/imports',$overrides+[
            'file'=>\Illuminate\Http\UploadedFile::fake()->createWithContent('pms.csv',$content),
            'synthetic'=>'1','delimiter'=>';','amount_unit'=>'euros','mode'=>'room',
            'date_header'=>'jour','room_header'=>'chambre','amount_header'=>'total','business_date'=>'2026-03-03',
        ]);
    }

    public function test_ledger_retries_historical_price_and_filtered_exports(): void
    {
        $entry=$this->consume()->assertCreated()->assertJsonPath('total_cents',500)->json('id');
        $this->consume()->assertOk()->assertJsonPath('id',$entry);
        $this->consume(['quantity'=>3])->assertConflict();
        $this->consume(['unit_cents'=>1])->assertUnprocessable();
        $this->postJson("$this->base/products/$this->product/prices",['effective_on'=>'2026-03-01','unit_cents'=>350])->assertCreated();
        $this->consume(['request_id'=>'00000000-0000-4000-8000-000000000002'])->assertCreated()->assertJsonPath('total_cents',700);
        $this->getJson("$this->base/report?business_date=2026-03-03")->assertOk()
            ->assertJsonPath('total_cents',1200)->assertJsonCount(2,'lines');
        $this->getJson("$this->base/report?business_date=2026-03-04")->assertJsonPath('total_cents',0);
        $csv=$this->get("$this->base/export?business_date=2026-03-03")->assertOk()->getContent();
        $this->assertStringContainsString('2026-03-03;101;EAU;Eau;250;2;500',$csv);
        $this->assertStringContainsString('2026-03-03;101;EAU;Eau;350;2;700',$csv);
        $this->assertSame(2,DB::table('audit_logs')->where('action','hotel.minibar.consumption.created')->count());
    }

    public function test_pms_import_validation_mapping_and_replay(): void
    {
        $csv="jour;chambre;total\n2026-03-03;101;5,00\n";
        $id=$this->importCsv($csv)->assertCreated()->assertJsonPath('row_count',1)->json('id');
        $this->importCsv($csv)->assertOk()->assertJsonPath('id',$id);
        $this->importCsv($csv,['synthetic'=>'0'])->assertUnprocessable();
        $this->importCsv($csv,['business_date'=>'2026-03-04'])->assertUnprocessable();
        $this->importCsv($csv,['date_header'=>'chambre'])->assertUnprocessable();
        $this->importCsv(str_replace('5,00','9.999',$csv))->assertUnprocessable();
        $this->importCsv(str_replace('5,00','"5,00',$csv))->assertUnprocessable();
        $this->assertSame(1,DB::table('minibar_pms_imports')->count());
        $this->getJson("$this->base/pms/mapping")->assertJsonPath('mapping.amount_header','total');
    }

    public function test_runtime_isolates_catalog_and_cannot_rewrite_historical_prices(): void
    {
        DB::transaction(function () {
            DB::statement('SET LOCAL ROLE atlas_app_runtime');
            DB::selectOne("SELECT set_config('atlas.tenant_id', ?, true)",[(string)$this->company]);
            $this->assertSame(1,DB::table('minibar_prices')->where('product_id',$this->product)->count());
            try {
                DB::transaction(fn()=>DB::table('minibar_prices')->where('product_id',$this->product)->update(['unit_cents'=>1]));
                $this->fail('Tarif historique modifié');
            } catch (QueryException $e) {
                $this->assertSame('42501',$e->errorInfo[0]);
            }
            DB::selectOne("SELECT set_config('atlas.tenant_id', '', true)");
            $this->assertSame(0,DB::table('minibar_products')->count());
            $this->assertSame(0,DB::table('minibar_prices')->count());
            DB::statement('RESET ROLE');
        });
    }
}
