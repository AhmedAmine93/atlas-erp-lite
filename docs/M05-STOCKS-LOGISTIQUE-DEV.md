# M05 — Développement sur branche dédiée

**Base initiale :** dev@4a9f5e27d46a156465c523c069dd85a76005b94b.

## État de livraison

- M05.01 (produits) : code Lite existant, à faire évoluer sans casser l'historique commandes.
- M05.02 (mouvements) : journal Lite existant, sans allocation par emplacement.
- M05.03 (dépôts/emplacements) : premier incrément full-stack. Tables, FK tenant composite PostgreSQL, RLS runtime, lecture RBAC, mutation, audit, API et tests ajoutés. **Ne pas activer ready tant que la recette PostgreSQL et l'UI ne sont pas validées.**
- M05.04 à M05.07 : en attente de leur ledger transactionnel commun, non déclarés livrés.

## Risque prioritaire

Le solde actuel est dans products.stock. Introduire des soldes par emplacement sans reprise contrôlée des existants ni adaptation de la validation des commandes créerait une double source de vérité. Il faut exécuter un backfill audité et adapter StockService et SalesService de façon atomique avant d'annoncer un inventaire ou un transfert de stock opérationnel.

## Scénarios M05.03 à valider

1. Création entrepôt + emplacement normal par rôle stock.
2. Tentative de mutation par reader ou commercial refusée 403.
3. Un emplacement d'une société A ne peut être lié au dépôt de B : 404 côté API et FK composite en PostgreSQL.
4. Deux codes identiques dans le même entrepôt sont refusés ; mêmes codes dans deux dépôts différents sont acceptés.
5. Un dépôt avec emplacement actif ne peut être désactivé ; un dépôt inactif ne reçoit plus d'emplacements.
6. Zone réglementée : création réservée au rôle administrateur du tenant.
7. Vérifier les droits réels PostgreSQL du compte atlas_app_runtime, la RLS avec tenant GUC et l'absence de DELETE.

## Vérification locale

Depuis une instance PostgreSQL dédiée et jamais sur la base de production :

- composer install (dans backend)
- php artisan migrate --force
- php artisan test --filter=InventoryLocationTest
- php artisan test
- cd frontend && npm ci && npm run lint && npm run build

Ne pas affirmer que ces commandes ont réussi sans leurs logs de sortie.
