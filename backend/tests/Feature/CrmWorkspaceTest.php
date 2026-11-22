<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CrmWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private int $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::where('code','ATLAS_DEMO')->firstOrFail()->id;
        $this->login('admin','a',$this->company);
    }

    private function login(string $role,string $suffix,int $company): void
    {
        $roleId = Role::where('name',$role)->value('id');
        $user=User::create(['name'=>'CRM '.$role,'email'=>'crm-'.$suffix.'@example.test',
            'role_id'=>$roleId,'password'=>'TestPassword2026!']);
        $user->companies()->attach($company,['role_id'=>$roleId]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company]);
    }

    public function test_prospect_qualify_convert_replay_and_customer_snapshot(): void
    {
        $id=$this->postJson('/api/crm/prospects',[
            'name'=>'Prospect fictif','email'=>'unique-crm@test.example','source'=>'salon',
        ])->assertCreated()->assertJsonPath('status','new')->json('id');
        $this->postJson('/api/crm/prospects/'.$id.'/convert',['expected_revision'=>1])->assertConflict();
        $this->postJson('/api/crm/prospects/'.$id.'/transition',[
            'target'=>'qualified','expected_revision'=>1,
        ])->assertOk()->assertJsonPath('revision',2);
        $result=$this->postJson('/api/crm/prospects/'.$id.'/convert',[
            'expected_revision'=>2,
        ])->assertOk()->assertJsonPath('prospect.status','converted');
        $customerId=$result->json('customer_id');
        $this->postJson('/api/crm/prospects/'.$id.'/convert',[
            'expected_revision'=>2,
        ])->assertOk()->assertJsonPath('customer_id',$customerId);
        $this->assertDatabaseCount('crm_prospects',1);
        $this->assertDatabaseHas('customers',['id'=>$customerId,'email'=>'unique-crm@test.example']);
    }

    public function test_all_six_menu_resources_and_version_conflict(): void
    {
        $customer=Customer::create(['name'=>'Entreprise fictive','email'=>'fictive-crm@test.example']);
        $contact=$this->postJson('/api/crm/contacts',[
            'customer_id'=>$customer->id,'first_name'=>'Camille','last_name'=>'Roux','consent'=>'refused',
        ])->assertCreated()->json('id');
        $this->putJson('/api/crm/contacts/'.$contact,['expected_revision'=>0,'job_title'=>'Directrice'])->assertConflict();
        $this->putJson('/api/crm/contacts/'.$contact,['expected_revision'=>1,'job_title'=>'Directrice'])
            ->assertOk()->assertJsonPath('revision',2);
        $opportunity=$this->postJson('/api/crm/opportunities',[
            'customer_id'=>$customer->id,'title'=>'Projet pilote','amount_cents'=>200000,
        ])->assertCreated()->json('id');
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/transition',[
            'target'=>'qualified','expected_revision'=>1,
        ])->assertOk()->assertJsonPath('stage','qualified');
        $activity=$this->postJson('/api/crm/activities',[
            'customer_id'=>$customer->id,'title'=>'Appeler client','due_at'=>'2026-12-05T10:00:00+01:00'
        ])->assertCreated()->json('id');
        $this->postJson('/api/crm/activities/'.$activity.'/transition',[
            'target'=>'completed','reason'=>'Appel effectué','expected_revision'=>1,
        ])->assertOk()->assertJsonPath('status','completed');
        $segment=$this->postJson('/api/crm/segments',[
            'name'=>'Clients pilotes','code'=>'PILOTE','mode'=>'static',
        ])->assertCreated()->json('id');
        $this->putJson('/api/crm/segments/'.$segment.'/members',[
            'customer_id'=>$customer->id,'active'=>true,
        ])->assertOk();
        $this->getJson('/api/crm/segments/'.$segment.'/members')->assertOk()
            ->assertJsonPath('data.0.id',$customer->id);
        foreach (['prospects','contacts','opportunities','activities','segments'] as $kind) {
            $this->getJson('/api/crm/'.$kind)->assertOk();
        }
    }

    public function test_dynamic_segment_and_cross_tenant_access(): void
    {
        Customer::create(['name'=>'Alpha fictive','email'=>'alpha@example.test']);
        $segment=$this->postJson('/api/crm/segments',[
            'code'=>'EXAMPLE','name'=>'Email example','mode'=>'dynamic',
            'criteria'=>['field'=>'email_domain','value'=>'example.test'],
        ])->assertCreated()->json('id');
        $this->getJson('/api/crm/segments/'.$segment.'/members')->assertOk()->assertJsonPath('total',1);
        $foreign=Company::create(['code'=>'CRM_B','name'=>'Entreprise CRM B'])->id;
        $this->login('admin','b',$foreign);
        $this->getJson('/api/crm/segments/'.$segment)->assertNotFound();
        $this->getJson('/api/crm/segments/'.$segment.'/members')->assertNotFound();
        $this->getJson('/api/crm/prospects')->assertJsonPath('total',0);
        $this->postJson('/api/crm/contacts',[
            'customer_id'=>1,'first_name'=>'Interdit','last_name'=>'Test',
        ])->assertUnprocessable();
    }

    public function test_permissions_validate_payload_and_reject_unsafe_transitions(): void
    {
        $id=$this->postJson('/api/crm/prospects',['name'=>'Refus','email'=>'refus@example.test'])
            ->assertCreated()->json('id');
        $this->postJson('/api/crm/prospects/'.$id.'/transition',[
            'target'=>'converted','expected_revision'=>1,
        ])->assertConflict();
        $this->postJson('/api/crm/opportunities',['title'=>'Injection','company_id'=>999])
            ->assertUnprocessable();
        $this->login('reader','reader',$this->company);
        $this->getJson('/api/crm/prospects')->assertOk();
        $this->postJson('/api/crm/prospects',['name'=>'Interdit'])->assertForbidden();
        $this->login('stock','stock',$this->company);
        $this->getJson('/api/crm/prospects')->assertForbidden();
    }

    public function test_archiving_customer_preserves_history_without_deletion(): void
    {
        $customer=Customer::create(['name'=>'Archive','email'=>'archive@example.test']);
        $this->postJson('/api/crm/customers/'.$customer->id.'/archive',[
            'reason'=>'Fin de relation commerciale',
        ])->assertOk();
        $this->assertNotNull($customer->fresh()->archived_at);
        $this->getJson('/api/customers')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/customers/'.$customer->id)->assertOk();
    }

    public function test_csv_customer_import_rejects_invalid_row_and_replays_without_duplicates(): void
    {
        $csv="name,email,phone\n";
        for($i=1;$i<=9;$i++)$csv.="Client fictif $i,client-csv-$i@example.test,0102030405\n";
        $csv.="Ligne invalide,pas-un-email,01\n";
        $upload=fn()=>\Illuminate\Http\UploadedFile::fake()->createWithContent('crm.csv',$csv);
        $this->post('/api/crm/customers/import',['file'=>$upload()],['Accept'=>'application/json'])
            ->assertCreated()->assertJsonPath('accepted_rows',9)->assertJsonPath('rejected_rows',1);
        $this->post('/api/crm/customers/import',['file'=>$upload()],['Accept'=>'application/json'])
            ->assertOk()->assertJsonPath('replayed',true);
        $this->assertSame(9,DB::table('customers')->where('email','like','client-csv-%')->count());
        $this->assertSame(1,DB::table('crm_import_batches')->count());
    }

    public function test_pg_runtime_rls_isolates_all_new_crm_tables(): void
    {
        if (DB::getDriverName()!=='pgsql') $this->markTestSkipped('RLS PostgreSQL uniquement.');
        $this->postJson('/api/crm/prospects',['name'=>'Isolé'])->assertCreated();
        $foreign=Company::create(['code'=>'CRM_SQL_B','name'=>'SQL autre entreprise'])->id;
        DB::transaction(function() use ($foreign){
            DB::statement('SET LOCAL ROLE atlas_app_runtime');
            DB::selectOne("SELECT set_config('atlas.tenant_id', ?, true)",[(string)$this->company]);
            $this->assertSame(1,DB::table('crm_prospects')->count());
            DB::selectOne("SELECT set_config('atlas.tenant_id', ?, true)",[(string)$foreign]);
            $this->assertSame(0,DB::table('crm_prospects')->count());
            DB::selectOne("SELECT set_config('atlas.tenant_id', '', true)");
            $this->assertSame(0,DB::table('crm_prospects')->count());
            DB::statement('RESET ROLE');
        });
    }
}
