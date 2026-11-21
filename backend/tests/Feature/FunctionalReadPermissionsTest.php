<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P066.04 / P019.05 — the role-specific UI must not be the security boundary.
 */
class FunctionalReadPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, string $suffix): User
    {
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $user = User::create([
            'name' => 'Permission '.$role,
            'email' => "permission-{$role}-{$suffix}@example.test",
            'password' => 'FunctionalTest2026!',
            // Intentionally use a global admin role to catch legacy-role bypass.
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        return $user;
    }

    public function test_stock_cannot_fetch_crm_sales_invoices_or_pdf(): void
    {
        $this->member('stock', 'a');
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/stock-movements')->assertOk();
        $this->getJson('/api/customers')->assertForbidden();
        $this->getJson('/api/suppliers')->assertForbidden();
        $this->getJson('/api/quotes')->assertForbidden();
        $this->getJson('/api/orders')->assertForbidden();
        $this->getJson('/api/invoices')->assertForbidden();
        $this->get('/api/invoices/1/pdf')->assertForbidden();

        $dashboard = $this->getJson('/api/dashboard')->assertOk()->json();
        $this->assertSame(0, $dashboard['revenue_cents']);
        $this->assertSame(0, $dashboard['outstanding_cents']);
        $this->assertSame(0, $dashboard['orders_count']);
        $this->assertSame([], $dashboard['top_products']);
        $this->assertSame([], $dashboard['recent_orders']);
        $this->assertSame([], $dashboard['daily_revenue']);
    }

    public function test_support_role_cannot_fetch_erp_documents_even_with_global_admin_role(): void
    {
        $this->member('support_n2', 'a');
        $this->getJson('/api/support/incidents')->assertOk();
        foreach (['dashboard', 'products', 'stock-movements', 'customers', 'suppliers', 'quotes', 'orders', 'invoices'] as $path) {
            $this->getJson('/api/'.$path)->assertForbidden();
        }
        $this->get('/api/invoices/1/pdf')->assertForbidden();
    }

    public function test_commercial_and_reader_have_expected_read_only_scope(): void
    {
        $this->member('commercial', 'a');
        foreach (['dashboard', 'products', 'customers', 'suppliers', 'quotes', 'orders', 'invoices'] as $path) {
            $this->getJson('/api/'.$path)->assertOk();
        }
        $this->getJson('/api/stock-movements')->assertForbidden();
        $this->getJson('/api/support/incidents')->assertForbidden();

        $this->member('reader', 'b');
        foreach (['dashboard', 'products', 'stock-movements', 'customers', 'suppliers', 'quotes', 'orders', 'invoices'] as $path) {
            $this->getJson('/api/'.$path)->assertOk();
        }
        $this->postJson('/api/customers', ['name' => 'Forbidden', 'email' => 'forbidden@example.test'])->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/support/incidents')->assertForbidden();
    }
}
