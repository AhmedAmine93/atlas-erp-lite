<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $c,string $suffix): void
    {
        $role=Role::where('name','admin')->value('id');
        $user=User::create(['name'=>'Maintenance','email'=>'maintenance-'.$suffix.'@example.test',
            'password'=>'TestPassword2026!','role_id'=>$role]);
        $user->companies()->attach($c->id,['role_id'=>$role]);
        $this->actingAs($user)->withSession(['active_company_id'=>$c->id]);
    }
    public function test_DI_is_scoped_and_qualification_is_single_use(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M_REQ_B','name'=>'Other plant']);
        $this->admin($a,'a');
        $site=$this->postJson('/api/maintenance/sites',['code'=>'S-1','name'=>'Site'])->assertCreated()->json('id');
        $asset=$this->postJson('/api/maintenance/assets',[
            'site_id'=>$site,'asset_code'=>'P-1','name'=>'Pump',
        ])->assertCreated()->json('id');
        $id=$this->postJson('/api/maintenance/requests',[
            'asset_id'=>$asset,'reference'=>'DI-001','title'=>'Valve leak','priority'=>'high',
        ])->assertCreated()->assertJsonPath('status','new')->json('id');
        $this->postJson("/api/maintenance/requests/$id/qualify")->assertOk()->assertJsonPath('status','qualified');
        $this->postJson("/api/maintenance/requests/$id/qualify")->assertStatus(409);
        $this->postJson('/api/maintenance/requests',[
            'asset_id'=>$asset,'reference'=>'DI-002','title'=>'Bad','priority'=>'invalid',
        ])->assertUnprocessable();

        $this->admin($b,'b');
        $this->postJson('/api/maintenance/requests',[
            'asset_id'=>$asset,'reference'=>'DI-EXT','title'=>'Foreign asset','priority'=>'normal',
        ])->assertNotFound();
        $this->postJson("/api/maintenance/requests/$id/qualify")->assertNotFound();
        $this->getJson('/api/maintenance/requests')->assertOk()->assertJsonPath('total',0);
    }
}
