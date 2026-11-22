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

    public function test_reconciliation_flags_amount_deltas_duplicates_and_preserves_original_entries(): void
    {
        $this->consume(['quantity'=>4])->assertCreated()->assertJsonPath('total_cents', 1000);
        $single = $this->importCsv("jour;chambre;total\n2026-03-03;101;6,00\n")
            ->assertCreated()->json('id');
        $report = "$this->base/reconciliation?business_date=2026-03-03&import_id=$single";
        $this->getJson($report)->assertOk()
            ->assertJsonPath('atlas_total_cents', 1000)
            ->assertJsonPath('pms_total_cents', 600)
            ->assertJsonPath('delta_cents', 400)
            ->assertJsonPath('counts.amount_mismatch', 1)
            ->assertJsonPath('lines.0.status', 'amount_mismatch')
            ->assertJsonPath('lines.0.possible_breakfast_difference', false)
            ->assertJsonPath('manual_review_required', true);

        $duplicate = $this->importCsv("jour;chambre;total\n2026-03-03;101;3,00\n2026-03-03;101;3,00\n")
            ->assertCreated()->assertJsonPath('row_count', 2)->json('id');
        $this->getJson("$this->base/reconciliation?business_date=2026-03-03&import_id=$duplicate")
            ->assertOk()->assertJsonPath('counts.duplicate_pms', 1)
            ->assertJsonPath('lines.0.pms_line_count', 2)
            ->assertJsonPath('lines.0.delta_cents', 400);
        $this->getJson("$this->base/reconciliation?business_date=2026-03-03")
            ->assertJsonPath('import_id', $duplicate)
            ->assertJsonPath('selected_latest', true);
        $this->getJson("$this->base/reconciliation?business_date=2026-03-03&import_id=999999")
            ->assertNotFound();

        $csv = $this->get("$this->base/reconciliation/export?business_date=2026-03-03&import_id=$single")
            ->assertOk()->getContent();
        $this->assertStringContainsString('2026-03-03;'.$this->hotel.';'.$single.';room;101;', $csv);
        $this->assertStringContainsString('1000;600;400;1;amount_mismatch;no', $csv);
        $this->assertSame(1, DB::table('minibar_consumptions')->count());
        $this->assertSame(2, DB::table('minibar_pms_imports')->count());

        $foreign = Company::create(['code'=>'MB_REC_B', 'name'=>'Société fictive B'])->id;
        $this->loginFor($foreign, 'reconciliation-b');
        $this->getJson($report)->assertNotFound();
        $this->get("$this->base/reconciliation/export?business_date=2026-03-03&import_id=$single")
            ->assertNotFound();
    }

    public function test_room_product_mode_matches_and_absent_pms_lines_are_not_silently_corrected(): void
    {
        $this->consume()->assertCreated()->assertJsonPath('total_cents', 500);
        $matched = $this->importCsv("jour;chambre;article;total\n2026-03-03;101;EAU;5,00\n", [
            'mode'=>'room_product', 'sku_header'=>'article',
        ])->assertCreated()->json('id');
        $this->getJson("$this->base/reconciliation?business_date=2026-03-03&import_id=$matched")
            ->assertOk()->assertJsonPath('import_mode', 'room_product')
            ->assertJsonPath('counts.matched', 1)
            ->assertJsonPath('manual_review_required', false)
            ->assertJsonPath('lines.0.sku', 'EAU');
        $missing = $this->importCsv("jour;chambre;article;total\n2026-03-03;999;EAU;5,00\n", [
            'mode'=>'room_product', 'sku_header'=>'article',
        ])->assertCreated()->json('id');
        $this->getJson("$this->base/reconciliation?business_date=2026-03-03&import_id=$missing")
            ->assertOk()->assertJsonPath('counts.missing_in_pms', 1)
            ->assertJsonPath('counts.missing_in_atlas', 1)
            ->assertJsonPath('manual_review_required', true);
    }

    public function test_monthly_dashboard_distinguishes_unimported_days_from_zero_variance(): void
    {
        $this->consume(['quantity'=>4])->assertCreated();
        $this->importCsv("jour;chambre;total\n2026-03-03;101;6,00\n")->assertCreated();
        $this->getJson("$this->base/monthly?month=2026-03")->assertOk()
            ->assertJsonPath('month','2026-03')
            ->assertJsonPath('atlas_total_cents',1000)
            ->assertJsonPath('total_quantity',4)
            ->assertJsonPath('by_article.0.sku','EAU')
            ->assertJsonPath('by_article.0.quantity',4)
            ->assertJsonPath('by_article.0.total_cents',1000)
            ->assertJsonPath('days.2.quantity',4)
            ->assertJsonPath('compared_atlas_cents',1000)
            ->assertJsonPath('compared_pms_cents',600)
            ->assertJsonPath('compared_delta_cents',400)
            ->assertJsonPath('compared_days',1)
            ->assertJsonPath('uncompared_days',30)
            ->assertJsonPath('anomalies_count',1)
            ->assertJsonPath('days.2.business_date','2026-03-03')
            ->assertJsonPath('days.2.delta_cents',400)
            ->assertJsonPath('days.0.pms_total_cents',null);
        $this->getJson("$this->base/monthly?month=2026-99")->assertUnprocessable();
        $foreign = Company::create(['code'=>'MB_MONTH_B','name'=>'Société B fictive'])->id;
        $this->loginFor($foreign,'monthly-b');
        $this->getJson("$this->base/monthly?month=2026-03")->assertNotFound();
    }

    public function test_runtime_rls_isolates_minibar_consumptions_and_pms_imports(): void
    {
        $this->consume()->assertCreated()->assertJsonPath('total_cents', 500);
        $this->importCsv("jour;chambre;total\n2026-03-03;101;5,00\n")->assertCreated();
        $foreign = Company::create(['code'=>'MB_RUNTIME_B','name'=>'Entreprise B fictive'])->id;

        DB::transaction(function () use ($foreign): void {
            DB::statement('SET LOCAL ROLE atlas_app_runtime');
            DB::selectOne("SELECT set_config('atlas.tenant_id', ?, true)", [(string) $this->company]);
            $this->assertSame(1, DB::table('minibar_consumptions')->count());
            $this->assertSame(1, DB::table('minibar_pms_imports')->count());

            DB::selectOne("SELECT set_config('atlas.tenant_id', ?, true)", [(string) $foreign]);
            $this->assertSame(0, DB::table('minibar_consumptions')->count());
            $this->assertSame(0, DB::table('minibar_pms_imports')->count());

            DB::selectOne("SELECT set_config('atlas.tenant_id', '', true)");
            $this->assertSame(0, DB::table('minibar_consumptions')->count());
            $this->assertSame(0, DB::table('minibar_pms_imports')->count());
            DB::statement('RESET ROLE');
        });
    }

    public function test_reconciliation_breakfast_hypothesis_requires_exact_nonzero_five_euro_multiple(): void
    {
        $this->consume(['quantity'=>4])->assertCreated()->assertJsonPath('total_cents', 1000);
        $id = $this->importCsv("jour;chambre;total\n2026-03-03;101;5,00\n")
            ->assertCreated()->json('id');
        $this->getJson("$this->base/reconciliation?business_date=2026-03-03&import_id=$id")
            ->assertOk()->assertJsonPath('delta_cents', 500)
            ->assertJsonPath('lines.0.possible_breakfast_difference', true)
            ->assertJsonPath('lines.0.status', 'amount_mismatch')
            ->assertJsonPath('manual_review_required', true);
        $this->assertSame(1, DB::table('minibar_consumptions')->count());
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
