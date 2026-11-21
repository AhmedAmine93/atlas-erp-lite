<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Database\Seeders\MultiTenantDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_f05_creates_two_companies_four_sites_and_one_shared_reader_idempotently(): void
    {
        config()->set('atlas.demo_password', 'F05DemoPassword2026!');

        $this->seed(MultiTenantDemoSeeder::class);
        $this->seed(MultiTenantDemoSeeder::class);

        $a = Company::where('code', 'ATLAS_F05_A')->firstOrFail();
        $b = Company::where('code', 'ATLAS_F05_B')->firstOrFail();
        $user = User::where('email', 'multi-f05@atlas.example')->firstOrFail();

        $this->assertSame(2, Company::whereIn('code', ['ATLAS_F05_A', 'ATLAS_F05_B'])->count());
        $this->assertSame(2, Establishment::where('company_id', $a->id)->count());
        $this->assertSame(2, Establishment::where('company_id', $b->id)->count());
        $this->assertSame(1, User::where('email', 'multi-f05@atlas.example')->count());
        $this->assertSame('reader', $user->role->name);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $user->companies()->pluck('companies.id')->all());

        $this->actingAs($user);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('active_company_id', $a->id)
            ->assertJsonCount(2, 'companies');
        $this->postJson('/api/tenant/switch', ['company_id' => $b->id])->assertOk();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('active_company_id', $b->id);
        $this->getJson('/api/customers')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_f05_fixture_is_opt_in_and_rejects_non_demo_environments(): void
    {
        $this->assertDatabaseMissing('companies', ['code' => 'ATLAS_F05_A']);
        $this->app['env'] = 'production';
        try {
            $this->expectException(\RuntimeException::class);
            // Call the seeder directly: Artisan would prompt for confirmation
            // in production before our environment guard executes.
            app(MultiTenantDemoSeeder::class)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
