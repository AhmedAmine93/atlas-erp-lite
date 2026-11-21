# ATLAS ONE V5 — P050 : demandes d'intervention GMAO

**Parent PR #44** — sites et équipements. Cet incrément introduit les demandes d'intervention (DI) pour un seul site au sein de la société active.

- Table `maintenance_interventions` avec catégorie bornée, priorité, statut et demandeur authentifié.
- FK composées `(company_id,site_id)` et `(company_id,site_id,asset_id)` : empêche de relier une DI à un actif d'un autre site ou d'une autre société, même en cas de SQL direct.
- `POST /api/maintenance/interventions` crée une DI à l'état `new`, sans création de bon de travail.
- `POST /api/maintenance/interventions/{id}/transition` : `new → qualified → accepted/rejected`, avec motif et vérification sous verrou.
- `maintenance_intervention_events` : journal transactionnel, séparé de l'audit global, liant chaque transition à son acteur.
- Tous les endpoints restent réservés à l'administrateur du tenant dans cette première version. Roles technicien, superviseur et clients externes non activés.

**Restant** : catégories paramétrables P050.01, approbations métier étendues, affectations, SLA, BT P051, audit immuable, mobile et DI de chambres P136. Les scénarios de refus, statuts invalides et mélange sites/sociétés sont couverts par tests de feature.

Cette PR n'est pas une livraison GMAO complète. Pas de fusion automatique ni déploiement production.
