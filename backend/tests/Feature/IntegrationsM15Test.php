<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IntegrationsM15Test extends TestCase
{
    use RefreshDatabase;

    private function member(Company $company, string $role, string $suffix): void
    {
        $global=Role::where('name','admin')->value('id');
        $user=User::create([
            'name'=>'M15 '.$suffix, 'email'=>'m15-'.$suffix.'@example.test',
            'password'=>'M15Password2026!', 'role_id'=>$global,
        ]);
        $user->companies()->attach($company->id,[
            'role_id'=>Role::where('name',$role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id'=>$company->id]);
    }

    public function test_six_routes_are_authenticated_and_tenant_admin_only(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M15_B','name'=>'Company B']);
        $this->getJson('/api/integrations/v1/connectors')->assertUnauthorized();
        $this->member($a,'reader','reader');
        foreach (['connectors','api','imports','jobs','errors','iot'] as $area) {
            $this->getJson('/api/integrations/v1/'.$area)->assertForbidden();
        }
        $this->member($a,'admin','a');
        $this->postJson('/api/integrations/v1/connectors',[
            'code'=>'DEMO','name'=>'Sandbox','provider'=>'mock',
            'secret'=>str_repeat('s',32),
        ])->assertCreated()->assertJsonMissing(['secret_ciphertext']);
        $this->getJson('/api/integrations/v1/connectors')->assertOk()
            ->assertJsonPath('total',1);
        $this->member($b,'admin','b');
        $this->getJson('/api/integrations/v1/connectors')->assertOk()
            ->assertJsonPath('total',0);
        $this->getJson('/api/integrations/v1/api')->assertOk()->assertJsonPath('total',0);
    }

    public function test_connector_sandbox_activation_signature_and_webhook_deduplication(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->member($a,'admin','signature');
        $secret=str_repeat('h',40);
        $id=$this->postJson('/api/integrations/v1/connectors',[
            'code'=>'HMAC','name'=>'HMAC mock','provider'=>'mock','secret'=>$secret,
        ])->assertCreated()->assertJsonPath('status','draft')->json('id');
        $this->postJson("/api/integrations/v1/connectors/$id/activate",[
            'expected_version'=>0,
        ])->assertConflict();
        $this->postJson("/api/integrations/v1/connectors/$id/sandbox",[
            'expected_version'=>0,
        ])->assertOk();
        $this->postJson("/api/integrations/v1/connectors/$id/activate",[
            'expected_version'=>0,
        ])->assertOk()->assertJsonPath('status','active');
        $event=['event_id'=>'EVENT-1','event_type'=>'sandbox.demo','payload'=>['amount'=>42]];
        $body=json_encode($event);
        $time=(string)time();
        $url="/api/integrations/v1/webhooks/$id/receive";
        $signed=[
            'X-Atlas-Webhook-Timestamp'=>$time,
            'X-Atlas-Webhook-Signature'=>hash_hmac('sha256',$time.'.'.$body,$secret),
        ];
        $this->withHeaders($signed)->call('POST',$url,[],[],[],[
            'CONTENT_TYPE'=>'application/json',
        ],$body)->assertStatus(202);
        $this->withHeaders($signed)->call('POST',$url,[],[],[],[
            'CONTENT_TYPE'=>'application/json',
        ],$body)->assertStatus(202);
        $this->assertDatabaseCount('integration_webhook_receipts',1);
        $this->assertDatabaseCount('integration_outbox',1);
        $this->withHeaders(['X-Atlas-Webhook-Signature'=>str_repeat('a',64),
            'X-Atlas-Webhook-Timestamp'=>$time])->call('POST',$url,[],[],[],[
            'CONTENT_TYPE'=>'application/json',
        ],$body)->assertUnauthorized();
        $this->assertDatabaseCount('integration_webhook_receipts',1);
    }

    public function test_exchange_preview_reject_lines_and_replay(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->member($a,'admin','exchanges');
        $body=[
            'kind'=>'import','mapping_version'=>'v1','commit'=>false,
            'rows'=>[
                ['external_id'=>'ONE','value'=>'A'],
                ['external_id'=>'ONE','value'=>'B'],
                ['external_id'=>'TWO','value'=>'C'],
            ],
        ];
        $this->postJson('/api/integrations/v1/exchange',$body)->assertOk()
            ->assertJsonPath('accepted_rows',2)
            ->assertJsonPath('rejected_rows',1);
        $this->assertDatabaseCount('integration_exchange_batches',0);
        $body['commit']=true;
        $this->postJson('/api/integrations/v1/exchange',$body)->assertCreated();
        $this->postJson('/api/integrations/v1/exchange',$body)->assertCreated();
        $this->assertDatabaseCount('integration_exchange_batches',1);
    }

    public function test_job_window_is_idempotent_and_suspension_blocks_execution(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->member($a,'admin','jobs');
        $id=$this->postJson('/api/integrations/v1/jobs',[
            'code'=>'HEALTH','name'=>'Snapshot','task'=>'health_snapshot',
            'cadence'=>'daily','timezone'=>'Europe/Paris',
        ])->assertCreated()->json('id');
        $this->postJson("/api/integrations/v1/jobs/$id/run",[])->assertConflict();
        $this->postJson("/api/integrations/v1/jobs/$id/resume",[])->assertOk();
        $this->postJson("/api/integrations/v1/jobs/$id/run",[])->assertOk();
        $this->postJson("/api/integrations/v1/jobs/$id/run",[])->assertOk();
        $this->assertDatabaseCount('integration_job_runs',1);
        $this->postJson("/api/integrations/v1/jobs/$id/suspend",[])->assertOk();
        $this->postJson("/api/integrations/v1/jobs/$id/run",[])->assertConflict();
    }

    public function test_devices_reject_cross_tenant_and_deduplicate_measurements(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'M15_IOT_B','name'=>'IoT B']);
        $this->member($a,'admin','devicea');
        $site=$this->postJson('/api/maintenance/sites',[
            'code'=>'SITE_M15','name'=>'M15 site',
        ])->assertCreated()->json('id');
        $secret=str_repeat('z',40);
        $id=$this->postJson('/api/integrations/v1/iot',[
            'site_id'=>$site,'code'=>'TEMP','name'=>'Temperature',
            'unit'=>'C','lower_bound'=>10,'upper_bound'=>30,'secret'=>$secret,
        ])->assertCreated()->assertJsonMissing(['secret_ciphertext'])->json('id');
        $data=['secret'=>$secret,'event_id'=>'TELEM-1','value'=>40,
            'observed_at'=>'2026-11-22T10:00:00Z'];
        $this->postJson("/api/integrations/v1/devices/$id/telemetry",$data)
            ->assertStatus(202)->assertJsonPath('quality','needs_review');
        $this->postJson("/api/integrations/v1/devices/$id/telemetry",$data)->assertStatus(202);
        $this->assertDatabaseCount('integration_telemetry',1);
        $this->postJson("/api/integrations/v1/devices/$id/telemetry",
            array_merge($data,['value'=>25]))->assertConflict();
        $this->member($b,'admin','deviceb');
        $this->postJson("/api/integrations/v1/devices/$id/telemetry",$data)->assertNotFound();
    }
}
