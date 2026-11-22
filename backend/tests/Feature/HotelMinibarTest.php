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
