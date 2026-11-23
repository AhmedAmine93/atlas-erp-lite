<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTransferTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $user = User::create(['name' => $role, 'email' => uniqid($role). '@test.example',
            'password' => 'PasswordTest2026!', 'role_id' => Role::where('name', $role)->value('id')]);
        $company = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $user->companies()->attach($company->id, ['role_id' => Role::where('name', $role)->value('id')]);
        return $user;
    }
    public function test_ten_units_transfer_four_receive_three_loss_one_and_replay(): void
    {
        $this->actingAs($this->user('admin'));
        $p = Product::create(['reference' => 'M05-TST', 'name' => 'Produit transfert',
            'category' => 'Test', 'price_cents' => 500, 'vat_bps' => 2000, 'stock' => 10,
            'alert_threshold' => 2]);
        $a = $this->postJson('/api/inventory/warehouses', ['code' => 'A', 'name' => 'Entrepôt A'])
            ->assertCreated()->json('id');
        $b = $this->postJson('/api/inventory/warehouses', ['code' => 'B', 'name' => 'Entrepôt B'])
            ->assertCreated()->json('id');
        $source = $this->postJson('/api/inventory/locations', ['warehouse_id' => $a,
            'code' => 'A1', 'name' => 'Zone A'])->assertCreated()->json('id');
        $target = $this->postJson('/api/inventory/locations', ['warehouse_id' => $b,
            'code' => 'B1', 'name' => 'Zone B'])->assertCreated()->json('id');

        $this->postJson('/api/inventory/allocate', [
            'product_id' => $p->id, 'location_id' => $source, 'quantity' => 10,
            'reason' => 'Allocation explicite du stock initial', 'source_reference' => 'INIT-A',
            'operation_key' => '00000000-0000-4000-8000-000000000001',
        ])->assertCreated()->assertJsonPath('balance_after', 10);

        $id = $this->postJson('/api/inventory/transfers', [
            'reference' => 'M05-TRANS-1', 'product_id' => $p->id,
            'source_location_id' => $source, 'target_location_id' => $target,
            'quantity_requested' => 4,
        ])->assertCreated()->json('id');
        $this->postJson("/api/inventory/transfers/$id/ship", [
            'operation_key' => '00000000-0000-4000-8000-000000000002',
        ])->assertOk()->assertJsonPath('quantity_shipped', 4)->assertJsonPath('status', 'in_transit');
        $this->postJson("/api/inventory/transfers/$id/ship", [
            'operation_key' => '00000000-0000-4000-8000-000000000002',
        ])->assertOk();

        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $p->id, 'location_id' => $source, 'quantity' => 6,
        ]);
        $this->assertDatabaseHas('products', ['id' => $p->id, 'stock' => 10]);

        $this->postJson("/api/inventory/transfers/$id/receive", [
            'reference' => 'REC-001', 'quantity' => 3,
            'operation_key' => '00000000-0000-4000-8000-000000000003',
        ])->assertOk()->assertJsonPath('quantity_received', 3)->assertJsonPath('status', 'in_transit');
        $this->postJson("/api/inventory/transfers/$id/receive", [
            'reference' => 'REC-001', 'quantity' => 3,
            'operation_key' => '00000000-0000-4000-8000-000000000003',
        ])->assertOk();
        $this->postJson("/api/inventory/transfers/$id/receive", [
            'reference' => 'REC-002', 'quantity' => 2,
            'operation_key' => '00000000-0000-4000-8000-000000000004',
        ])->assertConflict();
        $this->postJson("/api/inventory/transfers/$id/loss", [
            'quantity' => 1, 'reason' => 'Casse confirmée lors du transport',
            'operation_key' => '00000000-0000-4000-8000-000000000005',
        ])->assertOk()->assertJsonPath('quantity_lost', 1)->assertJsonPath('status', 'received');

        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $p->id, 'location_id' => $source, 'quantity' => 6,
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'product_id' => $p->id, 'location_id' => $target, 'quantity' => 3,
        ]);
        $this->assertDatabaseHas('products', ['id' => $p->id, 'stock' => 9]);
        $this->assertDatabaseCount('inventory_transfer_receipts', 1);
        $this->assertDatabaseCount('inventory_ledger', 3);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.transfer.loss_approved']);
    }

    public function test_unallocated_stock_is_not_simulated_and_quarantine_is_unavailable(): void
    {
        $this->actingAs($this->user('stock'));
        $p = Product::create(['reference' => 'M05-TEST2', 'name' => 'Quarantaine',
            'category' => 'Test', 'price_cents' => 20, 'vat_bps' => 2000, 'stock' => 4,
            'alert_threshold' => 1]);
        $a = $this->postJson('/api/inventory/warehouses', ['code' => 'Q',
            'name' => 'Dépôt Q'])->assertCreated()->json('id');
        $loc = $this->postJson('/api/inventory/locations', ['warehouse_id' => $a,
            'code' => 'LOCK', 'name' => 'Quarantaine', 'condition_code' => 'quarantine'])
            ->assertCreated()->json('id');
        $this->postJson('/api/inventory/allocate', [
            'product_id' => $p->id, 'location_id' => $loc, 'quantity' => 4,
            'reason' => 'Mise en quarantaine', 'source_reference' => 'Q-001',
            'operation_key' => '00000000-0000-4000-8000-000000000006',
        ])->assertCreated();
        $this->postJson('/api/products/'.$p->id.'/stock', [
            'quantity' => -1, 'reason' => 'Sortie refusée',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $p->id, 'stock' => 4]);
    }

    public function test_damaged_receipt_remains_physical_but_unsellable(): void
    {
        $this->actingAs($this->user('admin'));
        $p=Product::create([
            'reference'=>'M05-DAMAGE','name'=>'Cartons test','category'=>'Demo',
            'price_cents'=>1200,'vat_bps'=>2000,'stock'=>10,'alert_threshold'=>2,
        ]);
        $a=$this->postJson('/api/inventory/warehouses',['code'=>'DA','name'=>'Source'])
            ->assertCreated()->json('id');
        $b=$this->postJson('/api/inventory/warehouses',['code'=>'DB','name'=>'Destination'])
            ->assertCreated()->json('id');
        $source=$this->postJson('/api/inventory/locations',[
            'warehouse_id'=>$a,'code'=>'A','name'=>'Rayon source',
        ])->assertCreated()->json('id');
        $target=$this->postJson('/api/inventory/locations',[
            'warehouse_id'=>$b,'code'=>'B','name'=>'Rayon cible',
        ])->assertCreated()->json('id');
        $quarantine=$this->postJson('/api/inventory/locations',[
            'warehouse_id'=>$b,'code'=>'Q','name'=>'Quarantaine',
            'condition_code'=>'quarantine',
        ])->assertCreated()->json('id');
        $this->postJson('/api/inventory/allocate',[
            'product_id'=>$p->id,'location_id'=>$source,'quantity'=>10,
            'reason'=>'Stock initial vérifié','source_reference'=>'INITIAL',
            'operation_key'=>'00000000-0000-4000-8000-000000000021',
        ])->assertCreated();
        $id=$this->postJson('/api/inventory/transfers',[
            'reference'=>'T-DAMAGE-001','product_id'=>$p->id,
            'source_location_id'=>$source,'target_location_id'=>$target,'quantity_requested'=>4,
        ])->assertCreated()->json('id');
        $this->postJson("/api/inventory/transfers/$id/ship",[
            'operation_key'=>'00000000-0000-4000-8000-000000000022',
        ])->assertOk()->assertJsonPath('status','in_transit');
        $this->postJson("/api/inventory/transfers/$id/receive",[
            'reference'=>'OK-001','quantity'=>3,
            'operation_key'=>'00000000-0000-4000-8000-000000000023',
        ])->assertOk()->assertJsonPath('quantity_received',3);
        $payload=[
            'reference'=>'DMG-001','quantity'=>1,'damage_location_id'=>$quarantine,
            'reason'=>'Carton endommagé et isolé',
            'operation_key'=>'00000000-0000-4000-8000-000000000024',
        ];
        $this->postJson("/api/inventory/transfers/$id/damaged-receipt",$payload)
            ->assertOk()->assertJsonPath('quantity_received',4)->assertJsonPath('status','received');
        $this->postJson("/api/inventory/transfers/$id/damaged-receipt",$payload)
            ->assertOk()->assertJsonPath('quantity_received',4);
        $this->assertDatabaseHas('inventory_balances',[
            'product_id'=>$p->id,'location_id'=>$source,'quantity'=>6,
        ]);
        $this->assertDatabaseHas('inventory_balances',[
            'product_id'=>$p->id,'location_id'=>$target,'quantity'=>3,
        ]);
        $this->assertDatabaseHas('inventory_balances',[
            'product_id'=>$p->id,'location_id'=>$quarantine,'quantity'=>1,
        ]);
        $this->assertDatabaseHas('products',['id'=>$p->id,'stock'=>10]);
        $this->assertDatabaseHas('inventory_transfer_receipts',[
            'reference'=>'DMG-001','damaged'=>true,'damage_location_id'=>$quarantine,
        ]);
        $this->postJson('/api/products/'.$p->id.'/stock',[
            'quantity'=>-10,'reason'=>'Sortie qui ignore quarantaine',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('products',['id'=>$p->id,'stock'=>10]);
    }

}
