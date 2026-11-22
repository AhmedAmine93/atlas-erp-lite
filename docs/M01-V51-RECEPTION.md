# ATLAS ONE — M01 Pilotage & Direction — réception V5.1

**Branche de livraison :** \`feature/m01-pilotage-v51-finalisation\` → PR #100 → \`dev\` uniquement. **Jamais \`main\` ni production.**

Ce document rapproche la V5.1 fonctionnelle et la V5.2 proposée \`M01_REGLES_METIER_pilotage-direction.md\` du code. Les règles proposées **P** exigent encore une décision Direction/Contrôle de gestion ; aucune CI ne signe un procès-verbal métier. Toute ligne « partielle » reste **NO-GO pour M01 complet en production**.

## Matrice six sous-menus

| Écran | Couvert et testable | Non couvert / dépendance de donnée |
|---|---|---|
| M01.01 Vue d'ensemble | KPI facturation issue/payée, commandes validées, stocks par tenant, graphiques, préférences de dashboard et autorisations existants | Filtres historique site, versions de définition KPI, preuve de clôture de période |
| M01.02 Direction | Consolidation **par entreprise**, factures HT/TTC, N-1, objectif HT versionné, liste des hôtels, nuitées confirmées / théoriques, 8 nuits sur 10 = 80 % | ADR/RevPAR sans chiffre d'affaires hôtelier fiable ; réservations datées sans instantanés pickup, prévisions, chambres historiques ; délégation multi-entreprise explicite absente |
| M01.03 Commercial | Devis, commandes, factures émises et états en cohortes séparées | Entonnoir prospect→opportunité (M02 incomplet), portefeuille commercial, échéances fiables |
| M01.04 Finance | Montants HT/TTC, encours des factures émises sur la période, annulations exclues, centres de coûts **référentiels**, objectifs de gestion versionnés | Facturation sans avoirs, échéances, règlements bancaires affectés ni grand livre ⇒ pas de DSO, paiements réconciliés, intercompany ou consolidation comptable |
| M01.05 Logistique | Stock physique et nombre d'articles sous seuil à date ; mouvements de la période | Stocks réservés, ATP, quarantaine, délais de livraison nécessitent M05/M03 et événements sources |
| M01.06 Alertes | Seuil et validité par société, propriétaire membre, acquittement idempotent, escalade manuelle, résolution seulement après disparition de l'anomalie, journal append-only, audit | Moteur de fréquence automatique, règles par site, notifications et journal des épisodes récurrents non livrés |

**Ne jamais transformer un objectif en revenu ni une confirmation de réservation en encaissement.** Les colonnes de revenu hôtelier manquantes retournent \`null\` et l'UI affiche **Non disponible**, jamais 0 € fictif.

## Définitions et unités

- Toute facture \`draft\` ou \`cancelled\` est exclue du CA. Les indicateurs de facturation utilisent \`issued_at\`, pas \`created_at\`, et ne comptent pas une commande validée comme facture émise.
- Montants entiers en centimes EUR, nombre de pièces comme entiers, ratios (%) uniquement si le dénominateur existe. Pas de fusion de devises différentes.
- Le tableau de bord direction affiche le **stock actuel** de chambres référencées, pas une disponibilité historique reconstruite ; l'occupation « indicative » = nuitées de réservation (confirmée, checked_in, checked_out et départ réel si précoce), écrêtées aux jours sélectionnés, divisées par chambres **actuelles × jours de la période**. En l'absence de chambres, le ratio est \`null\`.
- Exemple synthétique : une chambre et 10 jours du 01 au 10 novembre, réservation arrivée 01 / départ 09 → 8 nuitées occupées, 10 théoriques, occupation indicative 80 %. L'ADR et le RevPAR restent \`null\` tant que le produit hôtelier ne contient pas de recettes hébergement vérifiées.
- N/N-1 = facturation sur la même fenêtre civile N-1, sans double comptage des factures annulées. Aucun calcul automatique de croissance si la base N-1 est nulle.
- Une version d'objectif correspond à une métrique et **aux dates exactes** ; une révision conserve sa version antérieure et inscrit un audit. Un objectif publié ne change pas les pièces canoniques.

## Interfaces REST

Toutes les routes sont derrière \`auth:sanctum\`, \`throttle:api\`, \`tenant\`, gestion CSRF pour les écritures et rôle au niveau entreprise via Gate.

- \`GET /api/pilotage/{direction|sales|finance|logistics|alerts}?from=YYYY-MM-DD&to=YYYY-MM-DD\` — tableaux bornés et horodatés, aucune sélection libre de tenant.
- \`GET /api/pilotage/{section}/export?... \` — CSV contrôlé et audité, échappement des formules Excel/LibreOffice, même filtre et même permission que la consultation.
- \`GET /api/pilotage/targets?from=...&to=...\` — historique versions, données financières invisibles au rôle stock.
- \`POST /api/pilotage/targets\` — admin, métrique admise, période ≤366 jours, unité déterminée serveur, motif ≥10 caractères.
- \`GET /api/pilotage/centers\` et \`POST /api/pilotage/centers\` — référentiel, création admin sans opération comptable.
- \`GET /api/pilotage/alerts/rules\`, \`POST /api/pilotage/alerts/rules\`, \`POST /api/pilotage/alerts/acknowledge\`, \`POST /api/pilotage/alerts/transition\` — lecture autorisée et actions administrateur contrôlées.
- Les anomalies de stock et facture sont deux sources distinctes. Une **acquittée** n'est pas une **résolue**. La résolution exige acquittement et disparition de l'anomalie. Deux transitions identiques avec même motif sont idempotentes ; motif différent ⇒ HTTP 409.

## PostgreSQL et sécurité

- \`pilotage_cost_centers\` référentiel unique par \`(company_id,code)\`.
- \`pilotage_target_versions\` journal de versions \`(company_id,metric,from,to,version)\`, période validée SQL, acteur et motif.
- \`pilotage_alert_events\` journal append-only des escalades/résolutions, index unique anti-rejeu, FKs composites qui empêchent de rattacher une pièce d'une autre société.
- RLS \`atlas_app_runtime\` par \`current_setting('atlas.tenant_id')\` transactionnel ; SELECT+INSERT sur ces journaux, aucune permission UPDATE/DELETE, rôle DB runtime non propriétaire.
- Les exports passent la même porte serveur que les écrans ; \`X-Atlas-Expected-Company\` protège la session active. Les alertes stock ne divulguent pas les règles de factures au rôle \`stock\`.
- Aucune migration ne supprime de données préexistantes. Le \`down\` refuse les pertes de journaux non vides. En production, corrections via migration forward après sauvegarde.

## Preuves automatiques et recette manuelle

\`\`\`sh
# Base PostgreSQL _test dédiée, jamais une base client
docker compose -f compose.yaml -f compose.ci.yaml run --rm tests \
  vendor/bin/phpunit --filter 'Pilotage'
cd frontend
npm run typecheck
npm run build
npm run test:e2e
\`\`\`

Tests \`PilotageMenuTest\`, \`PilotageV51FinalizationTest\` et \`frontend/tests/pilotage.spec.ts\` : RBAC, tenant A/B, version N→N+1, factures émises/annulées, nuitées 8/10, seuils, reprise, événements et CSV anti-formule. La CI complète exécute les mêmes images et les chemins de migration/restauration, mais **un test manuel Windows/Docker avec captures fictives et une approbation par Direction/contrôle de gestion reste nécessaire**.

## Arbitrages restants pour la réception finale du cahier intégral

P044.01 centres de coûts : référentiel livré. P044.02 budgets et engagements : objectifs versionnés livrés, engagements non rapprochés. P044.03 imputations, P044.04 pièces intercompany, P044.05 éliminations et P044.06 rapprochements : dépendent des écritures sources M06/M03, non livrés.

P135.01 chambres et nuitées : comparaison indicative livrée. P135.02 occupation partielle, ADR/RevPAR non disponibles ; P135.03 pickup/annulations historiques non disponibles ; P135.04 prévision non disponible ; P135.05 hôtel dans sa société uniquement (intercompany non délégué) ; P135.06 cas 8/10 automatisé, mais **800 € et divisions ADR/RevPAR non testables sans registre de revenus**.

**Décision :** candidate d'intégration de code \`dev\` après CI et revue, **pas** « menu métier M01 terminé à 100 % » ni déploiement production. Ne pas fabriquer de revenus hôteliers, de paiements ou de transactions intercompany dans M01 pour contourner les dépendances des autres modules.
