<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SalesService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Explicit opt-in, deterministic, append-only synthetic population for ATLAS V5.
 *
 * WARNING: executes against the CURRENT configured PostgreSQL database.
 * Refuses non-local/testing env, non-ATLAS DB names and unconfirmed runs.
 * Existing rows are never deleted, truncated or overwritten.
 *
 * docker compose exec -e ATLAS_DEMO_DATA_CONFIRM=YES_LOCAL_ONLY backend \
 *   php artisan db:seed --class=MassiveDemoSeeder
 *
 * Never wire this into DatabaseSeeder, setup.mjs or production startup.
 */
final class MassiveDemoSeeder extends Seeder
{
    private const TENANTS = ['ATLAS_DEMO', 'ATLAS_SYNTH_B', 'ATLAS_SYNTH_C'];
    private const PEOPLE_PER_TENANT = 50; // 150 different HTTP login identities.
    private const MARKER = 'ATLAS_SYNTH_LOAD_V1';

    private function fixture(string $table, array $identity, array $attributes): int
    {
        DB::table($table)->insertOrIgnore(array_merge(
            $identity,
            $attributes,
            ['created_at' => now(), 'updated_at' => now()],
        ));
        $id = DB::table($table)->where($identity)->value('id');
        if (! $id) {
            throw new RuntimeException("Impossible de retrouver la ligne fictive : {$table}");
        }
        return (int) $id;
    }

    private function checkSafety(): string
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Jeu massif réservé aux environnements APP_ENV=local/testing.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Ce seeder exige les vraies tables PostgreSQL.');
        }
        $database = DB::connection()->getDatabaseName();
        if (! preg_match('/^atlas(?:_[a-z0-9_]+)?$/i', $database)) {
            throw new RuntimeException('Base PostgreSQL non autorisée : nom attendu atlas ou atlas_*.');
        }
        $host = (string) config('database.connections.pgsql.host');
        if (! in_array($host, ['db', 'localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Hôte distant refusé pour un remplissage fictif massif.');
        }
        if (getenv('ATLAS_DEMO_DATA_CONFIRM') !== 'YES_LOCAL_ONLY') {
            throw new RuntimeException(
                'Ajoutez explicitement ATLAS_DEMO_DATA_CONFIRM=YES_LOCAL_ONLY pour autoriser le remplissage.'
            );
        }
        $password = config('atlas.demo_password');
        if (! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Définissez DEMO_PASSWORD (minimum 12 caractères).');
        }
        if (! DB::table('roles')->where('name', 'admin')->exists()) {
            throw new RuntimeException('Exécutez les migrations et DatabaseSeeder avant MassiveDemoSeeder.');
        }
        return $password;
    }

    public function run(): void
    {
        $password = $this->checkSafety();
        $hash = Hash::make($password);
        $roles = DB::table('roles')->pluck('id', 'name')->all();
        foreach (['admin', 'commercial', 'stock', 'reader', 'support_n2', 'support_n3'] as $name) {
            if (empty($roles[$name])) {
                throw new RuntimeException("Rôle non trouvé : {$name}. Exécutez php artisan migrate.");
            }
        }

        foreach (self::TENANTS as $tenantIndex => $tenantCode) {
            DB::transaction(function () use ($tenantIndex, $tenantCode, $roles, $hash): void {
                $companyId = $this->fixture('companies', ['code' => $tenantCode], [
                    'name' => "ATLAS V5 / {$tenantCode} (100 % fictif)",
                    'email' => 'contact-'.strtolower($tenantCode).'@atlas.example',
                ]);
                $users = [];
                for ($i = 1; $i <= self::PEOPLE_PER_TENANT; $i++) {
                    $number = $tenantIndex * self::PEOPLE_PER_TENANT + $i;
                    $roleName = match ($i % 10) {
                        1 => 'admin',
                        2, 3, 4 => 'commercial',
                        5, 6 => 'stock',
                        7, 8 => 'reader',
                        9 => 'support_n2',
                        default => 'support_n3',
                    };
                    $userId = $this->fixture('users', [
                        'email' => sprintf('load%03d@atlas.example', $number),
                    ], [
                        'name' => sprintf('Utilisateur Fictif %03d · %s', $number, $roleName),
                        'password' => $hash,
                        'role_id' => $roles[$roleName],
                    ]);
                    DB::table('company_user')->insertOrIgnore([
                        'company_id' => $companyId, 'user_id' => $userId, 'role_id' => $roles[$roleName],
                    ]);
                    $users[] = $userId;
                }
                $adminId = $users[0];
                $admin = User::query()->findOrFail($adminId);
                Auth::login($admin);
                request()->attributes->set('atlas_company_id', $companyId);

                try {
                    $this->seedCompany($companyId, $tenantIndex, $tenantCode, $users, $adminId);
                } finally {
                    Auth::logout();
                    request()->attributes->remove('atlas_company_id');
                }
            }, 3);
            $this->command?->info("{$tenantCode} : données fictives insérées dans PostgreSQL.");
        }

        $counts = [];
        foreach ([
            'companies', 'users', 'company_user', 'customers', 'suppliers', 'products',
            'quotes', 'orders', 'invoices', 'stock_movements', 'support_incidents',
            'maintenance_sites', 'maintenance_assets', 'maintenance_requests',
            'maintenance_work_orders', 'hotel_properties', 'hotel_rooms', 'hotel_bookings',
            'purchase_requests', 'data_source_registry', 'data_ingestion_batches',
            'data_ingested_csv_rows',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $this->command?->info('Comptes de test : load001@atlas.example à load150@atlas.example.');
        $this->command?->line(json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function seedCompany(int $companyId, int $tenantIndex, string $code, array $users, int $adminId): void
    {
        $prefix = 'SYN'.($tenantIndex + 1);
        $customers = [];
        $products = [];

        for ($i = 1; $i <= 4; $i++) {
            $this->fixture('establishments', ['company_id' => $companyId, 'code' => "{$prefix}-EST-{$i}"], [
                'name' => "Établissement fictif {$i}", 'city' => ['Lyon', 'Nantes', 'Nice', 'Lille'][$i - 1],
                'active' => true,
            ]);
        }

        for ($i = 1; $i <= 180; $i++) {
            $customers[] = $this->fixture('customers', [
                'company_id' => $companyId, 'email' => sprintf('client-%s-%04d@atlas.example', $prefix, $i),
            ], [
                'name' => sprintf('%s %03d (fictif)', ['Atelier', 'Entreprise', 'Société', 'Studio', 'Groupe'][$i % 5], $i),
                'phone' => sprintf('0100%06d', $tenantIndex * 10000 + $i),
                'address' => "{$i} rue Imaginaire, Ville Démonstration",
            ]);
        }
        for ($i = 1; $i <= 40; $i++) {
            $this->fixture('suppliers', [
                'company_id' => $companyId, 'email' => sprintf('fournisseur-%s-%03d@atlas.example', $prefix, $i),
            ], ['name' => "Partenaire fictif {$prefix}-{$i}", 'address' => 'Adresse de démonstration uniquement']);
        }
        for ($i = 1; $i <= 160; $i++) {
            $sku = sprintf('%s-ART-%04d', $prefix, $i);
            $initialStock = 2000 + ($i % 20) * 20;
            $productId = $this->fixture('products', [
                'company_id' => $companyId, 'reference' => $sku,
            ], [
                'name' => sprintf('%s de démonstration %03d', ['Pompe', 'Capteur', 'Pièce', 'Consommable', 'Mobilier'][$i % 5], $i),
                'category' => ['Industrie', 'Électronique', 'Maintenance', 'Bureau'][$i % 4],
                'price_cents' => 1500 + $i * 75, 'vat_bps' => 2000,
                'stock' => $initialStock, 'alert_threshold' => 10,
            ]);
            $products[] = $productId;
            // One initial stock movement only; repeated seeds do not adjust real balances.
            $reason = self::MARKER.':initial:'.$sku;
            if (! DB::table('stock_movements')->where('company_id', $companyId)->where('reason', $reason)->exists()) {
                DB::table('stock_movements')->insert([
                    'company_id' => $companyId, 'product_id' => $productId,
                    'quantity' => $initialStock, 'balance_after' => $initialStock,
                    'reason' => $reason, 'user_id' => $adminId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $sales = app(SalesService::class);
        $invoices = app(InvoiceService::class);
        for ($i = 1; $i <= 95; $i++) {
            $marker = self::MARKER.':'.$prefix.':quote:'.$i;
            if (DB::table('quotes')->where('company_id', $companyId)->where('notes', $marker)->exists()) {
                continue;
            }
            $quote = $sales->create('quotes', [
                'customer_id' => $customers[($i * 7) % count($customers)],
                'notes' => $marker,
                'items' => [[
                    'product_id' => $products[($i * 11) % count($products)],
                    'quantity' => 1 + $i % 4,
                ]],
            ]);
            if ($i % 6 === 0) { // Keep some pending quotes.
                continue;
            }
            $order = $sales->convert($quote->id);
            if ($i % 7 === 0) { // Keep draft orders.
                continue;
            }
            $sales->validateOrder($order->id);
            $invoice = $invoices->create($order->id);
            if ($i % 9 === 0) { // Keep draft invoices.
                continue;
            }
            $invoices->transition($invoice->id, 'issued');
            if ($i % 3 === 0) {
                $invoices->transition($invoice->id, 'paid');
            }
        }

        for ($i = 1; $i <= 95; $i++) {
            $requester = $users[($i * 3) % count($users)];
            if ($requester === $adminId) {
                $requester = $users[1];
            }
            $state = ['draft', 'submitted', 'rejected', 'approved'][$i % 4];
            $this->fixture('purchase_requests', [
                'company_id' => $companyId, 'reference' => sprintf('%s-DA-%04d', $prefix, $i),
            ], [
                'title' => "Expression de besoin fictive {$i}",
                'justification' => 'Équipement de démonstration, sans engagement financier réel.',
                'estimated_total_cents' => 12000 + $i * 880,
                'currency' => 'EUR', 'status' => $state,
                'requested_by' => $requester,
                'submitted_at' => $state === 'draft' ? null : now()->subDays($i % 30),
                'approved_by' => $state === 'approved' ? $adminId : null,
                'approved_at' => $state === 'approved' ? now()->subDays($i % 20) : null,
                'decided_by' => in_array($state, ['approved', 'rejected'], true) ? $adminId : null,
                'decided_at' => in_array($state, ['approved', 'rejected'], true) ? now()->subDays($i % 20) : null,
                'decision_reason' => $state === 'rejected' ? 'Budget fictif à réviser.' : null,
                'revision' => 1,
            ]);
        }

        $siteIds = [];
        for ($i = 1; $i <= 9; $i++) {
            $siteIds[] = $this->fixture('maintenance_sites', [
                'company_id' => $companyId, 'code' => "{$prefix}-SITE-{$i}",
            ], ['name' => "Site industriel fictif {$i}", 'active' => true]);
        }
        $assetIds = [];
        for ($i = 1; $i <= 120; $i++) {
            $assetIds[] = $this->fixture('maintenance_assets', [
                'company_id' => $companyId, 'asset_code' => sprintf('%s-EQ-%04d', $prefix, $i),
            ], [
                'site_id' => $siteIds[$i % count($siteIds)],
                'name' => "Équipement de production fictif {$i}",
                'status' => $i % 17 === 0 ? 'out_of_service' : 'in_service',
                'commissioned_on' => now()->subYears(1 + $i % 7)->toDateString(),
            ]);
        }
        for ($i = 1; $i <= 150; $i++) {
            $status = $i % 4 === 0 ? 'new' : 'qualified';
            $assetId = $assetIds[$i % count($assetIds)];
            $diId = $this->fixture('maintenance_requests', [
                'company_id' => $companyId, 'reference' => sprintf('%s-DI-%04d', $prefix, $i),
            ], [
                'asset_id' => $assetId, 'title' => "Diagnostic maintenance fictif {$i}",
                'description' => 'Essai fonctionnel sans équipement réel.',
                'priority' => ['low', 'normal', 'high', 'critical'][$i % 4],
                'status' => $status, 'created_by' => $users[$i % count($users)],
            ]);
            if ($status !== 'qualified') {
                continue;
            }
            $woStatus = ['open', 'in_progress', 'completed'][$i % 3];
            DB::table('maintenance_work_orders')->insertOrIgnore([
                'company_id' => $companyId, 'maintenance_request_id' => $diId,
                'asset_id' => $assetId,
                'priority' => ['low', 'normal', 'high', 'critical'][$i % 4],
                'summary' => "Travaux fictifs à réaliser {$i}", 'status' => $woStatus,
                'due_at' => now()->addDays($i % 20),
                'resolution' => $woStatus === 'completed' ? 'Inspection simulée et correction validée (fictif).' : null,
                'completed_at' => $woStatus === 'completed' ? now()->subDays($i % 10) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $roomIds = [];
        for ($h = 1; $h <= 3; $h++) {
            $hotelId = $this->fixture('hotel_properties', [
                'company_id' => $companyId, 'code' => "{$prefix}-HOTEL-{$h}",
            ], ['name' => "Hôtel ATLAS fictif {$h}", 'timezone' => 'Europe/Paris', 'active' => true]);
            for ($r = 1; $r <= 24; $r++) {
                $roomIds[] = [
                    'hotel' => $hotelId,
                    'room' => $this->fixture('hotel_rooms', [
                        'hotel_id' => $hotelId, 'room_number' => (string) (100 + $r),
                    ], [
                        'company_id' => $companyId,
                        'floor_code' => (string) (int) floor($r / 8),
                        'category' => $r % 4 === 0 ? 'Suite' : 'Double',
                        'room_status' => 'available',
                    ]),
                ];
            }
        }
        for ($i = 0; $i < 210; $i++) {
            $index = $i % count($roomIds);
            $slot = intdiv($i, count($roomIds));
            $start = now()->startOfDay()->addDays(1 + $slot * 7 + $index % 5);
            $booking = $roomIds[$index];
            $this->fixture('hotel_bookings', [
                'company_id' => $companyId, 'hotel_id' => $booking['hotel'],
                'reference' => sprintf('%s-RES-%05d', $prefix, $i + 1),
            ], [
                'room_id' => $booking['room'],
                'guest_display_name' => sprintf('Client Fictif %s %04d', $prefix, $i + 1),
                'arrival_date' => $start->toDateString(),
                'departure_date' => $start->copy()->addDays(2)->toDateString(),
                'status' => ['draft', 'confirmed', 'cancelled'][$i % 3],
                'created_by' => $users[$i % count($users)],
            ]);
        }

        for ($i = 1; $i <= 8; $i++) {
            $type = $i <= 4 ? 'csv' : ['pms', 'api', 'postgresql', 'kafka'][$i - 5];
            $sourceId = $this->fixture('data_source_registry', [
                'company_id' => $companyId, 'code' => "{$prefix}-DATA-{$i}",
            ], [
                'name' => "Source synthétique {$type} {$i}", 'source_type' => $type,
                'classification' => 'internal', 'status' => 'validated',
                'schema_version' => 'v1', 'purpose' => 'Jeu synthétique de validation.',
            ]);
            if ($type !== 'csv') {
                continue;
            }
            for ($b = 1; $b <= 3; $b++) {
                $lines = [];
                for ($j = 1; $j <= 30; $j++) {
                    $lines[] = sprintf('%s-%d-%d,%d', $prefix, $i * 100 + $b, $j, $j * 200 + $i);
                }
                $csv = "external_id,amount_cents\n".implode("\n", $lines)."\n";
                $batchId = $this->fixture('data_ingestion_batches', [
                    'company_id' => $companyId, 'source_id' => $sourceId,
                    'batch_key' => "{$prefix}-LOT-{$i}-{$b}",
                ], [
                    'sha256' => hash('sha256', $csv), 'expected_rows' => count($lines),
                    'status' => 'processed', 'created_by' => $adminId,
                ]);
                $batchRows = [];
                for ($j = 1; $j <= 30; $j++) {
                    $batchRows[] = [
                        'company_id' => $companyId, 'batch_id' => $batchId,
                        'external_id' => sprintf('%s-%d-%d', $prefix, $i * 100 + $b, $j),
                        'amount_cents' => $j * 200 + $i,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                DB::table('data_ingested_csv_rows')->insertOrIgnore($batchRows);
            }
        }

        for ($i = 1; $i <= 110; $i++) {
            $incidentRef = sprintf('%s-INC-%04d', $prefix, $i);
            $status = ['open', 'investigating', 'resolved', 'closed'][$i % 4];
            $resolved = in_array($status, ['resolved', 'closed'], true);
            $new = DB::table('support_incidents')->where('reference', $incidentRef)->doesntExist();
            $incidentId = $this->fixture('support_incidents', ['reference' => $incidentRef], [
                'company_id' => $companyId,
                'title' => "Incident fictif {$i} · SQL/API",
                'description' => 'Scénario de charge et support sans information réelle.',
                'severity' => ['P1', 'P2', 'P3', 'P4'][$i % 4],
                'status' => $status, 'environment' => 'demo',
                'reported_by' => $users[$i % count($users)],
                'assigned_to' => $adminId,
                'resolution' => $resolved ? 'Incident de simulation résolu.' : null,
                'resolved_at' => $resolved ? now()->subHours($i % 30) : null,
                'response_due_at' => now()->addHours(4),
                'resolution_due_at' => now()->addHours(24),
                'first_responded_at' => $status === 'open' ? null : now()->subHour(),
                'closed_at' => $status === 'closed' ? now()->subMinutes(20) : null,
                'escalation_level' => $i % 7 === 0 ? 3 : 2,
            ]);
            if ($new) {
                DB::table('support_incident_events')->insert([
                    'company_id' => $companyId, 'support_incident_id' => $incidentId,
                    'actor_id' => $adminId, 'action' => 'seeded',
                    'from_status' => null, 'to_status' => $status,
                    'message' => 'Création de scénario fictif ATLAS V5.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
