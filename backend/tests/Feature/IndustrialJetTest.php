<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PostgreSQL real tests of schema-qualified industrial rules (not Oracle PKG). */
final class IndustrialJetTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(Company $company, string $role, string $suffix): User
    {
        $user=User::create([
            'name'=>'Industrial '.$suffix,
            'email'=>'industrial-'.$suffix.'@example.test',
            'password'=>'IndustrialSynthetic2026!',
            'role_id'=>Role::where('name','admin')->value('id'),
        ]);
        $user->companies()->attach($company->id,[
            'role_id'=>Role::where('name',$role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
        return $user;
    }

    private function asset(string $code): int
    {
        $site=$this->postJson('/api/maintenance/sites',[
            'code'=>'SITE-'.$code,'name'=>'Usine fictive '.$code,
        ])->assertCreated()->json('id');
        return $this->postJson('/api/maintenance/assets',[
            'site_id'=>$site,'asset_code'=>'PUMP-'.$code,'name'=>'Pompe de test '.$code,
        ])->assertCreated()->json('id');
    }

    public function test_meter_monotonicity_idempotence_tenant_and_runtime_rls(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'IND_B','name'=>'Industrial company B']);
        $this->getJson('/api/industrial/overview')->assertUnauthorized();
        $this->signIn($a,'reader','meter-reader');
        $this->getJson('/api/industrial/overview')->assertForbidden();

        $this->signIn($a,'admin','meter-admin');
        $asset=$this->asset('A');
        $url='/api/industrial/meters';
        $first=[
            'asset_id'=>$asset,'meter_code'=>'HOURS','reading_value'=>100.5,
            'idempotency_key'=>'meter-synthetic-01',
        ];
        $id=$this->postJson($url,$first)->assertCreated()->assertJsonPath('replayed',false)->json('id');
        $this->postJson($url,$first)->assertOk()->assertJsonPath('id',$id)
            ->assertJsonPath('replayed',true);
        $this->postJson($url,[...$first,'reading_value'=>120])->assertStatus(409);
        $this->postJson($url,[...$first,'reading_value'=>90,'idempotency_key'=>'meter-synthetic-02'])
            ->assertStatus(409);
        $this->postJson($url,[...$first,'meter_code'=>'INVALID','idempotency_key'=>'meter-synthetic-03'])
            ->assertUnprocessable();
        $this->postJson($url,[...$first,'company_id'=>$b->id])->assertUnprocessable();
        $this->postJson($url,[...$first,'reading_value'=>110,'idempotency_key'=>'meter-synthetic-04'])
            ->assertCreated();
        $this->getJson($url)->assertOk()->assertJsonPath('total',2);
        $this->assertDatabaseHas('industrial_meter_readings',[
            'id'=>$id,'company_id'=>$a->id,'asset_id'=>$asset,'meter_code'=>'HOURS',
        ]);
        $this->assertDatabaseHas('audit_logs',[
            'company_id'=>$a->id,'action'=>'industrial.meter.recorded',
        ]);

        if (DB::getDriverName()==='pgsql') {
            foreach (['industrial_meter_readings','industrial_inspection_templates','industrial_inspection_runs'] as $table) {
                $acl=DB::selectOne(
                    "SELECT has_table_privilege('atlas_app_runtime', ?, 'UPDATE')::int AS u,
                            has_table_privilege('atlas_app_runtime', ?, 'DELETE')::int AS d",
                    ['public.'.$table,'public.'.$table]
                );
                $this->assertSame(0,(int)$acl->u);
                $this->assertSame(0,(int)$acl->d);
                $this->assertSame(1,(int) DB::selectOne(
                    'SELECT relrowsecurity::int AS enabled FROM pg_class WHERE relname = ?',[$table]
                )->enabled);
            }
        }

        $this->signIn($b,'admin','meter-admin-b');
        $this->getJson($url)->assertOk()->assertJsonPath('total',0);
        $this->postJson($url,[...$first,'idempotency_key'=>'meter-synthetic-05'])
            ->assertNotFound();
        $this->getJson('/api/industrial/overview')->assertJsonPath('meter_readings_30d',0);
    }

    public function test_failed_inspection_creates_exactly_one_qualifed_di_and_work_order(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->signIn($a,'admin','inspection-admin');
        $asset=$this->asset('INS');
        $template=$this->postJson('/api/industrial/templates',[
            'code'=>'CTRL-01','title'=>'Contrôle technique obligatoire',
            'description'=>'Inspection quotidienne fictive',
        ])->assertCreated()->json('id');
        $this->postJson('/api/industrial/templates',[
            'code'=>'CTRL-01','title'=>'Deuxième modèle identique',
        ])->assertUnprocessable();

        $input=[
            'asset_id'=>$asset,'template_id'=>$template,
            'result'=>'fail','observations'=>'Fuite hydraulique détectée pendant le contrôle.',
            'idempotency_key'=>'inspection-unique-001',
        ];
        $first=$this->postJson('/api/industrial/inspections',$input)->assertCreated()
            ->assertJsonPath('replayed',false)->json();
        $this->assertNotNull($first['corrective_request_id']);
        $this->assertNotNull($first['corrective_work_order_id']);
        $this->assertDatabaseHas('maintenance_requests',[
            'id'=>$first['corrective_request_id'],'company_id'=>$a->id,
            'status'=>'qualified','priority'=>'high',
            'reference'=>'INS-'.$first['id'],
        ]);
        $this->assertDatabaseHas('maintenance_work_orders',[
            'id'=>$first['corrective_work_order_id'],'company_id'=>$a->id,
            'status'=>'open','maintenance_request_id'=>$first['corrective_request_id'],
        ]);
        $this->postJson('/api/industrial/inspections',$input)->assertOk()
            ->assertJsonPath('replayed',true)
            ->assertJsonPath('corrective_work_order_id',$first['corrective_work_order_id']);
        $this->postJson('/api/industrial/inspections',[
            ...$input,'result'=>'pass',
        ])->assertStatus(409);
        $this->assertDatabaseCount('industrial_inspection_runs',1);
        $this->assertDatabaseCount('maintenance_requests',1);
        $this->assertDatabaseCount('maintenance_work_orders',1);
        $this->getJson('/api/industrial/overview')->assertJsonPath('failed_inspections_30d',1);
        $this->getJson('/api/industrial/inspections')->assertOk()->assertJsonPath('total',1);
    }

    public function test_cross_tenant_template_foreign_key_and_immutable_journals(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'IND_TEST_B','name'=>'Industrial B']);
        $this->signIn($a,'admin','template-a');
        $assetA=$this->asset('A2');
        $template=$this->postJson('/api/industrial/templates',[
            'code'=>'QA-01','title'=>'Contrôle qualité fictif',
        ])->assertCreated()->json('id');

        $this->signIn($b,'admin','template-b');
        $assetB=$this->asset('B2');
        $this->postJson('/api/industrial/inspections',[
            'asset_id'=>$assetB,'template_id'=>$template,
            'result'=>'pass','observations'=>'Contrôle conforme mais modèle étranger',
            'idempotency_key'=>'inspection-other-tenant',
        ])->assertNotFound();
        $this->getJson('/api/industrial/templates')->assertJsonPath('total',0);
        $this->postJson('/api/industrial/meters',[
            'asset_id'=>$assetA,'meter_code'=>'HOURS','reading_value'=>8,
            'idempotency_key'=>'meter-other-company',
        ])->assertNotFound();
        $this->assertDatabaseCount('industrial_inspection_runs',0);
    }
}
