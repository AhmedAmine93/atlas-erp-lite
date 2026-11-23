# M13 — BI & Reporting V5.1 / règles V5.2

## Traçabilité et portée

Branche dédiée `feature/m13-bi-reporting-v5-2`, PR cible `dev`.
Sources : `M13_bi-reporting.md` et `M13_REGLES_METIER_bi-reporting.md`, contrats B04/B09/B10/B18.
Le périmètre de la PR couvre les six routes et les nouveaux objets de BI. Les propositions V5.2 doivent encore recevoir une validation métier formelle.

| Sous-menu | API | Livraison de code | Limitation explicitement signalée |
|---|---|---|---|
| M13.01 `analytics-sales` | GET `/api/analytics/sales` | KPI distincts devis/commandes/factures | Ne constitue pas une conversion analytique avancée ni un historique dimensionnel |
| M13.02 `analytics-finance` | GET `/api/analytics/finance` | Emis, payé déclaré, dû déclaré | Avoirs, GL, flux bancaires, cash et marges non disponibles |
| M13.03 `analytics-stock` | GET `/api/analytics/stock` | Soldes courants, seuils, mouvements | Reconstruction du stock historique impossible sans ouverture versionnée |
| M13.04 `analytics-suppliers` | GET `/api/analytics/suppliers` | Référentiel restreint, sans contacts privés | Délais de livraison, acceptation, conformité non disponibles |
| M13.05 `analytics-custom` | `/api/analytics/catalog`, `/api/analytics/reports`, POST `/api/analytics/reports/{id}/run` | Champs allowlist, définitions append-only, versions, exécutions auditées | Aucun SQL libre, aucune jointure transversale, limité à 200 lignes |
| M13.06 `analytics-exports` | `/api/analytics/exports`, GET `/api/analytics/exports/{id}/download` | Snapshot JSON, CSV, hash SHA-256, expiration 1 h et contrôle récurrent d'accès | Exports programmés et gros volumes non implémentés |

## Contrats de calcul et de sécurité

- Périmètre : `company_id` résolu depuis la session, jamais depuis une demande API.
- Tous les contrôles sont réappliqués côté Laravel ; sélection de l’entreprise contrôlée par le middleware. La barrière PostgreSQL utilise la GUC transactionnelle `atlas.tenant_id` et le compte restreint `atlas_app_runtime`.
- Les nouveaux objets sont isolés par `company_id` et protégés par RLS. `bi_report_definitions`, `bi_report_runs` et `bi_report_exports` sont append-only pour le rôle runtime. L'accès aux rapports personnalisés et aux exports est réservé aux administrateurs tenant pour ce premier lot.
- Pas de lecture inter-entreprises. Pas d’accès à l'e-mail des fournisseurs par la BI, même si les contacts sont visibles ailleurs.
- Les factures `issued` et `paid` sont prises comme source de facturation, les commandes validées comme autre cohorte : elles ne sont jamais sommées ensemble comme revenu.
- `paid` est un marqueur déclaratif en l'absence de pièces de paiement canonique. Tous les agrégats impossibles sont `null` et accompagnés d'une explication.
- Les rapports personnalisés ne sélectionnent que des colonnes explicitement publiées. Les filtres ne peuvent être que `from`/`to`.
- Chaque modification d'une définition crée une nouvelle version ; aucune modification/suppression rétrospective des versions.
- Les exports sont de portée tenant, demandeur, période et durée de vie. Le téléchargement vérifie droits courants, expiration et empreinte ; les champs CSV débutant par des préfixes de formule sont neutralisés.
- Limites fixées pour cette première implémentation, à revalider avec le data owner : 366 jours par requête, 200 lignes détaillées maximum, expiration export 1 h. Pas de consolidation multi-établissements car les pièces de vente actuelles ne sont pas attribuées à un établissement.

## Commandes de qualification à exécuter sur PostgreSQL

```bash
# Utiliser uniquement une base jetable de test ou un environnement isolé.
cd backend
php artisan migrate --force
php artisan test --filter=BiReportingTest
php artisan route:list --path=api/analytics

cd ../frontend
npm ci
npm run typecheck
npm run build
# Après création du compte E2E fictif par la procédure CI :
npx playwright test tests/bi-reporting.spec.ts
```

Pour la recette intégrée / environnement Windows-Docker, exécuter aussi `bash scripts/ci.sh` dans l’environnement CI isolé prévu par le dépôt. Ne pas exécuter `down -v`, `migrate:fresh`, un effacement de données client ou un déploiement automatique.

## Critères de sortie / réserves

- Tests unitaires/API tenant A/B, permissions, idempotence, contrôles SQL, intégrité et expiration : *sources de tests ajoutées, résultats CI à documenter*.
- Tests Next.js/Playwright, sécurité du compte runtime et restauration migration : *non attestés par cette note*.
- Les règles de qualité des réceptions fournisseurs, des avoirs, des encaissements, des coûts historiques, de la marge, des écritures comptables, des exports volumineux ou programmés et des performances de requêtes ne sont **pas** livrées comme fonctionnalités complètes.
- Aucune décision Go n'est déduite de `ready: true`. PR en brouillon, non-fusionnable tant que les vérifications et la validation métier ne sont pas concluantes.
