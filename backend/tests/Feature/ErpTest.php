<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->user('admin');
        $this->actingAs($this->admin);
        $this->customer = Customer::create(['name' => 'Client Test', 'email' => 'client@test.example', 'address' => 'Adresse fictive']);
        $this->product = Product::create(['reference' => 'TEST-01', 'name' => 'Produit test', 'category' => 'Tests', 'price_cents' => 999, 'vat_bps' => 550, 'stock' => 10, 'alert_threshold' => 3]);
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => $role, 'email' => $role.'@test.example', 'password' => 'PasswordTest2026!', 'role_id' => Role::where('name', $role)->value('id')]);
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $user->companies()->attach($company->id, ['role_id' => Role::where('name', $role)->value('id')]);
        return $user;
    }

    private function order(int $quantity = 2): int
    {
        return $this->postJson('/api/orders', ['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]]])->assertCreated()->json('id');
    }

    private function invoice(): int
    {
        $id = $this->order();
        $this->postJson("/api/orders/$id/validate")->assertOk();

        return $this->postJson('/api/invoices', ['order_id' => $id])->assertCreated()->json('id');
    }

    public function test_authentication_and_logout(): void
    {
        auth()->logout();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $this->admin->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $this->admin->email, 'password' => 'PasswordTest2026!'])->assertOk()->assertJsonPath('role.name', 'admin');
        $this->postJson('/api/logout')->assertNoContent();
        // A new HTTP request rebuilds guards; the test application persists them.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_reader_cannot_mutate_or_see_admin_data(): void
    {
        $this->actingAs($this->user('reader'));
        foreach (['/api/customers', '/api/products', '/api/quotes', '/api/orders', '/api/invoices', '/api/dashboard'] as $url) {
            $this->getJson($url)->assertOk();
        }
        foreach (['/api/customers', '/api/products', '/api/quotes', '/api/orders', '/api/invoices', '/api/users'] as $url) {
            $this->postJson($url, [])->assertForbidden();
        }
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/audit-logs')->assertForbidden();
        $this->postJson('/api/products/'.$this->product->id.'/stock', ['quantity' => 1, 'reason' => 'Test'])->assertForbidden();
    }

    public function test_role_separation(): void
    {
        $this->actingAs($this->user('stock'));
        $this->postJson('/api/products/'.$this->product->id.'/stock', ['quantity' => 3, 'reason' => 'Réception'])->assertOk();
        $this->postJson('/api/orders', [])->assertForbidden();
        $this->postJson('/api/customers', [])->assertForbidden();
        $this->actingAs($this->user('commercial'));
        $this->postJson('/api/products', [])->assertForbidden();
        $this->order();
    }

    public function test_contacts_crud_search_pagination_and_duplicates(): void
    {
        foreach (['customers', 'suppliers'] as $kind) {
            $id = $this->postJson('/api/'.$kind, ['name' => 'Équipe Alpha', 'email' => "$kind@test.example"])->assertCreated()->json('id');
            $this->getJson('/api/'.$kind.'?search=alpha')->assertOk()->assertJsonPath('total', 1);
            $this->putJson("/api/$kind/$id", ['name' => 'Bravo', 'email' => "$kind@test.example", 'address' => 'Lyon'])->assertOk();
            $this->postJson('/api/'.$kind, ['name' => 'Duplicate', 'email' => "$kind@test.example"])->assertUnprocessable();
            $this->deleteJson("/api/$kind/$id")->assertNoContent();
        }
        for ($i = 0; $i < 24; $i++) {
            Customer::create(['name' => 'Extra '.$i, 'email' => "extra$i@test.example"]);
        }
        $this->getJson('/api/customers?page=2')->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(5, 'data');
    }

    public function test_customer_history_and_referenced_deletion_are_protected(): void
    {
        $this->order();
        $this->getJson('/api/customers/'.$this->customer->id)->assertOk()->assertJsonCount(1, 'orders');
        $this->deleteJson('/api/customers/'.$this->customer->id)->assertConflict();
    }

    public function test_product_crud_filters_and_stock_audit(): void
    {
        $payload = ['reference' => 'NEW', 'name' => 'New product', 'category' => 'Test', 'price_cents' => 1500, 'vat_bps' => 2000, 'alert_threshold' => 2, 'stock' => 999];
        $id = $this->postJson('/api/products', $payload)->assertCreated()->assertJsonPath('stock', 0)->json('id');
        $this->getJson('/api/products?low_stock=1')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/products/$id/stock", ['quantity' => 5, 'reason' => 'Livraison'])->assertOk()->assertJsonPath('stock', 5);
        $this->postJson("/api/products/$id/stock", ['quantity' => -6, 'reason' => 'Sortie'])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $id, 'stock' => 5]);
        $this->assertDatabaseCount('stock_movements', 1);
        $payload['name'] = 'Updated';
        $this->putJson("/api/products/$id", $payload)->assertOk()->assertJsonPath('stock', 5);
        $this->getJson('/api/stock-movements?product_id='.$id)->assertOk()->assertJsonPath('total', 1);
        $this->deleteJson("/api/products/$id")->assertConflict();
        $payload['reference'] = 'UNUSED';
        $unused = $this->postJson('/api/products', $payload)->assertCreated()->json('id');
        $this->deleteJson("/api/products/$unused")->assertNoContent();
    }

    public function test_quote_conversion_keeps_prices_and_is_single_use(): void
    {
        $q = $this->postJson('/api/quotes', ['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 3]]])->assertCreated()->assertJsonPath('total_cents', 3162)->json('id');
        $this->product->update(['price_cents' => 5000]);
        $this->postJson("/api/quotes/$q/convert")->assertCreated()->assertJsonPath('total_cents', 3162);
        $this->postJson("/api/quotes/$q/convert")->assertConflict();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_order_validation_is_atomic_and_single_use(): void
    {
        $id = $this->order(3);
        $this->postJson("/api/orders/$id/validate")->assertOk()->assertJsonPath('status', 'validated');
        $this->assertSame(7, $this->product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->postJson("/api/orders/$id/validate")->assertConflict();
        $this->assertSame(7, $this->product->fresh()->stock);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.validated', 'entity_id' => $id]);
    }

    public function test_partial_stock_deductions_roll_back_on_insufficient_stock(): void
    {
        $p = Product::create(['reference' => 'EMPTY', 'name' => 'Empty', 'category' => 'Test', 'price_cents' => 100, 'vat_bps' => 2000, 'stock' => 0]);
        $id = $this->postJson('/api/orders', ['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 2], ['product_id' => $p->id, 'quantity' => 1]]])->assertCreated()->json('id');
        $this->postJson("/api/orders/$id/validate")->assertUnprocessable();
        $this->assertSame(10, $this->product->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'draft']);
    }

    public function test_invalid_lines_and_untrusted_totals_are_handled(): void
    {
        $payload = ['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 0]]];
        $this->postJson('/api/orders', $payload)->assertUnprocessable();
        $payload['items'][0]['quantity'] = 1;
        $payload['total_cents'] = 1;
        $payload['items'][0]['price_cents'] = 1;
        $this->postJson('/api/orders', $payload)->assertCreated()->assertJsonPath('total_cents', 1054);
        $payload['items'][] = $payload['items'][0];
        $this->postJson('/api/orders', $payload)->assertUnprocessable();
    }

    public function test_full_invoice_lifecycle_pdf_snapshot_and_uniqueness(): void
    {
        $id = $this->invoice();
        $inv = Invoice::findOrFail($id);
        $this->postJson('/api/invoices', ['order_id' => $inv->order_id])->assertConflict();
        $this->patchJson("/api/invoices/$id", ['status' => 'paid'])->assertConflict();
        $this->patchJson("/api/invoices/$id", ['status' => 'issued'])->assertOk()->assertJsonPath('status', 'issued');
        $this->assertStringStartsWith('FAC-', $inv->fresh()->number);
        $this->customer->update(['name' => 'Changed']);
        $this->product->update(['name' => 'Changed']);
        $this->getJson("/api/invoices/$id")->assertOk()->assertJsonPath('snapshot.customer.name', 'Client Test')->assertJsonPath('snapshot.items.0.name', 'Produit test');
        $pdf = $this->get("/api/invoices/$id/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->patchJson("/api/invoices/$id", ['status' => 'paid'])->assertOk();
        $this->patchJson("/api/invoices/$id", ['status' => 'cancelled'])->assertConflict();
        $second = $this->invoice();
        $this->patchJson("/api/invoices/$second", ['status' => 'issued'])->assertOk();
        $this->assertNotSame($inv->fresh()->number, Invoice::find($second)->number);
    }

    public function test_cancelled_invoice_keeps_number_and_does_not_restore_stock(): void
    {
        $id = $this->invoice();
        $this->patchJson("/api/invoices/$id", ['status' => 'issued'])->assertOk();
        $number = Invoice::find($id)->number;
        $this->patchJson("/api/invoices/$id", ['status' => 'cancelled'])->assertOk();
        $this->assertSame($number, Invoice::find($id)->number);
        $this->assertSame(8, $this->product->fresh()->stock);
        $this->patchJson("/api/invoices/$id", ['status' => 'issued'])->assertConflict();
    }

    public function test_draft_order_cannot_be_invoiced(): void
    {
        $id = $this->order();
        $this->postJson('/api/invoices', ['order_id' => $id])->assertConflict();
    }

    public function test_dashboard_period_revenue_top_products_and_alerts(): void
    {
        $id = $this->invoice();
        $this->patchJson("/api/invoices/$id", ['status' => 'issued'])->assertOk();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('revenue_cents', 1998)->assertJsonPath('orders_count', 1)->assertJsonPath('outstanding_cents', 2108)->assertJsonCount(1, 'top_products');
        $this->getJson('/api/dashboard?from=2000-01-01&to=2000-01-02')->assertOk()->assertJsonPath('revenue_cents', 0);
        $this->getJson('/api/dashboard?from=2026-02-10&to=2026-02-01')->assertUnprocessable();
        $this->patchJson("/api/invoices/$id", ['status' => 'cancelled'])->assertOk();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('revenue_cents', 0);
    }

    public function test_admin_management_last_admin_and_audit(): void
    {
        $this->putJson('/api/users/'.$this->admin->id, ['name' => 'Admin', 'email' => $this->admin->email, 'role_id' => Role::where('name', 'reader')->value('id')])->assertConflict();
        $this->deleteJson('/api/users/'.$this->admin->id)->assertConflict();
        $id = $this->postJson('/api/users', ['name' => 'New reader', 'email' => 'new@test.example', 'password' => 'SafePassword123!', 'role_id' => Role::where('name', 'reader')->value('id')])->assertCreated()->assertJsonMissingPath('password')->json('id');
        $this->putJson("/api/users/$id", ['role_id' => Role::where('name', 'stock')->value('id')])->assertOk()->assertJsonPath('role.name', 'stock');
        $this->deleteJson("/api/users/$id")->assertNoContent();
        $this->getJson('/api/audit-logs')->assertOk()->assertJsonPath('total', 3);
        $this->assertStringNotContainsString('SafePassword', AuditLog::all()->toJson());
    }

    public function test_sql_injection_search_is_bound_and_missing_ids_return_404(): void
    {
        $this->getJson('/api/customers?search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/orders/999999')->assertNotFound();
        $this->postJson('/api/products/'.$this->product->id.'/stock', ['quantity' => 0, 'reason' => 'Zero'])->assertUnprocessable();
    }

    public function test_postgres_check_constraints_reject_negative_stock(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL specific check constraint');
        }
        $this->expectException(QueryException::class);
        DB::table('products')->where('id',$this->product->id)->update(['stock' => -1]);
    }
}
