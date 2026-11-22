<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finance V5.1 domain contracts (fake customers, no external banking or email).
 * CI runs the same contracts on PostgreSQL as the runtime database.
 */
final class FinanceMenuTest extends TestCase
{
    use RefreshDatabase;

    private function member(Company $company, string $role, string $email): User
    {
        $user=User::create([
            'name'=>'Finance '.ucfirst($role),'email'=>$email,
            'password'=>'SyntheticFinance2026!',
            'role_id'=>Role::where('name','admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, ['role_id'=>Role::where('name',$role)->value('id')]);
        return $user;
    }
    private function login(User $user,Company $company): void
    {
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }
    private function payload(array $data,string $key): array
    {
        return $data+['request_key'=>$key];
    }
    private function fixtureInvoice(): int
    {
        // 100 EUR excl. VAT + 20% = 120 EUR incl. VAT.
        $customer=Customer::create(['name'=>'Entreprise Démo','email'=>'finance.test@example.test']);
        $product=Product::create([
            'reference'=>'M06-FICTIF','name'=>'Service fictif','category'=>'Services',
            'price_cents'=>10000,'vat_bps'=>2000,'stock'=>2,'alert_threshold'=>0,
        ]);
        $order=$this->postJson('/api/orders',[
            'customer_id'=>$customer->id,'items'=>[['product_id'=>$product->id,'quantity'=>1]],
        ])->assertCreated()->json('id');
        $this->postJson('/api/orders/'.$order.'/validate')->assertOk();
        $invoice=$this->postJson('/api/invoices',['order_id'=>$order])->assertCreated()->json('id');
        $this->patchJson('/api/invoices/'.$invoice,['status'=>'issued','due_at'=>'2026-11-01'])->assertOk();
        $this->assertDatabaseHas('invoices',['id'=>$invoice,'total_cents'=>12000,'status'=>'issued']);
        return (int)$invoice;
    }

    public function test_invoice_credit_receipt_balance_and_idempotency(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->login($this->member($company,'admin','m06-finance-admin@example.test'),$company);
        $invoice=$this->fixtureInvoice();
        $credit=$this->payload(['invoice_id'=>$invoice,'amount_cents'=>2400,'reason'=>'Réduction contractuelle fictive'], 'm06-credit-once');
        $this->postJson('/api/finance/credit-notes',$credit)->assertCreated()->assertJsonPath('amount_cents',2400);
        $this->postJson('/api/finance/credit-notes',$credit)->assertOk();
        $this->assertDatabaseCount('finance_credit_notes',1);
        $this->postJson('/api/finance/credit-notes',array_merge($credit,['amount_cents'=>3000]))->assertConflict();
        $this->postJson('/api/finance/credit-notes',$this->payload([
            'invoice_id'=>$invoice,'amount_cents'=>10000,'reason'=>'Trop élevé pour le solde',
        ],'m06-credit-overlimit'))->assertConflict();

        $receipt=$this->postJson('/api/finance/collections',$this->payload([
            'amount_cents'=>6000,'received_on'=>'2026-11-22','method'=>'bank','currency'=>'EUR',
            'reference'=>'VIREMENT-DEMO'
        ],'m06-receipt-once'))->assertCreated()->json('id');
        $allocation=$this->payload(['invoice_id'=>$invoice,'amount_cents'=>6000],'m06-allocation-once');
        $this->postJson('/api/finance/collections/'.$receipt.'/allocate',$allocation)->assertCreated();
        $this->postJson('/api/finance/collections/'.$receipt.'/allocate',$allocation)->assertOk();
        $this->getJson('/api/finance/invoices/'.$invoice.'/balance')->assertOk()
            ->assertJsonPath('total_cents',12000)
            ->assertJsonPath('credit_cents',2400)
            ->assertJsonPath('allocated_cents',6000)
            ->assertJsonPath('outstanding_cents',3600);
        $this->assertDatabaseCount('finance_receipt_allocations',1);
        $this->postJson('/api/finance/collections/'.$receipt.'/allocate',
            $this->payload(['invoice_id'=>$invoice,'amount_cents'=>1000],'m06-allocate-too-much'))->assertConflict();
        $this->assertDatabaseCount('finance_outbox',3);
        $this->getJson('/api/invoices/'.$invoice)->assertOk()
            ->assertJsonPath('total_cents',12000)->assertJsonPath('status','issued');
    }

    public function test_reader_and_other_tenant_cannot_write_or_read_financial_records(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M06_COMPANY_B','name'=>'Entreprise fictive B']);
        $adminA=$this->member($a,'admin','m06-company-a@example.test');
        $readerA=$this->member($a,'reader','m06-reader@example.test');
        $adminB=$this->member($b,'admin','m06-company-b@example.test');
        $this->login($adminA,$a);
        $invoice=$this->fixtureInvoice();
        $credit=$this->postJson('/api/finance/credit-notes',$this->payload([
            'invoice_id'=>$invoice,'amount_cents'=>1000,'reason'=>'Avoir fictif autorisé',
        ],'m06-tenant-credit'))->assertCreated()->json('id');
        $this->login($readerA,$a);
        foreach(['credit-notes','collections','payment-reminders','expenses','budgets','accounting-exports'] as $page){
            $this->getJson('/api/finance/'.$page)->assertOk();
            $this->postJson('/api/finance/'.$page,[])->assertForbidden();
        }
        $this->login($adminB,$b);
        $this->getJson('/api/finance/credit-notes')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/finance/invoices/'.$invoice.'/balance')->assertNotFound();
        $this->postJson('/api/finance/credit-notes',$this->payload([
            'invoice_id'=>$invoice,'amount_cents'=>100,'reason'=>'Interdit entre entreprises',
        ],'m06-cross-tenant'))->assertNotFound();
        $this->assertDatabaseHas('finance_credit_notes',['id'=>$credit,'company_id'=>$a->id]);
        $this->getJson('/api/finance/payment-reminders')->assertOk()->assertJsonPath('total',0);
    }

    public function test_payment_reminders_are_suspended_by_approved_dispute(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $first=$this->member($company,'admin','m06-reminder-a@example.test');
        $second=$this->member($company,'admin','m06-reminder-b@example.test');
        $this->login($first,$company);
        $invoice=$this->fixtureInvoice();
        $this->putJson('/api/finance/reminder-policy',[
            'cadence_days'=>7,'enabled'=>true,'template_version'=>'fake-v1',
        ])->assertOk();
        $dispute=$this->postJson('/api/finance/disputes',[
            'invoice_id'=>$invoice,'reason'=>'Montant soumis à contestation fictive',
        ])->assertCreated()->json('id');
        $this->postJson('/api/finance/disputes/'.$dispute.'/decision',['decision'=>'approved'])->assertForbidden();
        $this->login($second,$company);
        $this->postJson('/api/finance/disputes/'.$dispute.'/decision',['decision'=>'approved'])->assertOk();
        $this->postJson('/api/finance/payment-reminders',$this->payload([
            'invoice_id'=>$invoice,'channel'=>'email'
        ],'m06-disputed-reminder'))->assertConflict();
        $this->postJson('/api/finance/disputes/'.$dispute.'/decision',['decision'=>'resolved'])->assertOk();
        $this->postJson('/api/finance/payment-reminders',$this->payload([
            'invoice_id'=>$invoice,'channel'=>'email'
        ],'m06-approved-reminder'))->assertCreated()->assertJsonPath('status','prepared');
        $this->assertDatabaseCount('finance_reminders',1);
    }

    public function test_expense_approval_requires_second_person_and_conserves_budget(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $first=$this->member($company,'admin','m06-expense-a@example.test');
        $second=$this->member($company,'admin','m06-expense-b@example.test');
        $this->login($first,$company);
        $center=$this->postJson('/api/finance/cost-centers',[
            'code'=>'OPS','name'=>'Opérations fictives'
        ])->assertCreated()->json('id');
        $budget=$this->postJson('/api/finance/budgets',[
            'cost_center_id'=>$center,'fiscal_year'=>2026,'currency'=>'EUR',
            'limit_cents'=>10000,'forecast_cents'=>8000
        ])->assertCreated()->json('id');
        $this->postJson('/api/finance/budgets/'.$budget.'/approve')->assertForbidden();
        $this->login($second,$company);
        $this->postJson('/api/finance/budgets/'.$budget.'/approve')->assertOk();
        $this->login($first,$company);
        $expense=$this->postJson('/api/finance/expenses',$this->payload([
            'cost_center_id'=>$center,'supplier_name'=>'Fournisseur Fictif',
            'category'=>'Services','receipt_reference'=>'FAUX-JUSTIF-001',
            'receipt_digest'=>str_repeat('a',64),'expense_date'=>'2026-11-21',
            'amount_cents'=>6000,'currency'=>'EUR'
        ],'m06-expense-once'))->assertCreated()->json('id');
        $this->postJson('/api/finance/expenses/'.$expense.'/decision',['decision'=>'approved'])->assertForbidden();
        $this->login($second,$company);
        $this->postJson('/api/finance/expenses/'.$expense.'/decision',['decision'=>'approved'])->assertOk();
        $this->getJson('/api/finance/budgets/'.$budget.'/summary')->assertOk()
            ->assertJsonPath('consumed_cents',6000)->assertJsonPath('available_cents',4000);
        $this->assertDatabaseCount('finance_budget_movements',1);
    }

    public function test_posted_ledger_balances_and_closed_period_export_is_replayable(): void
    {
        $company=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $maker=$this->member($company,'admin','m06-ledger-maker@example.test');
        $approver=$this->member($company,'admin','m06-ledger-approver@example.test');
        $this->login($maker,$company);
        $debit=$this->postJson('/api/finance/accounts',['code'=>'411','name'=>'Clients'])->assertCreated()->json('id');
        $credit=$this->postJson('/api/finance/accounts',['code'=>'706','name'=>'Services'])->assertCreated()->json('id');
        $journal=$this->postJson('/api/finance/journals',['code'=>'VE','name'=>'Ventes'])->assertCreated()->json('id');
        $period=$this->postJson('/api/finance/periods',[
            'code'=>'2026-11','starts_on'=>'2026-11-01','ends_on'=>'2026-11-30'
        ])->assertCreated()->json('id');
        $base=['journal_id'=>$journal,'period_id'=>$period,
            'entry_date'=>'2026-11-22','reference'=>'VENTE-FAUSSE',
            'description'=>'Écriture de contrôle fictive'];
        $this->postJson('/api/finance/entries',$this->payload($base+[
            'lines'=>[['account_id'=>$debit,'debit_cents'=>12000,'credit_cents'=>0],
                      ['account_id'=>$credit,'debit_cents'=>0,'credit_cents'=>11000]]
        ],'m06-unbalanced'))->assertUnprocessable();
        $entry=$this->postJson('/api/finance/entries',$this->payload($base+[
            'lines'=>[['account_id'=>$debit,'debit_cents'=>12000,'credit_cents'=>0],
                      ['account_id'=>$credit,'debit_cents'=>0,'credit_cents'=>12000]]
        ],'m06-balanced'))->assertCreated()->json('id');
        $this->postJson('/api/finance/entries/'.$entry.'/post')->assertForbidden();
        $this->login($approver,$company);
        $this->postJson('/api/finance/entries/'.$entry.'/post')->assertOk();
        $this->postJson('/api/finance/periods/'.$period.'/close')->assertOk();
        $this->postJson('/api/finance/entries',$this->payload($base+[
            'lines'=>[['account_id'=>$debit,'debit_cents'=>100,'credit_cents'=>0],
                      ['account_id'=>$credit,'debit_cents'=>0,'credit_cents'=>100]]
        ],'m06-after-close'))->assertConflict();
        $payload=$this->payload(['period_id'=>$period],'m06-export-once');
        $export=$this->postJson('/api/finance/accounting-exports',$payload)->assertCreated()
            ->assertJsonPath('row_count',2)->assertJsonPath('debit_cents',12000)
            ->assertJsonPath('credit_cents',12000)->json('id');
        $this->postJson('/api/finance/accounting-exports',$payload)->assertOk();
        $this->assertDatabaseCount('finance_export_batches',1);
        $response=$this->get('/api/finance/accounting-exports/'.$export.'/download')->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');
        $this->assertStringContainsString('VENTE-FAUSSE',$response->getContent());
    }
}
