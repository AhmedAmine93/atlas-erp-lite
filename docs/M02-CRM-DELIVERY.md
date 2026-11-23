# ATLAS ONE — M02 CRM & Relations clients

## Branche de livraison
`feature/m02-crm-complete-v5` → PR #81 → `dev` (aucune publication sur `main`).

## Écrans
- Clients : ancien CRUD enrichi avec archivage non destructif et import CSV avec erreurs par ligne
- Prospects : qualification, perdu avec motif, conversion transactionnelle vers client
- Contacts : personnes, coordonnées, fonction, consentement, masquage du lecteur
- Opportunités : pipeline, montant prévisionnel, probabilité, décision
- Activités : échéances, appels/rendez-vous/tâches, clôture avec résultat
- Segments : segments statiques et dynamiques avec critères bornés et membres

## API (auth:sanctum + tenant + Gate)
- `GET/POST /api/crm/{prospects|contacts|opportunities|activities|segments}`
- `GET/PUT /api/crm/{resource}/{id}`
- `POST /api/crm/{prospects|opportunities|activities}/{id}/transition`
- `POST /api/crm/prospects/{id}/convert`
- `POST /api/crm/customers/{id}/archive`
- `POST /api/crm/customers/import`
- `GET/PUT /api/crm/segments/{id}/members`
- Ancien `GET/POST/PUT/DELETE /api/customers` maintenu pour compatibilité ; DELETE devient archivage

## PostgreSQL
Migration additive `2026_11_22_000010_create_m02_crm.php`, sans effacer les documents, factures ou clients.
Tables `crm_prospects`, `crm_contacts`, `crm_opportunities`, `crm_activities`,
`crm_segments`, `crm_segment_memberships`, `crm_import_batches`.
Séparation tenant `company_id`, FKs composites, RLS runtime et privilèges DML explicites.

## Recettes minimales bloquantes
1. Clients historiques : archivage préserve les documents associés.
2. Prospect qualifié → client ; rejeu identique n'ajoute aucun second client.
3. Contact d'un autre tenant : 422 ; lecteur : aucune écriture.
4. Étapes opportunité et activité : transitions illégales refusées, révisions concurrentes 409.
5. Segments statiques et dynamiques : aucun client d'un autre tenant.
6. CSV 9 lignes valides + 1 rejet : neuf créations, une erreur ; second import = zéro doublon.
7. PostgreSQL rôle `atlas_app_runtime` : aucune lecture d'un autre tenant en changeant `atlas.tenant_id`.
8. CI complète : PHPUnit, PostgreSQL, Playwright, builds Docker et non-régression.

## Réserves / futures améliorations
- Un vrai connecteur CRM externe, l'email sortant et le marketing automation ne font pas partie de cette livraison.
- La fusion de doublons complexes avec approbation humaine et annulation, la déduplication approximative,
  le MDM golden record multi-sources et les droits site par site exigent des lots dédiés.
- L'intégration d'opportunité avec les révisions de devis et les workflows commerciaux avancés
  reste à valider sur un parcours E2E et ne doit pas être annoncée comme achevée sans preuve.
- Ne pas fusionner sur la seule base de builds : vérifier la CI sur le SHA final et l'essai Docker local.

## Test local après validation
```powershell
git fetch origin
git switch dev
git pull --ff-only origin dev
node scripts/setup.mjs
docker compose up --build -d --wait --wait-timeout 180
docker compose ps
```
Ne jamais utiliser `docker compose down -v` pour mettre à jour le CRM.
