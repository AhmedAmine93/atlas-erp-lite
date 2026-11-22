<?php
namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OperationsMenuTest extends TestCase
{
 use RefreshDatabase;
 private function member(Company $c,string $role,string $suffix):User
 {
  $u=User::create(['name'=>'Ops '.$suffix,'email'=>'m18-'.$suffix.'@example.test',
   'password'=>'DummyPassword2026!','role_id'=>Role::where('name','admin')->value('id')]);
  $u->companies()->attach($c->id,['role_id'=>Role::where('name',$role)->value('id')]);
  return $u;
 }
 private function asUser(User $u,Company $c):void
 {
  $this->actingAs($u)->withSession(['active_company_id'=>$c->id]);
 }
 public function test_six_screens_and_multi_tenant_access():void
 {
  $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
  $b=Company::create(['code'=>'M18_B','name'=>'M18 B']);
  $this->asUser($this->member($a,'reader','reader'),$a);
  foreach(['overview','health','logs','jobs','backups','deployments'] as $s)
   $this->getJson('/api/ops/'.$s)->assertForbidden();
  $this->asUser($this->member($a,'admin','admin-a'),$a);
  foreach(['health','logs','jobs','backups','deployments'] as $s)
   $this->getJson('/api/ops/'.$s)->assertOk()->assertJsonPath('total',0);
  $this->getJson('/api/ops/overview')->assertOk()
   ->assertJsonPath('scope','Entreprise active uniquement');
  $this->postJson('/api/ops/health',['service_key'=>'erp-api',
   'technical_status'=>'ok','business_status'=>'degraded','source'=>'manual',
   'observed_at'=>now()->subMinute()->toIso8601String()])->assertCreated();
  $this->getJson('/api/ops/overview')->assertJsonPath('counts.degraded_services',1);
  $this->asUser($this->member($b,'admin','admin-b'),$b);
  $this->getJson('/api/ops/health')->assertJsonPath('total',0);
  $this->getJson('/api/ops/overview')->assertJsonPath('counts.observed_services',0);
 }
 public function test_logs_reject_secrets_and_audit_success():void
 {
  $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
  $this->asUser($this->member($a,'admin','logs'),$a);
  $p=['component'=>'backend','level'=>'warning','event'=>'dependency-timeout',
   'correlation_id'=>'a0c11111-1111-4111-8111-111111111111',
   'observed_at'=>now()->subMinute()->toIso8601String()];
  $this->postJson('/api/ops/logs',[...$p,'message'=>'Bearer token: abc'])->assertUnprocessable();
  $this->postJson('/api/ops/logs',[...$p,'message'=>'Dependency timed out'])->assertCreated();
  $this->getJson('/api/ops/logs?status=warning')->assertJsonPath('total',1);
  $this->assertDatabaseHas('audit_logs',['company_id'=>$a->id,'action'=>'ops.logs_registered']);
 }
 public function test_jobs_idempotency_and_optimistic_status_changes():void
 {
  $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
  $this->asUser($this->member($a,'admin','jobs'),$a);
  $p=['job_type'=>'report','max_attempts'=>2,'idempotency_key'=>'daily-report-001'];
  $id=$this->postJson('/api/ops/jobs',$p)->assertCreated()->json('record.id');
  $this->postJson('/api/ops/jobs',$p)->assertOk()->assertJsonPath('already_exists',true);
  $this->postJson('/api/ops/jobs',[...$p,'max_attempts'=>3])->assertStatus(409);
  $this->assertDatabaseCount('ops_jobs',1);
  $this->postJson('/api/ops/jobs/'.$id.'/transition',['action'=>'complete','version'=>1])->assertStatus(409);
  $this->postJson('/api/ops/jobs/'.$id.'/transition',['action'=>'start','version'=>1])
   ->assertOk()->assertJsonPath('attempts',1);
  $this->postJson('/api/ops/jobs/'.$id.'/transition',['action'=>'start','version'=>1])->assertStatus(409);
  $this->postJson('/api/ops/jobs/'.$id.'/transition',['action'=>'fail','version'=>2])->assertOk();
  $this->postJson('/api/ops/jobs/'.$id.'/transition',['action'=>'retry','version'=>3])->assertOk();
  $this->postJson('/api/ops/jobs/'.$id.'/transition',['action'=>'start','version'=>4])
   ->assertOk()->assertJsonPath('attempts',2);
  $this->postJson('/api/ops/jobs/'.$id.'/transition',['action'=>'fail','version'=>5])->assertOk();
  $this->postJson('/api/ops/jobs/'.$id.'/transition',['action'=>'retry','version'=>6])->assertStatus(409);
 }
 public function test_release_requires_restoration_and_proof():void
 {
  $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
  $b=Company::create(['code'=>'M18_REL_B','name'=>'Release B']);
  $this->asUser($this->member($a,'admin','release-a'),$a);
  $backup=$this->postJson('/api/ops/backups',['backup_ref'=>'backup-20261122-001',
   'environment'=>'staging','checksum_sha256'=>str_repeat('a',64),'encrypted'=>true,
   'captured_at'=>now()->subHour()->toIso8601String()])->assertCreated()->json('id');
  $release=$this->postJson('/api/ops/deployments',['environment'=>'production',
   'image_digest'=>'sha256:'.str_repeat('b',64)])->assertCreated()->json('id');
  $decision=['action'=>'approve','sbom_verified'=>true,'provenance_verified'=>true,
   'staging_smoke_passed'=>true,'migration_compatible'=>true,'backup_id'=>$backup,
   'decision_ref'=>'PV-20261122-001'];
  $this->postJson('/api/ops/deployments/'.$release.'/transition',$decision)->assertStatus(422);
  $this->postJson('/api/ops/backups/'.$backup.'/transition',[
   'action'=>'attest_restore','restored_at'=>now()->subMinute()->toIso8601String(),
   'observed_rpo_seconds'=>60,'observed_rto_seconds'=>180,'proof_ref'=>'isolated-test-001'
  ])->assertOk()->assertJsonPath('isolated_restore_verified',true);
  $this->postJson('/api/ops/deployments/'.$release.'/transition',$decision)
   ->assertOk()->assertJsonPath('status','approved');
  $this->postJson('/api/ops/deployments/'.$release.'/transition',$decision)->assertStatus(409);
  $this->asUser($this->member($b,'admin','release-b'),$b);
  $this->getJson('/api/ops/deployments')->assertJsonPath('total',0);
  $this->postJson('/api/ops/backups/'.$backup.'/transition',[
   'action'=>'attest_restore','restored_at'=>now()->toIso8601String(),
   'observed_rpo_seconds'=>0,'observed_rto_seconds'=>0,'proof_ref'=>'cross-tenant-test'
  ])->assertNotFound();
 }
}
