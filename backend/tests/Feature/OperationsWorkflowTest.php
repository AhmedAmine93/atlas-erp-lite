<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role, Company $company, string $email): User
    {
        $user = User::create([
            'name' => 'M18 '.$role,
            'email' => $email,
            'password' => 'TestPassword2026!',
            // Legacy global role intentionally disagrees with the tenant role.
            'role_id' => Role::where('name', 'admin')->value('id'),
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
        return $user;
    }

    private function company(): Company
    {
        return Company::where('code', 'ATLAS_DEMO')->firstOrFail();
    }

    private function service(): int
    {
        return (int) $this->postJson('/api/v1/ops/services', [
            'code' => 'erp-api', 'name' => 'API ERP',
            'owner' => 'Equipe SRE', 'criticality' => 'critical',
            'runbook_url' => 'https://example.test/runbook',
        ])->assertCreated()->json('id');
    }

    private function backup(string $idempotency): int
    {
        return (int) $this->postJson('/api/v1/ops/backups', [
            'artifact_reference' => 'object-store-backup-001',
            'environment' => 'staging', 'encrypted' => true,
            'completed_at' => '2026-11-22T12:00:00+00:00',
            'idempotency_key' => $idempotency,
        ])->assertCreated()->json('id');
    }

    private function release(): int
    {
        return (int) $this->postJson('/api/v1/ops/deployments', [
            'application' => 'atlas-one',
            'environment' => 'production',
            'image_digest' => 'sha256:'.str_repeat('a', 64),
            'sbom_reference' => 'sbom-001',
            'provenance_reference' => 'attestation-001',
            'migration_strategy' => 'expand_contract',
            'rollback_plan' => 'Use approved rollback runbook. Restore database only in isolated environment.',
            'idempotency_key' => '12000000-0000-4000-8000-000000000001',
        ])->assertCreated()->json('id');
    }

    public function test_roles_reject_unprivileged_reads_and_writes_even_if_legacy_role_is_admin(): void
    {
        $tenant = $this->company();
        $this->getJson('/api/v1/ops/overview')->assertUnauthorized();
        $this->member('reader', $tenant, 'ops-reader@example.test');
        $this->getJson('/api/v1/ops/overview')->assertForbidden();
        $this->postJson('/api/v1/ops/services', ['code' => 'x'])->assertForbidden();
        $this->member('ops_viewer', $tenant, 'ops-viewer@example.test');
        $this->getJson('/api/v1/ops/overview')->assertOk()->assertJsonPath('live_monitoring', false);
        $this->getJson('/api/v1/ops/logs')->assertOk();
        $this->postJson('/api/v1/ops/services', ['code' => 'x'])->assertForbidden();
        $this->member('ops_operator', $tenant, 'ops-operator@example.test');
        $this->service();
        $this->getJson('/api/v1/ops/services')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_company_isolation_and_cross_tenant_foreign_keys(): void
    {
        $a = $this->company();
        $b = Company::create(['code' => 'OPS_COMPANY_B', 'name' => 'Autre entreprise']);
        $userA = $this->member('ops_operator', $a, 'ops-a@example.test');
        $service = $this->service();
        $this->postJson('/api/v1/ops/observations', [
            'service_id' => $service, 'layer' => 'technical', 'state' => 'healthy',
            'source' => 'synthetic-monitor', 'observed_at' => now()->toIso8601String(),
        ])->assertCreated();
        $this->member('ops_operator', $b, 'ops-b@example.test');
        $this->getJson('/api/v1/ops/services')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/ops/services/'.$service)->assertNotFound();
        $this->postJson('/api/v1/ops/observations', [
            'service_id' => $service, 'layer' => 'technical', 'state' => 'down',
            'source' => 'synthetic-monitor', 'observed_at' => now()->toIso8601String(),
        ])->assertUnprocessable();
        $this->getJson('/api/v1/ops/overview')->assertJsonPath('counts.observations', 0);
        $this->actingAs($userA)->withSession(['active_company_id' => $a->id]);
        $this->getJson('/api/v1/ops/overview')->assertJsonPath('counts.observations', 1);
    }

    public function test_logs_redact_credentials_and_reject_mass_assignment(): void
    {
        $this->member('ops_operator', $this->company(), 'ops-logs@example.test');
        $response = $this->postJson('/api/v1/ops/logs', [
            'component' => 'api', 'level' => 'warning',
            'correlation_id' => '12000000-0000-4000-8000-000000000004',
            'occurred_at' => now()->toIso8601String(),
            'message' => 'Bearer abcdef123 password=unguessable alice@example.test',
        ])->assertCreated()->assertDontSee('unguessable')->assertDontSee('abcdef123')
            ->assertDontSee('alice@example.test')->assertSee('[REDACTED]');
        $this->assertStringContainsString('[EMAIL]', $response->json('message'));
        $this->postJson('/api/v1/ops/logs', [
            'component' => 'api', 'level' => 'info',
            'correlation_id' => '12000000-0000-4000-8000-000000000004',
            'occurred_at' => now()->toIso8601String(),
            'message' => 'test', 'company_id' => 999,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('ops_logs', 1);
    }

    public function test_jobs_replay_version_and_manual_requalification(): void
    {
        $this->member('ops_operator', $this->company(), 'ops-jobs@example.test');
        $payload = [
            'name' => 'nightly-reconcile',
            'correlation_id' => '12000000-0000-4000-8000-000000000005',
            'window_start' => '2026-11-22T00:00:00Z',
            'window_end' => '2026-11-22T02:00:00Z',
            'max_attempts' => 2,
            'runbook_url' => 'https://example.test/reconcile',
            'idempotency_key' => '12000000-0000-4000-8000-000000000006',
        ];
        $id = (int) $this->postJson('/api/v1/ops/jobs', $payload)->assertCreated()->json('id');
        $this->postJson('/api/v1/ops/jobs', $payload)->assertOk()->assertJsonPath('id', $id);
        $this->postJson('/api/v1/ops/jobs', [...$payload, 'name' => 'different'])
            ->assertConflict();
        $this->assertDatabaseCount('ops_jobs', 1);
        $this->assertDatabaseCount('ops_events', 1);
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", ['status' => 'succeeded', 'version' => 1])->assertConflict();
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", ['status' => 'running', 'version' => 1])
            ->assertOk()->assertJsonPath('attempts', 1);
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", ['status' => 'succeeded', 'version' => 1])->assertConflict();
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", [
            'status' => 'failed', 'version' => 2, 'failure_reason' => 'Timeout while reading synthetic data.',
        ])->assertOk();
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", ['status' => 'queued', 'version' => 3])->assertConflict();
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", ['status' => 'requalified', 'version' => 3])->assertOk();
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", ['status' => 'queued', 'version' => 4])->assertOk();
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", ['status' => 'running', 'version' => 5])
            ->assertOk()->assertJsonPath('attempts', 2);
        $this->postJson("/api/v1/ops/jobs/{$id}/transition", ['status' => 'succeeded', 'version' => 6])
            ->assertOk();
        $this->getJson("/api/v1/ops/jobs/{$id}")->assertJsonCount(7, 'events');
    }

    public function test_promotion_requires_staging_go_and_proven_restore(): void
    {
        $this->member('ops_operator', $this->company(), 'ops-release@example.test');
        $release = $this->release();
        $this->postJson("/api/v1/ops/deployments/{$release}/transition", [
            'status' => 'approved', 'version' => 1, 'decision' => 'go',
        ])->assertConflict();
        $this->postJson("/api/v1/ops/deployments/{$release}/transition", [
            'status' => 'staging_verified', 'version' => 1, 'staging_evidence' => 'E2E build #421',
        ])->assertOk();
        $this->postJson("/api/v1/ops/deployments/{$release}/transition", [
            'status' => 'approved', 'version' => 2, 'decision' => 'go',
            'backup_restore_reference' => 'object-store-backup-001',
        ])->assertConflict();
        $backup = $this->backup('12000000-0000-4000-8000-000000000010');
        $this->postJson("/api/v1/ops/backups/{$backup}/transition", [
            'status' => 'restore_verified', 'version' => 1,
            'isolated_restore_reference' => 'isolated-staging-restore-1',
            'observed_rpo_seconds' => 12, 'observed_rto_seconds' => 60,
            'expected_rows' => 125, 'restored_rows' => 124,
        ])->assertConflict();
        $this->postJson("/api/v1/ops/backups/{$backup}/transition", [
            'status' => 'restore_verified', 'version' => 1,
            'isolated_restore_reference' => 'isolated-staging-restore-1',
            'observed_rpo_seconds' => 12, 'observed_rto_seconds' => 60,
            'expected_rows' => 125, 'restored_rows' => 125,
        ])->assertOk()->assertJsonPath('status', 'restore_verified');
        $this->postJson("/api/v1/ops/deployments/{$release}/transition", [
            'status' => 'approved', 'version' => 2, 'decision' => 'go',
            'backup_restore_reference' => 'object-store-backup-001',
        ])->assertOk();
        $this->postJson("/api/v1/ops/deployments/{$release}/transition", [
            'status' => 'promoted', 'version' => 3,
        ])->assertOk();
        $this->assertDatabaseHas('ops_deployments', [
            'id' => $release, 'status' => 'promoted', 'decision' => 'go',
        ]);
    }

    public function test_invalid_resource_and_stale_company_header_are_rejected(): void
    {
        $this->member('ops_operator', $this->company(), 'ops-header@example.test');
        $this->getJson('/api/v1/ops/nope')->assertNotFound();
        $this->withHeaders(['X-Atlas-Expected-Company' => '999999'])
            ->getJson('/api/v1/ops/overview')->assertConflict();
        $this->assertDatabaseCount('ops_events', 0);
    }
}
