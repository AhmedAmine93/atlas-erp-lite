<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryWorkflowM05Test extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, Company $company, string $suffix): User
    {
        $user = User::create([
            'name'=> $role.' '.$suffix,
            'email'=>$role.$suffix.'@m05.test',
            'password'=>'PasswordTest2026!',
            'role_id'=>Role::where('name',$role)->value('id'),
        ]);
        $user->companies()->attach($company->id,['role_id'=>Role::where('name',$role)->value('id')]);
        return $user;
    }
    private function setupLocation(User $stock): array
    {
        $this->actingAs($stock);
        $product=Product::create([
            'reference'=>'M05-X','name'=>'Stock test','category'=>'Demo',
            'price_cents'=>1000,'vat_bps'=>2000,'stock'=>10,'alert_threshold'=>5,
        ]);
        $warehouse=$this->postJson('/api/inventory/warehouses',[
            'code'=>'W01','name'=>'Entrepôt test',
        ])->assertCreated()->json('id');
        $location=$this->postJson('/api/inventory/locations',[
            'warehouse_id'=>$warehouse,'code'=>'A01','name'=>'Emplacement A',
        ])->assertCreated()->json('id');
        return [$product,$warehouse,$location];
    }
    public function test_count_recount_and_independent_approval_adjusts_both_ledgers(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $stock=$this->member('stock',$a,'alice');
        $counter=$this->member('stock',$a,'bob');
        $admin=$this->member('admin',$a,'charlie');
        [$product,$warehouse,$location]=$this->setupLocation($stock);
        $this->postJson('/api/inventory/allocate',[
            'product_id'=>$product->id,'location_id'=>$location,'quantity'=>4,
            'source_reference'=>'INIT-M05','reason'=>'Allocation initiale vérifiée',
            'operation_key'=>'6c6bba64-8455-4826-a234-d44a4433c579',
        ])->assertCreated();
        $count=$this->postJson('/api/inventory/counts',[
            'reference'=>'COUNT-A-01','product_id'=>$product->id,'location_id'=>$location,
        ])->assertCreated()->assertJsonMissingPath('snapshot_quantity')->json('id');
        $this->postJson('/api/inventory/counts',[
            'reference'=>'COUNT-A-02','product_id'=>$product->id,'location_id'=>$location,
        ])->assertConflict();
        $this->postJson('/api/inventory/counts/'.$count.'/count',['quantity'=>2])->assertOk();
        $this->actingAs($admin)->postJson('/api/inventory/counts/'.$count.'/approve',[
            'reason'=>'Comptage non confirmé',
        ])->assertConflict();
        $this->actingAs($counter);
        $this->postJson('/api/inventory/counts/'.$count.'/count',[
            'quantity'=>2,'reason'=>'Recomptage contradictoire',
        ])->assertOk()->assertJsonPath('status','recounted');
        $this->actingAs($admin);
        $this->postJson('/api/inventory/counts/'.$count.'/approve',[
            'reason'=>'Écart confirmé par double comptage',
        ])->assertOk()->assertJsonPath('status','approved');
        $this->assertSame(8,(int)$product->fresh()->stock);
        $this->assertDatabaseHas('inventory_balances',[
            'company_id'=>$a->id,'product_id'=>$product->id,'location_id'=>$location,'quantity'=>2,
        ]);
        $this->assertDatabaseHas('inventory_ledger',['operation'=>'count_adjust','quantity'=>-2]);
        $this->assertDatabaseHas('stock_movements',['product_id'=>$product->id,'quantity'=>-2,'balance_after'=>8]);
        $this->postJson('/api/inventory/counts/'.$count.'/approve',[
            'reason'=>'Double approval',
        ])->assertConflict();
    }
    public function test_lot_and_serial_are_tenant_scoped_and_quality_decision_requires_admin(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $stock=$this->member('stock',$a,'stocklot');
        $admin=$this->member('admin',$a,'adminlot');
        [$product,$warehouse,$location]=$this->setupLocation($stock);
        $lot=$this->postJson('/api/inventory/lots',[
            'product_id'=>$product->id,'code'=>'LOT-01',
            'expires_on'=>'2030-12-31',
        ])->assertCreated()->assertJsonPath('status','quarantine')->json('id');
        $this->patchJson('/api/inventory/lots/'.$lot.'/decision',[
            'status'=>'available','reason'=>'Contrôle conforme',
        ])->assertForbidden();
        $this->actingAs($admin);
        $this->patchJson('/api/inventory/lots/'.$lot.'/decision',[
            'status'=>'available','reason'=>'Contrôle conforme',
        ])->assertOk()->assertJsonPath('status','available');
        $this->actingAs($stock);
        $this->postJson('/api/inventory/serials',[
            'product_id'=>$product->id,'lot_id'=>$lot,'location_id'=>$location,
            'serial_number'=>'SN-M05-001',
        ])->assertCreated();
        $this->postJson('/api/inventory/serials',[
            'product_id'=>$product->id,'lot_id'=>$lot,'location_id'=>$location,
            'serial_number'=>'SN-M05-001',
        ])->assertUnprocessable();
        $this->postJson('/api/inventory/lots',[
            'product_id'=>$product->id,'code'=>'LOT-02','status'=>'available',
        ])->assertUnprocessable();
    }
    public function test_replenishment_replay_mismatched_payload_and_four_eyes(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $stock=$this->member('stock',$a,'stocksupply');
        $admin=$this->member('admin',$a,'adminsupply');
        [$product]=$this->setupLocation($stock);
        $payload=[
            'product_id'=>$product->id,'safety_quantity'=>15,
            'reserved_quantity'=>2,'incoming_quantity'=>1,
            'operation_key'=>'dfd7bd56-3164-488e-b09c-fc5cd3e6794f',
        ];
        $created=$this->postJson('/api/inventory/replenishments',$payload)
            ->assertCreated()->assertJsonPath('suggested_quantity',6)->json('id');
        $this->postJson('/api/inventory/replenishments',$payload)
            ->assertOk()->assertJsonPath('id',$created);
        $this->postJson('/api/inventory/replenishments',array_merge($payload,['safety_quantity'=>16]))
            ->assertConflict();
        $this->postJson('/api/inventory/replenishments/'.$created.'/decision',[
            'decision'=>'approved',
        ])->assertForbidden();
        $this->actingAs($admin)->postJson('/api/inventory/replenishments/'.$created.'/decision',[
            'decision'=>'approved',
        ])->assertOk()->assertJsonPath('status','approved');
        $this->assertDatabaseCount('orders',0);
        $this->assertDatabaseCount('purchase_requests',0);
    }
    public function test_reader_cannot_write_and_other_tenant_cannot_reference_lots_or_products(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M05_OTHER','name'=>'Other']);
        $stock=$this->member('stock',$a,'tenantA');
        $other=$this->member('stock',$b,'tenantB');
        $reader=$this->member('reader',$a,'observer');
        [$product,$warehouse,$location]=$this->setupLocation($stock);
        $this->actingAs($reader)->getJson('/api/inventory/counts')->assertOk();
        $this->postJson('/api/inventory/counts',[])->assertForbidden();
        $this->actingAs($other)->withSession(['active_company_id'=>$b->id]);
        $this->postJson('/api/inventory/lots',[
            'product_id'=>$product->id,'code'=>'CROSS-TENANT',
        ])->assertNotFound();
        $this->postJson('/api/inventory/counts',[
            'reference'=>'BAD','product_id'=>$product->id,'location_id'=>$location,
        ])->assertNotFound();
    }
}
