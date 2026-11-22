<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantDatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function pgOnly(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('F14 composite tenant FK assertions require PostgreSQL.');
        }
    }

    private function base(User $actor, Company $company, string $tag): array
    {
        $time = now();

        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $company->id,
            'name' => "Customer {$tag}",
            'email' => "customer-{$tag}@example.test",
            'created_at' => $time, 'updated_at' => $time,
        ]);
        $productId = DB::table('products')->insertGetId([
            'company_id' => $company->id,
            'reference' => "PRODUCT-{$tag}",
            'name' => "Product {$tag}", 'category' => 'Test',
            'price_cents' => 1000, 'vat_bps' => 2000,
            'stock' => 20, 'alert_threshold' => 3,
            'created_at' => $time, 'updated_at' => $time,
        ]);
        $quoteId = DB::table('quotes')->insertGetId([
            'company_id' => $company->id, 'number' => "DEV-{$tag}",
            'customer_id' => $customerId, 'status' => 'draft',
            'subtotal_cents' => 1000, 'tax_cents' => 200, 'total_cents' => 1200,
            'created_by' => $actor->id, 'created_at' => $time, 'updated_at' => $time,
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $company->id, 'number' => "CMD-{$tag}",
            'customer_id' => $customerId, 'quote_id' => $quoteId, 'status' => 'draft',
            'subtotal_cents' => 1000, 'tax_cents' => 200, 'total_cents' => 1200,
            'created_by' => $actor->id, 'created_at' => $time, 'updated_at' => $time,
        ]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'company_id' => $company->id,
            'order_id' => $orderId, 'status' => 'draft',
            'snapshot' => json_encode(['demo' => true]),
            'subtotal_cents' => 1000, 'tax_cents' => 200, 'total_cents' => 1200,
            'created_at' => $time, 'updated_at' => $time,
        ]);
        $stockId = DB::table('stock_movements')->insertGetId([
            'company_id' => $company->id, 'product_id' => $productId,
            'quantity' => 1, 'balance_after' => 20,
            'reason' => 'Synthetic F14', 'order_id' => $orderId,
            'user_id' => $actor->id, 'created_at' => $time, 'updated_at' => $time,
        ]);
        $quoteItemId = DB::table('quote_items')->insertGetId([
            'company_id' => $company->id, 'quote_id' => $quoteId,
            'product_id' => $productId, 'reference' => "PRODUCT-{$tag}",
            'name' => "Product {$tag}", 'quantity' => 1,
            'price_cents' => 1000, 'vat_bps' => 2000,
            'subtotal_cents' => 1000, 'tax_cents' => 200, 'total_cents' => 1200,
        ]);
        $orderItemId = DB::table('order_items')->insertGetId([
            'company_id' => $company->id, 'order_id' => $orderId,
            'product_id' => $productId, 'reference' => "PRODUCT-{$tag}",
            'name' => "Product {$tag}", 'quantity' => 1,
            'price_cents' => 1000, 'vat_bps' => 2000,
            'subtotal_cents' => 1000, 'tax_cents' => 200, 'total_cents' => 1200,
        ]);
        $incidentId = DB::table('support_incidents')->insertGetId([
            'company_id' => $company->id, 'reference' => "INC-F14-{$tag}",
            'title' => "F14 incident {$tag}", 'description' => 'Entirely synthetic',
            'severity' => 'P3', 'status' => 'open', 'environment' => 'demo',
            'reported_by' => $actor->id, 'created_at' => $time, 'updated_at' => $time,
        ]);
        $eventId = DB::table('support_incident_events')->insertGetId([
            'company_id' => $company->id, 'support_incident_id' => $incidentId,
            'actor_id' => $actor->id, 'action' => 'created',
            'created_at' => $time, 'updated_at' => $time,
        ]);
        $diagnosticId = DB::table('support_diagnostic_runs')->insertGetId([
            'company_id' => $company->id, 'support_incident_id' => $incidentId,
            'triggered_by' => $actor->id, 'check_name' => 'database',
            'status' => 'ok', 'duration_ms' => 1,
            'created_at' => $time, 'updated_at' => $time,
        ]);

        return [
            'company' => $company->id, 'customer' => $customerId,
            'product' => $productId, 'quote' => $quoteId,
            'order' => $orderId, 'invoice' => $invoiceId,
            'stock' => $stockId, 'quote_item' => $quoteItemId,
            'order_item' => $orderItemId, 'incident' => $incidentId,
            'event' => $eventId, 'diagnostic' => $diagnosticId,
        ];
    }

    /**
     * RefreshDatabase wraps each test in a transaction; a violated
     * PostgreSQL FK needs a nested transaction/savepoint before continuing.
     */
    private function reject(callable $statement): void
    {
        try {
            DB::transaction(function () use ($statement): void {
                $statement();
            });
            $this->fail('Expected PostgreSQL to reject a cross-tenant foreign key.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0] ?? null);
        }
    }

    public function test_postgres_rejects_cross_tenant_references_even_via_raw_sql(): void
    {
        $this->pgOnly();

        $actor = User::create([
            'name' => 'F14 actor', 'email' => 'f14-actor@example.test',
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'F14_B', 'name' => 'F14 Company B']);
        $aRows = $this->base($actor, $a, 'A');
        $bRows = $this->base($actor, $b, 'B');

        // Avoid unrelated single-column UNIQUE violations masking the
        // composite foreign-key constraint under test. Reuse B's quote and
        // order only after releasing their one-to-one references.
        DB::table('invoices')->where('id', $bRows['invoice'])->delete();
        DB::table('orders')->where('id', $bRows['order'])->update(['quote_id' => null]);

        $cases = [
            ['quotes', 'customer_id', 'quote', 'customer'],
            ['orders', 'customer_id', 'order', 'customer'],
            ['orders', 'quote_id', 'order', 'quote'],
            ['invoices', 'order_id', 'invoice', 'order'],
            ['stock_movements', 'product_id', 'stock', 'product'],
            ['stock_movements', 'order_id', 'stock', 'order'],
            ['quote_items', 'product_id', 'quote_item', 'product'],
            ['quote_items', 'quote_id', 'quote_item', 'quote'],
            ['order_items', 'product_id', 'order_item', 'product'],
            ['order_items', 'order_id', 'order_item', 'order'],
            ['support_incident_events', 'support_incident_id', 'event', 'incident'],
            ['support_diagnostic_runs', 'support_incident_id', 'diagnostic', 'incident'],
        ];

        foreach ($cases as [$table, $column, $localKey, $foreignKey]) {
            $this->reject(fn () => DB::table($table)->where('id', $aRows[$localKey])
                ->update([$column => $bRows[$foreignKey]]));
        }

        // Spoofing company_id on an existing child must also fail.
        $this->reject(fn () => DB::table('quote_items')->where('id', $aRows['quote_item'])
            ->update(['company_id' => $b->id]));
        $this->reject(fn () => DB::table('support_incident_events')->where('id', $aRows['event'])
            ->update(['company_id' => $b->id]));

        foreach (['quotes', 'orders', 'invoices', 'stock_movements', 'quote_items', 'order_items',
            'support_incident_events', 'support_diagnostic_runs'] as $table) {
            $key = match ($table) {
                'quotes' => 'quote', 'orders' => 'order', 'invoices' => 'invoice',
                'stock_movements' => 'stock', 'quote_items' => 'quote_item',
                'order_items' => 'order_item', 'support_incident_events' => 'event',
                'support_diagnostic_runs' => 'diagnostic',
            };
            $this->assertDatabaseHas($table, ['id' => $aRows[$key], 'company_id' => $a->id]);
        }
    }

    public function test_normal_sales_flow_writes_tenant_ids_on_document_lines(): void
    {
        $this->pgOnly();

        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $admin = User::create([
            'name' => 'F14 admin', 'email' => 'f14-admin@example.test',
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $admin->companies()->attach($company->id, [
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $this->actingAs($admin);

        $customer = $this->postJson('/api/customers', [
            'name' => 'F14 customer', 'email' => 'f14-customer@example.test',
        ])->assertCreated()->json('id');
        $product = $this->postJson('/api/products', [
            'reference' => 'F14-PRODUCT', 'name' => 'F14 product',
            'category' => 'Tests', 'price_cents' => 1000,
            'vat_bps' => 2000, 'alert_threshold' => 1,
        ])->assertCreated()->json('id');
        $this->postJson('/api/products/'.$product.'/stock', [
            'quantity' => 5, 'reason' => 'Synthetic F14 stock',
        ])->assertOk();

        $quote = $this->postJson('/api/quotes', [
            'customer_id' => $customer,
            'items' => [['product_id' => $product, 'quantity' => 2]],
        ])->assertCreated()->json('id');
        $this->assertDatabaseHas('quote_items', [
            'quote_id' => $quote, 'product_id' => $product, 'company_id' => $company->id,
        ]);

        $order = $this->postJson('/api/quotes/'.$quote.'/convert')
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order, 'product_id' => $product, 'company_id' => $company->id,
        ]);
        $this->postJson('/api/orders/'.$order.'/validate')->assertOk();

        $invoice = $this->postJson('/api/invoices', ['order_id' => $order])
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice, 'order_id' => $order, 'company_id' => $company->id,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'order_id' => $order, 'product_id' => $product, 'company_id' => $company->id,
        ]);
    }
}
