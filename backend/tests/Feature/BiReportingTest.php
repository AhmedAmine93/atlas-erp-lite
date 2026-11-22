<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BiReportingTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email, string $role, Company $company): User
    {
        $u = User::create([
            'name'=>$email, 'email'=>$email, 'password'=>'PasswordTest2026!',
            'role_id'=>Role::where('name', $role)->value('id'),
        ]);
        $u->companies()->attach($company->id, [
            'role_id'=>Role::where('name', $role)->value('id')
        ]);
        return $u;
    }

    public function test_anonymous_and_unprivileged_roles_are_denied(): void
    {
        $this->getJson('/api/analytics/sales')->assertUnauthorized();
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $stock=$this->account('bi-stock@example.test','stock',$a);
        $this->actingAs($stock);
        $this->getJson('/api/analytics/stock')->assertOk()
            ->assertJsonPath('dataset','stock');
        $this->getJson('/api/analytics/finance')->assertForbidden();
        $this->getJson('/api/analytics/reports')->assertForbidden();
        $this->postJson('/api/analytics/exports',[])->assertForbidden();
    }

    public function test_company_isolation_for_canonical_analytics(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'BI_OTHER','name'=>'Other BI tenant']);
        $reader=$this->account('bi-reader@example.test','reader',$a);
        $this->actingAs($reader);
        Product::create(['reference'=>'TENANT_A_BI','name'=>'A product','category'=>'BI',
            'price_cents'=>100,'vat_bps'=>2000,'stock'=>4,'alert_threshold'=>5]);
        $this->getJson('/api/analytics/stock')->assertOk()->assertJsonPath('cards.products_count',1)
            ->assertJsonCount(1,'rows');
        $this->getJson('/api/analytics/stock?company_id='.$b->id)->assertUnprocessable();
        $this->postJson('/api/tenant/switch',['company_id'=>$b->id])->assertForbidden();
        $this->getJson('/api/analytics/stock')->assertOk()->assertJsonPath('cards.products_count',1);
    }

    public function test_finance_is_not_inflated_by_order_or_draft_invoice(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->account('bi-finance@example.test','admin',$a);
        $this->actingAs($admin);
        // No issued invoices: a validated order is not revenue.
        $this->getJson('/api/analytics/finance')->assertOk()
            ->assertJsonPath('cards.gross_invoiced_ttc_cents',0)
            ->assertJsonPath('cards.credit_notes_ttc_cents',null)
            ->assertJsonPath('cards.actual_cash_ttc_cents',null);
        $this->getJson('/api/analytics/sales')->assertOk()
            ->assertJsonPath('cards.revenue_ttc_cents',0);
    }

    public function test_admin_cannot_execute_non_allowlisted_sql_or_export_sensitive_supplier_email(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->account('bi-report@example.test','admin',$a);
        $this->actingAs($admin);
        $payload=['title'=>'Supplier report','dataset'=>'suppliers','columns'=>['email'],
            'idempotency_key'=>'bi-invalid-col-0001'];
        $this->postJson('/api/analytics/reports',$payload)->assertUnprocessable();
        $this->assertDatabaseCount('bi_report_definitions',0);
        $this->postJson('/api/analytics/reports',[
            'title'=>'Raw query','dataset'=>'suppliers','columns'=>['name'],
            'filters'=>['where_raw'=>'1=1'],'idempotency_key'=>'bi-invalid-sql-0001'
        ])->assertUnprocessable();
        $this->postJson('/api/analytics/reports',[
            'title'=>'Company injection','dataset'=>'suppliers','columns'=>['name'],
            'company_id'=>9999,'idempotency_key'=>'bi-invalid-tenant-1'
        ])->assertUnprocessable();
        $this->getJson('/api/analytics/suppliers')->assertOk()->assertJsonMissing(['email']);
    }

    public function test_definition_idempotency_conflict_versioning_and_export_download(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $admin=$this->account('bi-version@example.test','admin',$a);
        $this->actingAs($admin);
        $payload=['title'=>'Version A','dataset'=>'stock','columns'=>['reference','stock'],
            'filters'=>['from'=>'2026-01-01','to'=>'2026-12-31'],
            'idempotency_key'=>'bi-definition-version-0001'];
        $first=$this->postJson('/api/analytics/reports',$payload)->assertCreated();
        $id=$first->json('id');
        $uid=$first->json('report_uid');
        $this->postJson('/api/analytics/reports',$payload)->assertOk()
            ->assertJsonPath('replayed',true)->assertJsonPath('id',$id);
        $this->postJson('/api/analytics/reports',[...$payload,'title'=>'Different'])->assertConflict();
        $second=$this->postJson('/api/analytics/reports',[...$payload,
            'report_uid'=>$uid,'title'=>'Version B','idempotency_key'=>'bi-definition-version-0002'])
            ->assertCreated()->assertJsonPath('version',2);
        $this->postJson('/api/analytics/reports/'.$id.'/run')->assertOk()
            ->assertJsonPath('report_version',1);
        $this->assertDatabaseCount('bi_report_runs',1);
        $this->assertDatabaseCount('bi_report_definitions',2);
        $export=$this->postJson('/api/analytics/exports',[
            'dataset'=>'stock','from'=>'2026-01-01','to'=>'2026-12-31',
            'definition_id'=>$second->json('id')
        ])->assertCreated();
        $exportId=$export->json('id');
        $this->get('/api/analytics/exports/'.$exportId.'/download')
            ->assertOk()->assertHeader('Content-Type','text/csv; charset=utf-8')
            ->assertSee('reference');
        DB::table('bi_report_exports')->where('id',$exportId)->update(['expires_at'=>now()->subMinute()]);
        $this->get('/api/analytics/exports/'.$exportId.'/download')->assertStatus(410);
    }

    public function test_export_download_checks_current_company_membership_and_owner(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'BI_B','name'=>'Tenant B']);
        $admin=$this->account('bi-owner@example.test','admin',$a);
        $other=$this->account('bi-other@example.test','admin',$a);
        $admin->companies()->attach($b->id,['role_id'=>Role::where('name','reader')->value('id')]);
        $this->actingAs($admin);
        $id=$this->postJson('/api/analytics/exports',[
            'dataset'=>'stock','from'=>'2026-01-01','to'=>'2026-12-31'
        ])->assertCreated()->json('id');
        $this->actingAs($other);
        $this->get('/api/analytics/exports/'.$id.'/download')->assertNotFound();
        $this->actingAs($admin);
        $this->postJson('/api/tenant/switch',['company_id'=>$b->id])->assertOk();
        $this->get('/api/analytics/exports/'.$id.'/download')->assertForbidden();
        $this->postJson('/api/tenant/switch',['company_id'=>$a->id])->assertOk();
        $this->get('/api/analytics/exports/'.$id.'/download')->assertOk();
    }
}
