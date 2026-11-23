# M07 — GMAO & Maintenance V5.1 — état de livraison

Branche: `feature/m07-gmao-maintenance-v5-1`, créée depuis `dev`. Aucune fusion automatique.

## Périmètre développé

| Sous-menu | Couverture implémentée | Limites identifiées |
|---|---|---|
| M07.01 Pilotage | KPI DI/BT, coûts, arrêts, préventif | MTTR proxy; MTBF/SLA certifiés non disponibles |
| M07.02 Sites/localisations | Sites existants + emplacements hiérarchiques, historique déplacements | Triggers SQL anti-cycle et cohérence site ajoutés ; parcours de modification de hiérarchie à enrichir |
| M07.03 Équipements | Référentiel, immobilisation et contrôle de remise en service | Composants hiérarchiques, procédure de retrait à compléter |
| M07.04 DI | Création, qualification, refus motivé, conversion DI→BT déjà idempotente | Pièces jointes, habilitations métiers granulaires |
| M07.05 BT | Démarrage/fin préexistants, temps/main-d'œuvre, coûts, inspections | Cycle complet planifié/contrôlé/clos et BT parents/enfants |
| M07.06 Planning | Équipes, techniciens, plages anti-chevauchement existantes, UI | Compétences, indisponibilités, replanification avancée |
| M07.07 Préventif | Plans et occurrences idempotentes existants, UI | Compteurs, calendrier avancé, suspensions versionnées |
| M07.08 Pièces | Réservation sans débit, consommation unique, coûts, restitution | Lots/séries, retours multi-événements, achats |
| M07.09 Contrats | Contrats périmétrés par site, journal SLA | Calendrier publié et vrai calcul des SLA à ajouter |
| M07.10 Terrain | Journal événementiel, idempotence et contrôle de version | Vrai mode hors-ligne/PWA, signatures/photos sécurisées |

## Mesures de sécurité

- API derrière `auth:sanctum`, `tenant`, garde Laravel `admin`.
- Scopes Eloquent déjà en place sur les entités existantes.
- SQL additionnel toujours filtré par `company_id`.
- FK composites tenant pour les nouvelles relations internes et stocks.
- RLS PostgreSQL + rôle applicatif restreint + interdiction SQL DELETE.
- Triggers PL/pgSQL contre les cycles de hiérarchie d'actifs/emplacements et les références à un site incohérent.
- Clés d'idempotence persistées pour les réservations, consommations, restitutions, temps et événements terrain.
- Audit des changements métier; transactions, verrous, erreurs 409, idempotence partielle.

## Recette nécessaire avant GO

1. Lancer `bash scripts/ci.sh` sur un PostgreSQL réel, avec les tests Laravel et les builds TypeScript/Next.
2. Corriger tous les résultats rouges; vérifier les migrations up/down et le rôle `atlas_app_runtime`.
3. Vérifier deux utilisateurs de sociétés différentes, un lecteur sans droit `admin`, et deux consommations simultanées du même produit.
4. Tester les 10 parcours métier en navigateur et sur mobile, enregistrer captures et preuves.
5. Compléter les limites ci-dessus et valider les processus métier; aucun indicateur « prêt production » ne découle de la création des écrans.

**Statut: développement partiel, NO-GO production tant que la recette intégrale et les écarts ne sont pas levés.**
