<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryCountTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, int $n): User
    {
        $u = User::create([
            'name' => $role.$n, 'email' => "m05count-{$role}-{$n}@test.example",
            'password' => 'PasswordTest2026!',
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $u->companies()->attach($company->id, ['role_id' => Role::where('name', $role)->value('id')]);
        return $u;
    }

    public function test_inventory_is_blind_requires_independent_recount_and_adjusts_only_once(): void
    {
        $admin = $this->member('admin', 1);
        $counter = $this->member('stock', 1);
        $recount = $this->member('stock', 2);
        $this->actingAs($admin);
        $p = Product::create(['reference'=>'COUNT-01','name'=>'Comptage',
            'category'=>'Test','price_cents'=>100,'vat_bps'=>2000,
            'stock'=>7,'alert_threshold'=>1]);
        $wh = $this->postJson('/api/inventory/warehouses', [
            'code'=>'STOCK','name'=>'Entrepôt',
        ])->assertCreated()->json('id');
        $loc = $this->postJson('/api/inventory/locations', [
            'warehouse_id'=>$wh,'code'=>'L1','name'=>'Rayon',
        ])->assertCreated()->json('id');
        $this->postJson('/api/inventory/allocate', [
            'product_id'=>$p->id,'location_id'=>$loc,'quantity'=>7,
            'reason'=>'Allocation contrôlée','source_reference'=>'INIT',
            'operation_key'=>'00000000-0000-4000-8000-000000000051',
        ])->assertCreated();

        $this->actingAs($counter);
        $id = $this->postJson('/api/inventory/counts', [
            'reference'=>'INV-01','product_id'=>$p->id,'location_id'=>$loc,
        ])->assertCreated()->assertJsonMissingPath('snapshot_quantity')->json('id');

        $this->getJson('/api/inventory/counts')->assertOk()->assertJsonMissingPath('data.0.snapshot_quantity');
        $this->postJson('/api/inventory/counts', [
            'reference'=>'INV-02','product_id'=>$p->id,'location_id'=>$loc,
        ])->assertConflict();

        $this->postJson("/api/inventory/counts/{$id}/count", [
            'quantity'=>5,
        ])->assertOk()->assertJsonMissingPath('snapshot_quantity');
        $this->postJson("/api/inventory/counts/{$id}/approve", [
            'reason'=>'Tentative',
        ])->assertForbidden();
        $this->postJson("/api/inventory/counts/{$id}/count", [
            'quantity'=>6,
        ])->assertForbidden();

        $this->actingAs($recount);
        $this->postJson("/api/inventory/counts/{$id}/count", [
            'quantity'=>6, 'reason'=>'Recomptage contradictoire',
        ])->assertOk()->assertJsonMissingPath('first_count');

        $this->actingAs($admin);
        $this->postJson("/api/inventory/counts/{$id}/approve", [
            'reason'=>'Deux comptages divergents',
        ])->assertConflict();
        $this->assertDatabaseHas('products', ['id'=>$p->id,'stock'=>7]);
    }
}
