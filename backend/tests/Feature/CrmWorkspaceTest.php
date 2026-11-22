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

    public function test_lost_prospect_reopen_and_activity_reschedule_are_audited(): void
    {
        $prospect=$this->postJson('/api/crm/prospects',['name'=>'Prospect retrouvé','email'=>'reopen@example.test'])
            ->assertCreated()->json('id');
        $this->postJson('/api/crm/prospects/'.$prospect.'/transition',[
            'target'=>'lost','reason'=>'Pas de budget','expected_revision'=>1
        ])->assertOk()->assertJsonPath('status','lost');
        $this->postJson('/api/crm/prospects/'.$prospect.'/transition',[
            'target'=>'qualified','reason'=>'Budget validé','expected_revision'=>2
        ])->assertOk()->assertJsonPath('status','qualified');
        $activity=$this->postJson('/api/crm/activities',[
            'title'=>'Relancer prospect','prospect_id'=>$prospect,'due_at'=>'2026-12-01T10:00:00+01:00',
        ])->assertCreated()->json('id');
        $this->postJson('/api/crm/activities/'.$activity.'/reschedule',[
            'due_at'=>'2027-01-02T11:00:00+01:00','reason'=>'Rendez-vous déplacé','expected_revision'=>1,
        ])->assertOk()->assertJsonPath('reschedule_count',1);
        $this->postJson('/api/crm/activities/'.$activity.'/reschedule',[
            'due_at'=>'2027-01-03T11:00:00+01:00','reason'=>'Rejeu périmé','expected_revision'=>1,
        ])->assertConflict();
        $this->assertDatabaseHas('audit_logs',['action'=>'crm.activities.rescheduled']);
    }

    public function test_opportunity_quote_link_enforces_tenant_and_customer_and_is_idempotent(): void
    {
        $customer=Customer::create(['name'=>'Client du devis','email'=>'quote-link@example.test']);
        $other=Customer::create(['name'=>'Autre client','email'=>'other-quote@example.test']);
        $product=\App\Models\Product::create([
            'reference'=>'CRM-Q1','name'=>'Service fictif','category'=>'Services',
            'price_cents'=>1000,'vat_bps'=>2000,'stock'=>0,'alert_threshold'=>0
        ]);
        $quoteId=$this->postJson('/api/quotes',[
            'customer_id'=>$customer->id,'items'=>[['product_id'=>$product->id,'quantity'=>1]]
        ])->assertCreated()->json('id');
        $opportunity=$this->postJson('/api/crm/opportunities',[
            'title'=>'Pipeline', 'customer_id'=>$customer->id
        ])->assertCreated()->json('id');
        $wrong=$this->postJson('/api/crm/opportunities',[
            'title'=>'Autre pipeline', 'customer_id'=>$other->id
        ])->assertCreated()->json('id');
        $this->postJson('/api/crm/opportunities/'.$wrong.'/quotes',[
            'quote_id'=>$quoteId
        ])->assertUnprocessable();
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/quotes',[
            'quote_id'=>$quoteId
        ])->assertCreated();
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/quotes',[
            'quote_id'=>$quoteId
        ])->assertOk();
        $this->getJson('/api/crm/opportunities/'.$opportunity.'/quotes')
            ->assertOk()->assertJsonPath('data.0.id',$quoteId);
        $this->assertSame(1,DB::table('crm_opportunity_quotes')->count());
        $foreign=Company::create(['code'=>'CRM_Q_B','name'=>'Autre entreprise quote'])->id;
        $this->login('admin','qb',$foreign);
        $this->getJson('/api/crm/opportunities/'.$opportunity.'/quotes')->assertNotFound();
    }

    public function test_tenant_scoped_crm_kpi_forecast_and_overdue_counts(): void
    {
        $this->postJson('/api/crm/prospects',['name'=>'Prospect KPI'])->assertCreated();
        $this->postJson('/api/crm/opportunities',[
            'title'=>'Projet KPI','amount_cents'=>100000,'probability_bps'=>5000
        ])->assertCreated();
        $this->postJson('/api/crm/activities',[
            'title'=>'Relance ancienne','due_at'=>'2025-01-01T09:00:00Z'
        ])->assertCreated();
        $this->getJson('/api/crm/summary')->assertOk()
            ->assertJsonPath('prospects_open',1)
            ->assertJsonPath('opportunities_open',1)
            ->assertJsonPath('forecast_cents',50000)
            ->assertJsonPath('activities_overdue',1);
        $foreign=Company::create(['code'=>'CRM_KPI_B','name'=>'Entreprise KPI B'])->id;
        $this->login('admin','kpi',$foreign);
        $this->getJson('/api/crm/summary')->assertOk()
            ->assertJsonPath('prospects_open',0)
            ->assertJsonPath('forecast_cents',0);
    }

    public function test_crm_csv_export_is_scoped_audited_and_blocks_spreadsheet_formulas(): void
    {
        Customer::create(['name'=>'=HYPERLINK("https://example.invalid")','email'=>'formula-export@example.test']);
        $response=$this->get('/api/crm/export/customers')->assertOk()
            ->assertHeader('content-type','text/csv; charset=UTF-8');
        $csv=$response->getContent();
        $this->assertStringContainsString('formula-export@example.test',$csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('password',$csv);
        $this->assertDatabaseHas('audit_logs',['action'=>'crm.export.downloaded']);

        $prospect=$this->postJson('/api/crm/prospects',[
            'name'=>'Export lead','email'=>'exportlead@example.test',
        ])->assertCreated()->json('id');
        $this->get('/api/crm/export/prospects')->assertOk()
            ->assertSee('Export lead');
        $this->get('/api/crm/export/invoices')->assertNotFound();

        $foreign=Company::create(['code'=>'CRM_EXPORT_B','name'=>'Exports B'])->id;
        $this->login('admin','export-b',$foreign);
        $this->get('/api/crm/export/customers')->assertOk()->assertDontSee('formula-export@example.test');
        $this->get('/api/crm/export/prospects')->assertOk()->assertDontSee('exportlead@example.test');

        $this->login('reader','export-reader',$this->company);
        $this->get('/api/crm/export/customers')->assertForbidden();
        $this->get('/api/crm/export/contacts')->assertForbidden();
    }

    public function test_reversible_customer_duplicate_link_preserves_both_rows_and_history(): void
    {
        $canonical=Customer::create(['name'=>'Deux entreprises Alpha','email'=>'alpha-canon@example.test','phone'=>'0611223344']);
        $duplicate=Customer::create(['name'=>'Deux entreprises Alpha','email'=>'alpha-dup@example.test','phone'=>'0611223344']);
        $candidates=$this->getJson('/api/crm/customers/duplicate-candidates')->assertOk();
        $this->assertNotEmpty($candidates->json('candidates'));
        $link=$this->postJson('/api/crm/customers/duplicate-links',[
            'duplicate_customer_id'=>$duplicate->id,'canonical_customer_id'=>$canonical->id,
            'reason'=>'Même établissement vérifié manuellement',
        ])->assertCreated()->assertJsonPath('active',true);
        $id=$link->json('id');
        $this->assertNotNull($duplicate->fresh()->archived_at);
        $this->assertNotNull($canonical->fresh());
        $this->getJson('/api/customers/'.$duplicate->id)->assertOk();
        $this->postJson('/api/crm/customers/duplicate-links',[
            'duplicate_customer_id'=>$duplicate->id,'canonical_customer_id'=>$canonical->id,
            'reason'=>'Rejeu identique confirmé',
        ])->assertOk()->assertJsonPath('id',$id);
        $this->postJson('/api/crm/customers/duplicate-links',[
            'duplicate_customer_id'=>$canonical->id,'canonical_customer_id'=>$duplicate->id,
            'reason'=>'Tentative inversion impossible',
        ])->assertConflict();
        $this->postJson('/api/crm/customers/duplicate-links/'.$id.'/reverse',[
            'reason'=>'Fiches distinctes confirmées','expected_revision'=>1,
        ])->assertOk()->assertJsonPath('active',false);
        $this->assertNull($duplicate->fresh()->archived_at);
        $this->postJson('/api/crm/customers/duplicate-links/'.$id.'/reverse',[
            'reason'=>'Annulation rejouée','expected_revision'=>1,
        ])->assertConflict();
        $this->assertDatabaseHas('audit_logs',['action'=>'crm.customer.duplicate.linked']);
        $this->assertDatabaseHas('audit_logs',['action'=>'crm.customer.duplicate.reversed']);
    }

    public function test_duplicate_decisions_are_tenant_scoped_and_editor_only(): void
    {
        $first=Customer::create(['name'=>'Client doublon','email'=>'dup-one@example.test']);
        $second=Customer::create(['name'=>'Client doublon','email'=>'dup-two@example.test']);
        $this->login('reader','duplicate-reader',$this->company);
        $this->getJson('/api/crm/customers/duplicate-candidates')->assertForbidden();
        $this->postJson('/api/crm/customers/duplicate-links',[
            'duplicate_customer_id'=>$second->id,'canonical_customer_id'=>$first->id,
            'reason'=>'Lecteur non autorisé',
        ])->assertForbidden();
        $foreign=Company::create(['code'=>'CRM_DUP_B','name'=>'Entreprise doublons B'])->id;
        $this->login('admin','duplicate-other',$foreign);
        $this->getJson('/api/crm/customers/duplicate-links')->assertJsonPath('total',0);
        $this->postJson('/api/crm/customers/duplicate-links',[
            'duplicate_customer_id'=>$second->id,'canonical_customer_id'=>$first->id,
            'reason'=>'Tentative interentreprises',
        ])->assertNotFound();
    }

    public function test_marketing_activity_requires_active_contact_opt_in(): void
    {
        $customer=Customer::create(['name'=>'Contact tests','email'=>'contact-marketing@example.test']);
        $contact=$this->postJson('/api/crm/contacts',[
            'customer_id'=>$customer->id,'first_name'=>'Camille','last_name'=>'Tests',
            'email'=>'camille-marketing@example.test','consent'=>'refused',
        ])->assertCreated()->json('id');
        $base=['title'=>'Lettre commerciale','contact_id'=>$contact,
            'due_at'=>'2027-01-02T10:00:00+01:00','purpose'=>'marketing'];
        $this->postJson('/api/crm/activities',$base)->assertUnprocessable();
        $service=$this->postJson('/api/crm/activities',array_merge($base,['purpose'=>'service']))
            ->assertCreated()->assertJsonPath('purpose','service')->json('id');
        $this->putJson('/api/crm/activities/'.$service,[
            'purpose'=>'marketing','expected_revision'=>1,
        ])->assertUnprocessable();
        $this->putJson('/api/crm/contacts/'.$contact,[
            'consent'=>'granted','expected_revision'=>1,
        ])->assertOk();
        $this->postJson('/api/crm/activities',$base)
            ->assertCreated()->assertJsonPath('purpose','marketing');
        $this->postJson('/api/crm/activities',[
            'title'=>'Prospect sans consentement',
            'purpose'=>'marketing','due_at'=>'2027-01-02T10:00:00+01:00',
        ])->assertUnprocessable();
    }

    public function test_won_opportunity_requires_quote_conversion_or_detailed_business_reason(): void
    {
        $customer=Customer::create(['name'=>'Client affaire','email'=>'client-affaire@example.test']);
        $product=\App\Models\Product::create([
            'reference'=>'CRM-WON-1','name'=>'Prestation','category'=>'Services',
            'price_cents'=>5000,'vat_bps'=>2000,'stock'=>1,'alert_threshold'=>0
        ]);
        $quote=$this->postJson('/api/quotes',[
            'customer_id'=>$customer->id,'items'=>[['product_id'=>$product->id,'quantity'=>1]],
        ])->assertCreated()->json('id');
        $opportunity=$this->postJson('/api/crm/opportunities',[
            'customer_id'=>$customer->id,'title'=>'Vente validée',
        ])->assertCreated()->json('id');
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/quotes',[
            'quote_id'=>$quote,
        ])->assertCreated();
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/transition',[
            'target'=>'qualified','expected_revision'=>1,
        ])->assertOk();
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/transition',[
            'target'=>'proposal','expected_revision'=>2,
        ])->assertOk();
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/transition',[
            'target'=>'won','expected_revision'=>3,'reason'=>'Oui',
        ])->assertUnprocessable();
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/transition',[
            'target'=>'won','expected_revision'=>3,
            'reason'=>'Affaire gagnée et documentée hors devis',
        ])->assertOk()->assertJsonPath('stage','won');
        $this->postJson('/api/crm/opportunities/'.$opportunity.'/transition',[
            'target'=>'lost','expected_revision'=>4,'reason'=>'Pas autorisé',
        ])->assertConflict();
    }

    public function test_single_contact_can_represent_customer_and_supplier_in_same_tenant(): void
    {
        $customer=Customer::create(['name'=>'Groupe Alpha client','email'=>'role-client@example.test']);
        $supplier=\App\Models\Supplier::create(['name'=>'Groupe Alpha fournisseur','email'=>'role-supplier@example.test']);
        $this->postJson('/api/crm/contacts',[
            'first_name'=>'Nora','last_name'=>'Relations',
            'email'=>'nora-roles@example.test',
            'customer_id'=>$customer->id,'supplier_id'=>$supplier->id,
        ])->assertCreated()->assertJsonPath('supplier_id',$supplier->id)
            ->assertJsonPath('customer_id',$customer->id);
        $foreign=\App\Models\Company::create(['code'=>'CRM_CONTACT_SUP_B','name'=>'Another Supplier Tenant'])->id;
        $this->login('admin','contact-supplier',$foreign);
        $this->postJson('/api/crm/contacts',[
            'first_name'=>'Nora','last_name'=>'Cross tenant','supplier_id'=>$supplier->id,
        ])->assertUnprocessable();
    }

    public function test_segment_marketing_preview_excludes_unconsented_inactive_and_other_tenant_contacts(): void
    {
        $customer=Customer::create(['name'=>'Client segmenté','email'=>'segmented@example.test']);
        $segment=$this->postJson('/api/crm/segments',[
            'code'=>'OPTIN','name'=>'Cible autorisée','mode'=>'static'
        ])->assertCreated()->json('id');
        $this->putJson('/api/crm/segments/'.$segment.'/members',[
            'customer_id'=>$customer->id,'active'=>true
        ])->assertOk();
        $this->postJson('/api/crm/contacts',[
            'customer_id'=>$customer->id,'first_name'=>'Oui','last_name'=>'Consentement',
            'email'=>'yes-audience@example.test','consent'=>'granted'
        ])->assertCreated();
        $this->postJson('/api/crm/contacts',[
            'customer_id'=>$customer->id,'first_name'=>'Non','last_name'=>'Consentement',
            'email'=>'no-audience@example.test','consent'=>'refused'
        ])->assertCreated();
        $this->postJson('/api/crm/contacts',[
            'customer_id'=>$customer->id,'first_name'=>'Ancien','last_name'=>'Contact',
            'email'=>'inactive-audience@example.test','consent'=>'granted','active'=>false
        ])->assertCreated();
        $this->getJson('/api/crm/segments/'.$segment.'/marketing-audience')
            ->assertOk()->assertJsonPath('total',1)
            ->assertJsonPath('data.0.email','yes-audience@example.test');
        $this->assertDatabaseHas('audit_logs',['action'=>'crm.segment.marketing_audience.previewed']);
        $this->login('reader','audience-read',$this->company);
        $this->getJson('/api/crm/segments/'.$segment.'/marketing-audience')->assertForbidden();
        $companyB=Company::create(['code'=>'CRM_AUDIENCE_B','name'=>'Other Audience Tenant'])->id;
        $this->login('admin','audience-b',$companyB);
        $this->getJson('/api/crm/segments/'.$segment.'/marketing-audience')->assertNotFound();
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
