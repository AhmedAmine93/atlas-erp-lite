<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Establishment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Opt-in, synthetic multi-company fixtures for F05.
 *
 * Run only in isolated local/test databases:
 *   php artisan db:seed --class=MultiTenantDemoSeeder
 *
 * Never called by DatabaseSeeder or a production deployment.
 */
class MultiTenantDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('F05 demonstration fixtures are restricted to local/testing environments.');
        }

        $password = config('atlas.demo_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new \RuntimeException('DEMO_PASSWORD (minimum 12 characters) is required for F05 fixtures.');
        }

        $readerRoleId = Role::where('name', 'reader')->value('id');
        if (! $readerRoleId) {
            throw new \RuntimeException('Run ERP migrations before the F05 demo seeder.');
        }

        DB::transaction(function () use ($password, $readerRoleId): void {
            // Deterministic fixture identifiers; all addresses and contacts are synthetic.
            $companies = [
                'ATLAS_F05_A' => [
                    'name' => 'ATLAS F05 Industrie (fictif)',
                    'sites' => [
                        ['code' => 'PARIS_DEMO', 'name' => 'Atelier Paris (fictif)', 'city' => 'Paris'],
                        ['code' => 'LYON_DEMO', 'name' => 'Entrepôt Lyon (fictif)', 'city' => 'Lyon'],
                    ],
                ],
                'ATLAS_F05_B' => [
                    'name' => 'ATLAS F05 Hôtellerie (fictif)',
                    'sites' => [
                        ['code' => 'NICE_DEMO', 'name' => 'Hôtel Nice (fictif)', 'city' => 'Nice'],
                        ['code' => 'LILLE_DEMO', 'name' => 'Bureau Lille (fictif)', 'city' => 'Lille'],
                    ],
                ],
            ];

            $user = User::firstOrCreate(
                ['email' => 'multi-f05@atlas.example'],
                [
                    'name' => 'Multi F05 · Utilisateur de test',
                    'password' => $password,
                    'role_id' => $readerRoleId,
                ],
            );

            foreach ($companies as $code => $fixture) {
                $company = Company::firstOrCreate(['code' => $code], ['name' => $fixture['name']]);
                foreach ($fixture['sites'] as $site) {
                    Establishment::firstOrCreate(
                        ['company_id' => $company->id, 'code' => $site['code']],
                        ['name' => $site['name'], 'city' => $site['city'], 'active' => true],
                    );
                }
                $user->companies()->syncWithoutDetaching([$company->id]);
            }
        });

        $this->command?->info('F05: two fictitious companies, four sites and one shared reader account are ready.');
    }
}
