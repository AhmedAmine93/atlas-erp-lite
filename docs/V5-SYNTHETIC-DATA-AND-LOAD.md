# ATLAS V5 — Données PostgreSQL fictives et 120 utilisateurs simultanés

## Exécution réelle, optionnelle et isolée

MassiveDemoSeeder remplit les vraies tables PostgreSQL de trois sociétés (ATLAS_DEMO, ATLAS_SYNTH_B, ATLAS_SYNTH_C). Il ajoute 150 comptes uniques (load001@atlas.example à load150@atlas.example), clients, fournisseurs, produits, devis, commandes, factures, sites, équipements, DI/BT, hôtels/chambres/réservations, demandes d'achats, sources/batchs Data et incidents. Aucune table n'est supprimée; les clés métier fictives sont déterministes et le seeder est réexécutable sans doublons.

Volumes supplémentaires attendus sur une base initialisée : 150 comptes, 540 clients, 120 fournisseurs, 480 produits, 285 devis, 285 demandes d'achat, 27 sites, 360 équipements, 450 DI, environ 336 BT, 9 hôtels, 216 chambres, 630 réservations, 24 sources Data, 36 lots, 1080 lignes CSV et 330 incidents. Les commandes/factures/mouvements sont créés par les services ERP métier.

Ce seeder est volontairement séparé de DatabaseSeeder et ne se lance jamais au démarrage de production. Il impose APP_ENV=local/testing, PostgreSQL local, nom de base atlas ou atlas_*, mot de passe DEMO_PASSWORD >= 12 et ATLAS_DEMO_DATA_CONFIRM=YES_LOCAL_ONLY.

Depuis PowerShell, dans une copie locale et après sauvegarde de la base :

```powershell
node scripts/setup.mjs
docker compose up --build -d --wait --wait-timeout 240
docker compose exec -T -e ATLAS_DEMO_DATA_CONFIRM=YES_LOCAL_ONLY backend php artisan db:seed --class=MassiveDemoSeeder --force
```

Pour confirmer les insertions dans PostgreSQL :

```powershell
docker compose exec -T db psql -U atlas -d atlas -c "SELECT company_id, count(*) FROM hotel_bookings GROUP BY company_id ORDER BY company_id;"
docker compose exec -T db psql -U atlas -d atlas -c "SELECT count(*) FROM users WHERE email LIKE 'load%@atlas.example';"
docker compose exec -T db psql -U atlas -d atlas -c "SELECT count(*) FROM data_ingested_csv_rows;"
```

## Simulation 120 sessions simultanées

Le test Node 24 prépare 120 connexions HTTP indépendantes (cookies et société vérifiée) puis envoie 120 requêtes concurrentes par vague; chaque vague change de route métier. Tests de lecture uniquement, sans modification des enregistrements. Résultats : latence p50/p95/p99, débit, code HTTP, taux d'échec, écarts tenant, preuve JSON sous reports/.

```powershell
$env:DEMO_PASSWORD = ((Get-Content .env | Where-Object { $_ -match '^DEMO_PASSWORD=' } | Select-Object -First 1) -replace '^DEMO_PASSWORD=', '').Trim('"')
node scripts/load-120-users.mjs --users=120 --rounds=5 --base=http://127.0.0.1:3100
Remove-Item Env:\DEMO_PASSWORD
```

Le test refuse toute cible autre que localhost/127.0.0.1/::1. C'est une simulation HTTP multi-session, pas 120 navigateurs graphiques ou utilisateurs humains. Un succès sur un runner GitHub ne prouve pas que le même débit est possible sur un PC local. Ne jamais exécuter sur une base contenant des données réelles ou partagées.
