<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SalesService;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = config('atlas.demo_password');
        if (! $password || strlen($password) < 12) {
            throw new \RuntimeException('Définissez DEMO_PASSWORD (12 caractères minimum) pour les comptes fictifs.');
        }
        if (User::where('email', 'admin@atlas.example')->exists()) {
            $this->command?->info('Données démo déjà présentes : aucun changement.');

            return;
        }
        DB::transaction(function () use ($password) {
            foreach (['admin' => 'Amine · Administrateur', 'commercial' => 'Camille · Commercial', 'stock' => 'Samir · Stock', 'reader' => 'Alex · Consultation'] as $role => $name) {
                $u = User::create(['name' => $name, 'email' => $role.'@atlas.example', 'password' => $password, 'role_id' => Role::where('name', $role)->value('id')]);
                $tenant = Company::firstOrCreate(['code' => 'ATLAS_DEMO'], ['name' => 'Atelier & Co. (démonstration)']);
                $u->companies()->syncWithoutDetaching([$tenant->id => ['role_id' => Role::where('name', $role)->value('id')]]);
            }
            Auth::login(User::where('email', 'admin@atlas.example')->firstOrFail());
            foreach (['Maison Juniper', 'Studio Horizon', 'Atelier Noma', 'Collectif Rivage', 'Bureau Sillage', 'Forma Architecture'] as $i => $name) {
                Customer::create(['name' => $name, 'email' => 'contact'.($i + 1).'@client.example', 'phone' => '01000000'.str_pad($i, 2, '0', STR_PAD_LEFT), 'address' => ($i + 1).' rue de la Démonstration, 69002 Lyon']);
            }
            Supplier::create(['name' => 'Manufacture des Alpes', 'email' => 'bonjour@manufacture.example', 'address' => '20 avenue du Mobilier, Grenoble']);
            Supplier::create(['name' => 'Nord Équipement', 'email' => 'contact@nord.example', 'address' => '15 rue des Ateliers, Lille']);
            $catalog = [['BUR-001', 'Bureau chêne naturel', 'Mobilier', 34900, 30], ['CHA-002', 'Chaise de travail Alto', 'Assises', 18900, 45], ['LAM-003', 'Lampe de bureau Arco', 'Éclairage', 7900, 8], ['RAN-004', 'Caisson mobile Studio', 'Rangement', 12900, 18], ['ACC-005', 'Support écran en bois', 'Accessoires', 4900, 4], ['TAB-006', 'Table de réunion Forma', 'Mobilier', 79900, 6], ['CHA-007', 'Fauteuil lounge Oslo', 'Assises', 45900, 12], ['ACC-008', 'Organiseur de bureau', 'Accessoires', 2900, 3]];
            foreach ($catalog as [$ref,$name,$category,$price,$stock]) {
                $p = Product::create(['reference' => $ref, 'name' => $name, 'category' => $category, 'price_cents' => $price, 'vat_bps' => 2000, 'alert_threshold' => 5]);
                app(StockService::class)->adjust($p->id, $stock, 'Stock initial de démonstration');
            }
            foreach ([0, 1, 2, 3, 4, 5] as $i) {
                $q = app(SalesService::class)->create('quotes', ['customer_id' => $i + 1, 'items' => [['product_id' => 1 + ($i % 4), 'quantity' => 1 + ($i % 3)]]]);
                if ($i === 5) {
                    continue;
                }
                $o = app(SalesService::class)->convert($q->id);
                app(SalesService::class)->validateOrder($o->id);
                $inv = app(InvoiceService::class)->create($o->id);
                app(InvoiceService::class)->transition($inv->id, 'issued');
                if ($i < 3) {
                    app(InvoiceService::class)->transition($inv->id, 'paid');
                }
                $day = now()->subDays($i * 2);
                $o->update(['validated_at' => $day, 'created_at' => $day]);
                $inv->update(['issued_at' => $day, 'created_at' => $day]);
            }
            Auth::logout();
        });
    }
}
