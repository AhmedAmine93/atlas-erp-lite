<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M12 targeted acceptance. SQLite checks HTTP rules; PostgreSQL CI must also
 * validate composite FKs, RLS, privilege grants and evidence triggers.
 */
class QualityMenuTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(Company $company, string $role, string $suffix): User
    {
        $roleId = Role::where('name', $role)->value('id');
        $this->assertNotNull($roleId);
        $user = User::create([
            'name' => 'Quality Test '.$suffix,
            'email' => 'quality-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!', 'role_id' => $roleId,
        ]);
        $user->companies()->attach($company->id, ['role_id' => $roleId]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        return $user;
    }
    private function company(): Company
    {
        return Company::where('code', 'ATLAS_DEMO')->firstOrFail();
    }
    private function plan(): int
    {
        return $this->postJson('/api/quality/controls', [
            'code' => 'TEMP-SAMPLE', 'revision' => 1, 'title' => 'Temperature lot',
            'metric' => 'celsius', 'minimum' => 2, 'maximum' => 8,
            'sample_size' => 10, 'valid_from' => '2026-01-01',
        ])->assertCreated()->json('id');
    }
    public function test_failing_check_creates_one_nonconformity_and_one_capa_with_replay(): void
    {
        $this->signIn($this->company(), 'admin', 'control');
        $planId = $this->plan();
        $payload = [
            'source_type' => 'lot', 'source_ref' => 'LOT-TEST-001',
            'inspected_units' => 10, 'rejected_units' => 1,
            'measured' => 12.5, 'evidence_ref' => 'evidence://test/temperature',
        ];
        $uri = '/api/quality/controls/'.$planId.'/checks';
        $first = $this->withHeader('Idempotency-Key', 'check-fixture-0001')
            ->postJson($uri, $payload)->assertCreated();
        $first->assertJsonPath('check.result','fail');
        $ncId = $first->json('nonconformity.id');
        $actionId = $first->json('nonconformity.action_id');
        $this->assertNotNull($ncId);
        $this->assertNotNull($actionId);
        $this->assertDatabaseHas('quality_nonconformities', [
            'id' => $ncId, 'quarantine_units' => 1, 'status' => 'open',
        ]);
        $this->assertDatabaseHas('quality_actions', [
            'id' => $actionId, 'status' => 'needs_plan', 'kind' => 'corrective',
        ]);
        $this->withHeader('Idempotency-Key', 'check-fixture-0001')
            ->postJson($uri, $payload)->assertOk()->assertJsonPath('nonconformity.id', $ncId);
        $this->assertDatabaseCount('quality_checks', 1);
        $this->assertDatabaseCount('quality_nonconformities', 1);
        $this->assertDatabaseCount('quality_actions', 1);
        $this->withHeader('Idempotency-Key', 'check-fixture-0001')
            ->postJson($uri, [...$payload, 'measured' => 30])->assertStatus(409);
        $this->postJson('/api/quality/nonconformities/'.$ncId.'/transition', [
            'version' => 1, 'status' => 'closed', 'decision' => 'corrected',
            'closure_reason' => 'No longer failing', 'closure_evidence' => 'proof://test/close',
        ])->assertStatus(409);
        $this->postJson('/api/quality/nonconformities/'.$ncId.'/transition', [
            'version' => 1, 'status' => 'contained',
        ])->assertCreated()->assertJsonPath('status','contained');
        $this->postJson('/api/quality/nonconformities/'.$ncId.'/transition', [
            'version' => 1, 'status' => 'contained',
        ])->assertStatus(409);
        $this->postJson('/api/quality/nonconformities/'.$ncId.'/transition', [
            'version' => 2, 'status' => 'closed', 'decision' => 'corrected',
            'closure_reason' => 'Validated', 'closure_evidence' => 'proof://test/close',
        ])->assertStatus(409);
    }
    public function test_quality_data_never_crosses_companies(): void
    {
        $a = $this->company();
        $b = Company::create(['code' => 'QUALITY_B', 'name' => 'Quality B']);
        $this->signIn($a, 'admin', 'tenant-a');
        $id = $this->plan();
        $this->signIn($b, 'admin', 'tenant-b');
        $this->getJson('/api/quality/controls/'.$id)->assertNotFound();
        $this->postJson('/api/quality/controls/'.$id.'/checks', [
            'source_type'=>'lot','source_ref'=>'OUTSIDE','measured'=>12,
            'inspected_units'=>10,'rejected_units'=>1,'evidence_ref'=>'test://proof',
        ])->assertNotFound();
        $this->getJson('/api/quality/controls')->assertOk()->assertJsonPath('total',0);
    }
    public function test_reader_cannot_read_or_write_quality_data(): void
    {
        $this->signIn($this->company(),'reader','read-denied');
        $this->getJson('/api/quality/metrics')->assertForbidden();
        $this->postJson('/api/quality/nonconformities', [
            'reference'=>'NC-01','title'=>'Invalid','severity'=>'major',
            'scope'=>'test','owner_id'=>1,'quarantine_units'=>1,
        ])->assertForbidden();
    }
    public function test_document_signature_is_bound_to_revision_and_independent_approver(): void
    {
        $company=$this->company();
        $creator=$this->signIn($company,'admin','doc-author');
        $doc=$this->postJson('/api/quality/documents',[
            'code'=>'PROC-SAFETY','title'=>'Safety SOP','country_code'=>'FR',
            'content'=>'The approved procedure is never silently overwritten.',
        ])->assertCreated();
        $id=$doc->json('document.id');
        $version=$doc->json('version.id');
        $this->postJson('/api/quality/versions/'.$version.'/transition',['status'=>'review'])
            ->assertCreated()->assertJsonPath('status','review');
        $this->postJson('/api/quality/versions/'.$version.'/transition',['status'=>'approved'])
            ->assertForbidden();
        $this->signIn($company,'admin','doc-approver');
        $this->postJson('/api/quality/versions/'.$version.'/transition',['status'=>'approved'])
            ->assertCreated()->assertJsonPath('status','approved');
        $this->postJson('/api/quality/versions/'.$version.'/transition',[
            'status'=>'published','effective_at'=>'2026-01-01 00:00:00',
        ])->assertCreated()->assertJsonPath('status','published');
        $this->postJson('/api/quality/versions/'.$version.'/acknowledge',[])
            ->assertCreated()->assertJsonPath('sha256',$doc->json('version.sha256'));
        $this->postJson('/api/quality/versions/'.$version.'/acknowledge',[])
            ->assertCreated();
        $this->assertDatabaseCount('quality_read_receipts',1);
        $this->postJson('/api/quality/documents/'.$id.'/versions',[
            'content'=>'Second revision is independent of the first document.',
        ])->assertCreated()->assertJsonPath('revision',2);
        $this->getJson('/api/quality/documents/'.$id)->assertOk()->assertJsonCount(2,'versions');
        $this->assertDatabaseHas('quality_document_versions',[
            'id'=>$version,'status'=>'published',
        ]);
    }
    public function test_major_audit_finding_requires_independent_closer(): void
    {
        $company=$this->company();
        $auditor=$this->signIn($company,'admin','auditor');
        $id=$this->postJson('/api/quality/audits',[
            'reference'=>'AUD-01','scope'=>'Plant A','standard'=>'ISO TEST',
            'standard_version'=>'2026','auditor_id'=>$auditor->id,
            'starts_on'=>'2026-01-01','ends_on'=>'2026-12-30',
        ])->assertCreated()->json('id');
        $this->postJson('/api/quality/audits/'.$id.'/transition',[
            'version'=>1,'status'=>'in_progress',
        ])->assertCreated();
        $this->postJson('/api/quality/audits/'.$id.'/findings',[
            'title'=>'Major deviation','severity'=>'major',
            'description'=>'Fixture for independent approval',
            'evidence_ref'=>'test://audit/evidence',
        ])->assertCreated();
        $this->postJson('/api/quality/audits/'.$id.'/transition',[
            'version'=>2,'status'=>'closed','closure_evidence'=>'test://audit/report',
        ])->assertForbidden();
        $this->signIn($company,'admin','audit-approver');
        $this->postJson('/api/quality/audits/'.$id.'/transition',[
            'version'=>2,'status'=>'closed','closure_evidence'=>'test://audit/report',
        ])->assertCreated()->assertJsonPath('status','closed');
        $this->getJson('/api/quality/audits/'.$id)->assertOk()->assertJsonCount(1,'findings');
    }
}
