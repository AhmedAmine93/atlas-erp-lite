<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $c, string $suffix): void
    {
        $role = Role::where('name','admin')->value('id');
        $user = User::create(['name'=>'Maintenance',
            'email'=>'work-order-'.$suffix.'@example.test',
            'password'=>'TestPassword2026!', 'role_id'=>$role]);
        $user->companies()->attach($c->id, ['role_id'=>$role]);
        $this->actingAs($user)->withSession(['active_company_id'=>$c->id]);
    }

    public function test_qualified_DI_creates_one_work_order_and_closure_requires_resolution(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'MAINT_WO_B','name'=>'B']);
        $this->admin($a,'a');
        $site=$this->postJson('/api/maintenance/sites', [
            'code'=>'WO-SITE','name'=>'Fictive site',
        ])->assertCreated()->json('id');
        $asset=$this->postJson('/api/maintenance/assets', [
            'site_id'=>$site,'asset_code'=>'WO-PUMP','name'=>'Synthetic pump',
        ])->assertCreated()->json('id');
        $di=$this->postJson('/api/maintenance/requests', [
            'asset_id'=>$asset,'reference'=>'DI-WO-01','title'=>'Pressure issue',
            'priority'=>'high',
        ])->assertCreated()->json('id');
        $this->postJson("/api/maintenance/requests/$di/work-orders")->assertStatus(409);
        $this->postJson("/api/maintenance/requests/$di/qualify")->assertOk();
        $wo=$this->postJson("/api/maintenance/requests/$di/work-orders")
            ->assertCreated()->assertJsonPath('status','open')->json('id');
        $this->postJson("/api/maintenance/requests/$di/work-orders")
            ->assertOk()->assertJsonPath('id',$wo);
        $this->postJson("/api/maintenance/work-orders/$wo/complete", [
            'resolution'=>'Premature closure',
        ])->assertStatus(409);
        $this->postJson("/api/maintenance/work-orders/$wo/start")
            ->assertOk()->assertJsonPath('status','in_progress');
        $this->postJson("/api/maintenance/work-orders/$wo/start")->assertStatus(409);
        $this->postJson("/api/maintenance/work-orders/$wo/complete", [
            'resolution'=>'Fixed',
        ])->assertUnprocessable();
        $this->postJson("/api/maintenance/work-orders/$wo/complete", [
            'resolution'=>'Pump inspected and pressure seal replaced.',
        ])->assertOk()->assertJsonPath('status','completed');
        $this->postJson("/api/maintenance/work-orders/$wo/complete", [
            'resolution'=>'Redundant',
        ])->assertStatus(422); // invalid minimum length before state transition.
        $this->assertDatabaseCount('maintenance_work_orders',1);
        $this->admin($b,'b');
        $this->postJson("/api/maintenance/requests/$di/work-orders")->assertNotFound();
        $this->postJson("/api/maintenance/work-orders/$wo/start")->assertNotFound();
        $this->getJson('/api/maintenance/work-orders')->assertOk()->assertJsonPath('total',0);
    }
}
