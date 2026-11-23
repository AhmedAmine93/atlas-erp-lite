# M08 — Hôtellerie & PMS : état de réalisation V5.1/V5.2

**Branche :** `feature/m08-hotellerie-pms-v5-1-complet` basée sur `dev` (SHA initial `1e4fddc959b167f1c12069dab8a1b371af84b2f7`).
**Documents d'entrée :** `M08_hotellerie-pms.md` et `M08_REGLES_METIER_hotellerie-pms.md` du pack fourni, avec les règles transversales.
**Attention :** code ajouté ≠ recette et déploiement validés. Les décisions métier sur les règles marquées « P » restent ouvertes.

## Matrice de couverture — constat et livraison

| Sous-menu | État préexistant sur dev | Modifications de cette branche | Reste à valider |
|---|---|---|---|
| M08.01 Établissements | Hôtel, code tenant, fuseau, API de création | Versions datées du calendrier et heure de coupure | Édition contrôlée et validation métier |
| M08.02 Chambres | Référentiel, état physique et blocages | Réutilisation des contraintes tenant | Audit de toutes les transitions, étage/types |
| M08.03 Réservations | Brouillon/confirmation, exclusion GiST, annulation | Intégration en lecture dans folios et audits | Garanties, versions optimistes, modifications |
| M08.04 Disponibilités | Inventaire local, chevauchement [) | Aucun moteur tiers ajouté | Options expirées, tests d'externalités |
| M08.05 Arrivées/départs | Check-in/out et historique | Lecture dans snapshot | Validation identité/garanties selon hôtel |
| M08.06 Housekeeping | Missions, inspection, état propre distinct de maintenance | Conservation des fonctions présentes | Assignations, transitions détaillées, application mobile |
| M08.07 Mini-bar | Produits, prix historiques, consommation, comparaison PMS fictive | Trigger SQL interdisant saisie dans un jour clôturé | Régularisations tardives et workflow d'export PMS |
| M08.08 Folios | Absent | Tables, API, écrans, écritures immuables, solde calculé, transfert équilibré, correction append-only, fermeture et audit | Pièces comptables définitives et conformité fiscale |
| M08.09 Canaux | Absent (hors imports minibar) | Règles de référence versionnées, imports fictifs, écarts, ambiguïtés, écran | Connecteurs vrais, webhooks, accusés, corrections manuelles |
| M08.10 Night audit | Absent | Calendrier versionné, snapshot par journée et par hôtel, verrou, écran, SQL anti-écriture tardive | Reprise externe, journal des corrections et recette night auditor |

## API nouvelles

- `GET/POST /api/hotels/{hotel}/calendar/versions` — calendrier métier (timezone + cutoff).
- `GET/POST /api/hotels/{hotel}/folios` — folios attachés à une réservation locale.
- `GET /api/hotels/{hotel}/folios/{folio}` — lignes + solde dérivé.
- `POST /api/hotels/{hotel}/folios/{folio}/entries` — écriture sous clé UUID idempotente, montant positif en centimes, motif et journée métier.
- `POST /api/hotels/{hotel}/folios/{folio}/close` — fermeture tracée.
- `POST /api/hotels/{hotel}/folios/{folio}/transfer` — deux écritures équilibrées entre folios du même séjour.
- `POST /api/hotels/{hotel}/folios/{folio}/entries/{entry}/correct` — contre-écriture dupliquée ni effacée, sur journée ouverte.
- `GET/POST /api/hotels/{hotel}/channel/rules` — normalisation versionnée par hôtel.
- `GET/POST /api/hotels/{hotel}/channel/imports` — imports fictifs, lignes et références originales conservées.
- `GET /api/hotels/{hotel}/channel/reconciliation?pms_import_id=…&crs_import_id=…` — résultats locaux, ambiguïtés et écarts sans écriture automatique.
- `GET/POST /api/hotels/{hotel}/night-audits` — inventaire figé et anomalies non masquées.

Toutes les API utilisent la session existante, la société active serveur, le contrôle `Gate::authorize('admin')`, les contraintes SQL et la politique RLS de `atlas_app_runtime`.

## Règles majeures

- Les montants de folio sont des centimes entiers. Solde = charges + transferts entrants − avoirs − règlements − transferts sortants.
- Les lignes facturables restent immuables après insertion, et après clôture aucune écriture silencieuse n'est permise pour la date fermée.
- Les imports PMS/CRS sont **fictifs** : `synced_live=false`. Les enregistrements CRS/PMS ne peuvent pas annuler directement une réservation.
- Un écart de 5 EUR est une **anomalie à examiner**, jamais la preuve d'un petit-déjeuner, ni une rectification automatique.
- Les références brutes sont conservées avec leur règle de normalisation `reference_rule_id`.
- Les modifications des fuseaux/coupures sont datées ; la clôture stocke la version appliquée, pas une recomputation prospective.
- Le snapshot conserve les réservations par statut, les écritures en centimes et le total mini-bar ; le rapprochement externe non vérifié reste signalé.

## Vérification

Lancer dans un environnement isolé, avec PostgreSQL réel et rôle de runtime restreint :

```sh
bash scripts/ci.sh
cd backend && php artisan test --filter=HotelOperationsTest
cd frontend && npm run build
```

Les tests nouveaux visent les rejouements folio et import, les transferts équilibrés, les corrections après clôture, l'écart PMS/CRS, la séparation des tenants, les permissions lecture seule et l'unicité de clôture. Une suite Playwright parcourt les dix pages depuis la navigation. La CI générale doit aussi exécuter la concurrence, le TypeScript et les E2E.

**Ne pas lancer de migration ni de seed sur une base de production sans sauvegarde et validation du schéma cible.** Le rollback de la migration est destructeur pour les tables nouvelles ; le traiter uniquement en environnement éphémère. Pour une production contenant des données, privilégier la migration corrective.

**Statut : livraison technique partielle, recette globale M08 non terminée.** Ne pas approuver de fusion ou déclarer 10/10 validés avant tests et arbitrage métier sur les règles proposées.
