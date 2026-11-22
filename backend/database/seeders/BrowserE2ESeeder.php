<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dedicated browser E2E identity; strictly opt-in for disposable CI/local DB.
 * Avoid sharing the default admin login rate-limit bucket across test suites.
 */
final class BrowserE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])
            || getenv('ATLAS_BROWSER_E2E_CONFIRM') !== 'YES_ISOLATED_CI') {
            throw new RuntimeException('Browser E2E fixture requires isolated local/testing confirmation.');
        }
        if (DB::getDriverName() !== 'pgsql'
            || ! in_array((string) config('database.connections.pgsql.host'), ['db', '127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Browser E2E fixture requires local PostgreSQL.');
        }
        $password = config('atlas.demo_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('DEMO_PASSWORD must contain at least 12 characters.');
        }
        $tenant = Company::where('code', 'ATLAS_DEMO')->firstOrFail();
        $admin = Role::where('name', 'admin')->firstOrFail();
        $user = User::firstOrCreate(
            ['email' => 'v5-browser-e2e@atlas.example'],
            ['name' => 'ATLAS V5 · Compte de recette fictif', 'password' => $password, 'role_id' => $admin->id],
        );
        $user->companies()->syncWithoutDetaching([$tenant->id => ['role_id' => $admin->id]]);

        // The DI/BT E2E scenario has its own synthetic login bucket.
        // Production's five-logins-per-minute limit remains unchanged.
        $maintenanceUser = User::firstOrCreate(
            ['email' => 'v5-browser-e2e-maintenance@atlas.example'],
            ['name' => 'ATLAS V5 · Recette DI/BT fictive', 'password' => $password, 'role_id' => $admin->id],
        );
        $maintenanceUser->companies()->syncWithoutDetaching([$tenant->id => ['role_id' => $admin->id]]);
        // M01 runs independently of the V5 navigation and maintenance smoke tests:
        // a shared login bucket can trigger false negatives under Laravel throttling.
        $pilotageUser = User::firstOrCreate(
            ['email' => 'v5-browser-e2e-pilotage@atlas.example'],
            ['name' => 'ATLAS V5 · Recette pilotage fictive', 'password' => $password, 'role_id' => $admin->id],
        );
        $pilotageUser->companies()->syncWithoutDetaching([$tenant->id => ['role_id' => $admin->id]]);
        $biUser = User::firstOrCreate(
            ['email' => 'v5-browser-e2e-bi@atlas.example'],
            ['name' => 'ATLAS V5 · Recette BI fictive', 'password' => $password, 'role_id' => $admin->id],
        );
        $biUser->companies()->syncWithoutDetaching([$tenant->id => ['role_id' => $admin->id]]);
        $this->command?->info('Comptes isolés de recette navigateur activés.');
    }
}
