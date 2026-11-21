<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $email, Company $company, string $role): User
    {
        $user = User::create([
            'name' => 'Search '.$role,
            'email' => $email,
            'password' => 'SearchPassword2026!',
            'role_id' => Role::where('name', 'reader')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);

        return $user;
    }

    public function test_search_requires_authentication_and_bounded_input(): void
    {
        $this->getJson('/api/search?q=Atlas')->assertUnauthorized();
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->actingAs($this->member('search-input@example.test', $company, 'admin'));
        $this->getJson('/api/search?q=a')->assertUnprocessable();
        $this->getJson('/api/search?q='.str_repeat('x', 101))->assertUnprocessable();
        $this->getJson('/api/search?q=zzzxxyy')->assertOk()->assertJsonPath('groups', []);
    }

    public function test_search_is_tenant_scoped_and_uses_membership_role(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'SEARCH_B', 'name' => 'Search B']);
        $adminA = $this->member('search-admin-a@example.test', $a, 'admin');
        $adminB = $this->member('search-admin-b@example.test', $b, 'admin');

        $this->actingAs($adminA)->withSession(['active_company_id' => $a->id]);
        $this->postJson('/api/customers', [
            'name' => 'UniqueBlueCustomer',
            'email' => 'blue-search@example.test',
        ])->assertCreated();

        $this->actingAs($adminB)->withSession(['active_company_id' => $b->id]);
        $this->postJson('/api/customers', [
            'name' => 'UniqueGreenCustomer',
            'email' => 'green-search@example.test',
        ])->assertCreated();
        $resultB = $this->getJson('/api/search?q=Unique')->assertOk()->json();
        $encodedB = json_encode($resultB);
        $this->assertStringContainsString('UniqueGreenCustomer', $encodedB);
        $this->assertStringNotContainsString('UniqueBlueCustomer', $encodedB);

        $this->actingAs($adminA)->withSession(['active_company_id' => $a->id]);
        $resultA = $this->getJson('/api/search?q=Unique')->assertOk()->json();
        $encodedA = json_encode($resultA);
        $this->assertStringContainsString('UniqueBlueCustomer', $encodedA);
        $this->assertStringNotContainsString('UniqueGreenCustomer', $encodedA);
    }

    public function test_stock_role_does_not_discover_crm_and_support_role_only_discovers_incidents(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $admin = $this->member('search-admin@example.test', $a, 'admin');
        $stock = $this->member('search-stock@example.test', $a, 'stock');
        $support = $this->member('search-n2@example.test', $a, 'support_n2');

        $this->actingAs($admin)->withSession(['active_company_id' => $a->id]);
        $this->postJson('/api/customers', [
            'name' => 'SharedSearchSecretCustomer',
            'email' => 'secret@example.test',
        ])->assertCreated();
        Product::create([
            'name' => 'SharedSearchProduct',
            'reference' => 'SHARED-SEARCH',
            'category' => 'CI',
            'price_cents' => 1000,
            'vat_bps' => 2000,
            'stock' => 2,
            'alert_threshold' => 1,
        ]);

        $this->actingAs($stock)->withSession(['active_company_id' => $a->id]);
        $stockResult = $this->getJson('/api/search?q=SharedSearch')->assertOk()->json();
        $this->assertSame(['products'], array_column($stockResult['groups'], 'type'));
        $this->assertStringNotContainsString('SecretCustomer', json_encode($stockResult));

        $this->actingAs($support)->withSession(['active_company_id' => $a->id]);
        $this->postJson('/api/support/incidents', [
            'title' => 'SharedSearchIncident',
            'description' => 'Synthetic cross-module search test.',
            'severity' => 'P3',
            'environment' => 'demo',
        ])->assertCreated();
        $supportResult = $this->getJson('/api/search?q=SharedSearch')->assertOk()->json();
        $this->assertSame(['support-incidents'], array_column($supportResult['groups'], 'type'));
        $this->assertStringContainsString('SharedSearchIncident', json_encode($supportResult));
        $this->assertStringNotContainsString('SharedSearchProduct', json_encode($supportResult));
    }
}
