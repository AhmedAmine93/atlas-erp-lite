<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryLocationTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, Company $company): User
    {
        $user = User::create([
            'name' => $role.' warehouse test',
            'email' => uniqid($role.'-', true).'@example.test',
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $user->companies()->attach($company->id, ['role_id' => Role::where('name', $role)->value('id')]);
        return $user;
    }

    public function test_registry_write_read_audit_and_no_client_company_override(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->actingAs($this->member('stock', $a));

        $warehouse = $this->postJson('/api/inventory/warehouses', [
            'code' => 'PARIS', 'name' => 'Entrepôt Paris',
        ])->assertCreated()->assertJsonPath('company_id', $a->id)->json('id');
        $this->postJson('/api/inventory/warehouses', [
            'code' => 'PARIS', 'name' => 'Doublon',
        ])->assertUnprocessable();
        $this->postJson('/api/inventory/warehouses', [
            'code' => 'LYON', 'name' => 'Malicious', 'company_id' => 9999,
        ])->assertUnprocessable();

        $location = $this->postJson('/api/inventory/locations', [
            'warehouse_id' => $warehouse, 'code' => 'A01',
            'name' => 'Rayon A', 'condition_code' => 'quarantine',
        ])->assertCreated()->assertJsonPath('condition_code', 'quarantine')->json('id');
        $this->getJson('/api/inventory/locations?warehouse_id='.$warehouse)
            ->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/inventory/locations', [
            'warehouse_id' => $warehouse, 'code' => 'A01', 'name' => 'Doublon',
        ])->assertStatus(409);
        $this->patchJson('/api/inventory/warehouses/'.$warehouse, ['active' => false])->assertConflict();
        $this->patchJson('/api/inventory/locations/'.$location, ['active' => false])->assertOk();
        $this->patchJson('/api/inventory/warehouses/'.$warehouse, ['active' => false])->assertOk();
        $this->postJson('/api/inventory/locations', [
            'warehouse_id' => $warehouse, 'code' => 'B01', 'name' => 'Interdit',
        ])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.location.created']);
    }

    public function test_roles_and_cross_tenant_guards(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'INV_TEST_B', 'name' => 'Société B']);
        $this->actingAs($this->member('admin', $a));
        $warehouse = $this->postJson('/api/inventory/warehouses', [
            'code' => 'STOCK_A', 'name' => 'Dépôt A',
        ])->assertCreated()->json('id');

        $this->actingAs($this->member('reader', $a));
        $this->getJson('/api/inventory/warehouses')->assertOk()->assertJsonPath('total', 1);
        $this->postJson('/api/inventory/warehouses', [])->assertForbidden();

        $this->actingAs($this->member('commercial', $a));
        $this->getJson('/api/inventory/warehouses')->assertForbidden();

        $this->actingAs($this->member('stock', $b))->withSession(['active_company_id' => $b->id]);
        $this->getJson('/api/inventory/warehouses')->assertOk()->assertJsonPath('total', 0);
        $this->patchJson('/api/inventory/warehouses/'.$warehouse, ['name' => 'Infiltré'])->assertNotFound();
        $this->postJson('/api/inventory/locations', [
            'warehouse_id' => $warehouse, 'code' => 'X', 'name' => 'Intrusion',
        ])->assertNotFound();
        $this->postJson('/api/inventory/warehouses', [
            'code' => 'STOCK_B', 'name' => 'Dépôt B',
        ])->assertCreated();
        $this->assertDatabaseHas('inventory_warehouses', ['id' => $warehouse, 'company_id' => $a->id, 'name' => 'Dépôt A']);
    }

    public function test_regulated_location_requires_admin(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $this->actingAs($this->member('admin', $a));
        $warehouse = $this->postJson('/api/inventory/warehouses', [
            'code' => 'SAFE', 'name' => 'Dépôt réglementé',
        ])->assertCreated()->json('id');
        $this->actingAs($this->member('stock', $a));
        $this->postJson('/api/inventory/locations', [
            'warehouse_id' => $warehouse, 'code' => 'HAZ', 'name' => 'Zone réglementée',
            'condition_code' => 'regulated',
        ])->assertForbidden();
        $this->assertDatabaseMissing('inventory_locations', ['code' => 'HAZ']);
    }
}
