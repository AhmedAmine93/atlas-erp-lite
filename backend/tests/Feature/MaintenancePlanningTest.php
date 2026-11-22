<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MaintenancePlanningTest extends TestCase
{
    use RefreshDatabase;

    private function login(Company $company, string $role, string $suffix): void
    {
        $legacy = Role::where('name', 'admin')->value('id');
        $user = User::create([
            'name' => 'Planning '.$suffix,
            'email' => 'planning-'.$suffix.'@example.test',
            'password' => 'TestPassword2026!',
            'role_id' => $legacy,
        ]);
        $user->companies()->attach($company->id, [
            'role_id' => Role::where('name', $role)->value('id'),
        ]);
        $this->actingAs($user)->withSession(['active_company_id' => $company->id]);
    }

    private function createWorkOrder(int $index, int $asset): int
    {
        $di = $this->postJson('/api/maintenance/requests', [
            'asset_id' => $asset,
            'reference' => 'PL-DI-'.$index,
            'title' => 'Intervention fictive '.$index,
            'priority' => 'high',
        ])->assertCreated()->json('id');
        $this->postJson("/api/maintenance/requests/{$di}/qualify")->assertOk();
        return $this->postJson("/api/maintenance/requests/{$di}/work-orders")
            ->assertCreated()->json('id');
    }

    public function test_scheduling_rejects_overlaps_supports_cancellation_and_isolates_tenants(): void
    {
        $a = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $b = Company::create(['code' => 'PLANNING_B', 'name' => 'Tenant B']);
        $this->getJson('/api/maintenance/planning')->assertUnauthorized();
        $this->login($a, 'reader', 'reader');
        $this->getJson('/api/maintenance/planning')->assertForbidden();

        $this->login($a, 'admin', 'admina');
        $team = $this->postJson('/api/maintenance/teams', [
            'code' => 'TEAM-01', 'name' => 'Équipe électricité',
        ])->assertCreated()->json('id');
        $tech = $this->postJson('/api/maintenance/technicians', [
            'code' => 'TECH-01', 'name' => 'Technicien fictif',
            'team_id' => $team,
        ])->assertCreated()->json('id');
        $this->postJson('/api/maintenance/technicians', [
            'code' => 'TECH-01', 'name' => 'Duplicate',
            'team_id' => $team,
        ])->assertUnprocessable();

        $site = $this->postJson('/api/maintenance/sites', [
            'code' => 'PL-SITE', 'name' => 'Site fictif',
        ])->assertCreated()->json('id');
        $asset = $this->postJson('/api/maintenance/assets', [
            'site_id' => $site, 'asset_code' => 'PL-ASSET', 'name' => 'Groupe électrogène',
        ])->assertCreated()->json('id');
        $wo1 = $this->createWorkOrder(1, $asset);
        $wo2 = $this->createWorkOrder(2, $asset);
        $start = CarbonImmutable::now()->addDays(4)->startOfHour();
        $slot1 = [
            'technician_id' => $tech,
            'starts_at' => $start->format('Y-m-d\TH:i:sP'),
            'ends_at' => $start->addHours(2)->format('Y-m-d\TH:i:sP'),
        ];
        $slot2 = [
            'technician_id' => $tech,
            'starts_at' => $start->addHour()->format('Y-m-d\TH:i:sP'),
            'ends_at' => $start->addHours(3)->format('Y-m-d\TH:i:sP'),
        ];
        $this->postJson("/api/maintenance/work-orders/{$wo1}/schedule",
            array_merge($slot1, ['company_id' => $b->id]))->assertUnprocessable();
        $id = $this->postJson("/api/maintenance/work-orders/{$wo1}/schedule", $slot1)
            ->assertCreated()->assertJsonPath('status', 'scheduled')->json('id');
        // Exact duplicate is replay-safe.
        $this->postJson("/api/maintenance/work-orders/{$wo1}/schedule", $slot1)
            ->assertOk()->assertJsonPath('id', $id);
        $this->postJson("/api/maintenance/work-orders/{$wo1}/schedule", $slot2)
            ->assertStatus(409);
        // A distinct order cannot book the same technician in overlapping hours.
        $this->postJson("/api/maintenance/work-orders/{$wo2}/schedule", $slot2)
            ->assertStatus(409);
        // A following adjacent interval is allowed (half-open PostgreSQL range).
        $after = array_merge($slot1, [
            'starts_at' => $start->addHours(2)->format('Y-m-d\TH:i:sP'),
            'ends_at' => $start->addHours(4)->format('Y-m-d\TH:i:sP'),
        ]);
        $this->postJson("/api/maintenance/work-orders/{$wo2}/schedule", $after)
            ->assertCreated();
        $this->postJson("/api/maintenance/planning/{$id}/cancel", [
            'reason' => 'Short',
        ])->assertUnprocessable();
        $this->postJson("/api/maintenance/planning/{$id}/cancel", [
            'reason' => 'Replanification exceptionnelle fictive',
        ])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/maintenance/planning/{$id}/cancel", [
            'reason' => 'Replanification exceptionnelle fictive',
        ])->assertStatus(409);
        $this->postJson("/api/maintenance/work-orders/{$wo1}/schedule", $slot1)
            ->assertCreated();

        $this->getJson('/api/maintenance/planning')->assertOk()
            ->assertJsonPath('total', 3);
        $this->getJson('/api/maintenance/reports/summary')->assertOk()
            ->assertJsonPath('work_orders_by_status.open', 2)
            ->assertJsonPath('schedules_by_status.scheduled', 2)
            ->assertJsonPath('schedules_by_status.cancelled', 1);
        $this->assertDatabaseCount('maintenance_schedules', 3);

        $this->login($b, 'admin', 'adminb');
        $this->getJson('/api/maintenance/teams')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/maintenance/technicians')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/maintenance/planning')->assertOk()->assertJsonPath('total', 0);
        $this->postJson("/api/maintenance/work-orders/{$wo1}/schedule", $slot1)->assertNotFound();
        $this->postJson('/api/maintenance/technicians', [
            'code' => 'B-TECH', 'name' => 'Intrusion', 'team_id' => $team,
        ])->assertNotFound();
        $this->postJson("/api/maintenance/planning/{$id}/cancel", [
            'reason' => 'Intercompany cancellation attempt',
        ])->assertNotFound();
    }

    public function test_restricted_postgres_role_has_p068_table_rls_but_no_delete(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL role and RLS only');
        }
        $tables = ['maintenance_teams', 'maintenance_technicians', 'maintenance_schedules'];
        foreach ($tables as $table) {
            $grants = DB::selectOne(
                "SELECT has_table_privilege('atlas_app_runtime', ?, 'SELECT')::int AS readable,
                        has_table_privilege('atlas_app_runtime', ?, 'INSERT')::int AS insertable,
                        has_table_privilege('atlas_app_runtime', ?, 'DELETE')::int AS deletable",
                array_fill(0, 3, 'public.'.$table),
            );
            $this->assertSame(1, (int) $grants->readable);
            $this->assertSame(1, (int) $grants->insertable);
            $this->assertSame(0, (int) $grants->deletable);
            $policy = DB::table('pg_policies')->where('schemaname', 'public')
                ->where('tablename', $table)
                ->where('policyname', 'atlas_f20_runtime_tenant_policy')
                ->count();
            $this->assertSame(1, (int) $policy);
        }
    }
}
