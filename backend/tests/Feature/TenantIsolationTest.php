<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email, string $role, Company $company): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $user->companies()->attach($company->id, ['role_id' => Role::where('name', $role)->value('id')]);

        return $user;
    }

    public function test_tenant_isolation_for_crm_stock_orders_and_dashboard(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'SECOND', 'name' => 'Second']);
        $ua = $this->makeUser('sales-a@example.test', 'commercial', $a);
        $ub = $this->makeUser('sales-b@example.test', 'commercial', $b);

        $this->actingAs($ua);
        $customerA = $this->postJson('/api/customers', ['name' => 'Customer A', 'email' => 'same@example.test'])->assertCreated()->json('id');
        $this->assertDatabaseHas('customers', ['id' => $customerA, 'company_id' => $a->id]);
        $productA = Product::create([
            'reference' => 'SAME-REF', 'name' => 'Product A', 'category' => 'Test',
            'price_cents' => 1000, 'vat_bps' => 2000, 'stock' => 10,
        ]);
        $orderA = $this->postJson('/api/orders', [
            'customer_id' => $customerA,
            'items' => [['product_id' => $productA->id, 'quantity' => 1]],
        ])->assertCreated()->json('id');

        $this->actingAs($ub);
        $this->withSession(['active_company_id' => $b->id]);
        $this->getJson('/api/customers')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/products')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/orders/'.$orderA)->assertNotFound();
        $this->getJson('/api/customers/'.$customerA)->assertNotFound();
        $this->putJson('/api/customers/'.$customerA, [
            'name' => 'Attack', 'email' => 'same@example.test',
        ])->assertNotFound();
        $this->postJson('/api/orders', [
            'customer_id' => $customerA,
            'items' => [['product_id' => $productA->id, 'quantity' => 1]],
        ])->assertNotFound();

        // Same natural keys are legal in separate tenants.
        $customerB = $this->postJson('/api/customers', [
            'name' => 'Customer B', 'email' => 'same@example.test',
        ])->assertCreated()->json('id');
        $productB = Product::create([
            'reference' => 'SAME-REF', 'name' => 'Product B', 'category' => 'Test',
            'price_cents' => 3000, 'vat_bps' => 2000, 'stock' => 3,
        ]);
        $this->assertSame($b->id, Customer::findOrFail($customerB)->company_id);
        $this->assertSame($b->id, $productB->company_id);
        $this->postJson('/api/orders', [
            'customer_id' => $customerB,
            'items' => [['product_id' => $productA->id, 'quantity' => 1]],
        ])->assertNotFound();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('orders_count', 0);
        $this->assertDatabaseHas('orders', ['id' => $orderA, 'company_id' => $a->id]);
        $this->assertSame(0, Order::where('id', $orderA)->count());
    }

    public function test_tenant_switch_requires_membership_and_changes_active_scope(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'SECOND', 'name' => 'Second']);
        $forbidden = Company::create(['code' => 'PRIVATE', 'name' => 'Private']);
        $user = $this->makeUser('multi@example.test', 'admin', $a);
        $user->companies()->attach($b->id, ['role_id' => Role::where('name', 'admin')->value('id')]);
        $this->actingAs($user);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('active_company_id', $a->id);
        $this->postJson('/api/tenant/switch', ['company_id' => $forbidden->id])->assertUnprocessable();
        $this->postJson('/api/tenant/switch', ['company_id' => $b->id])->assertOk()
            ->assertJsonPath('active_company_id', $b->id);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('active_company_id', $b->id);
        $this->postJson('/api/customers', [
            'name' => 'B Customer', 'email' => 'b@example.test', 'company_id' => $a->id,
        ])->assertCreated();
        $this->assertDatabaseHas('customers', ['name' => 'B Customer', 'company_id' => $b->id]);
    }

    public function test_missing_company_access_is_denied(): void
    {
        $user = User::create([
            'name' => 'No tenant', 'email' => 'none@example.test',
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);
        $this->actingAs($user);
        $this->getJson('/api/customers')->assertForbidden();
    }
}
