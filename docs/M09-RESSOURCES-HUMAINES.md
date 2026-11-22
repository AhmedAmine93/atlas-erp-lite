# M09 — Ressources humaines — implémentation en cours

Base : `dev` au SHA `1e4fddc959b167f1c12069dab8a1b371af84b2f7` ; travail isolé dans `feat/menu-09-complet`.

## Traçabilité
- M09.01 `hr-employees` : collaborateurs, dossiers, affiliation et dates d’entrée/sortie.
- M09.02 `hr-teams` : équipes, référentiel de managers, affectations.
- M09.03 `hr-schedules` : créneaux publiables, exclusion PostgreSQL des chevauchements, contrôle congés.
- M09.04 `hr-leaves` : brouillon / soumission / décision / annulation, solde annuel verrouillé.
- M09.05 `hr-timesheets` : projet **ou** BT, cumul des minutes, états jusqu’au transfert.
- M09.06 `hr-skills` : catalogue, formations, justificatifs référencés, échéances et validation.

## Architecture
- Next.js : `frontend/src/components/HumanResourcesWorkspace.tsx`, navigation `hr-*`.
- Laravel : `backend/app/Http/Controllers/HumanResourcesController.php`, endpoints sous `/api/hr/`.
- PostgreSQL : migration `2026_11_22_000100_create_hr_domain.php`, tables RH, clés composites multi-tenant, RLS `atlas_app_runtime`, exclusions gist, événements, clés de rejeu.
- Accès serveur réservé aux membres entreprise ayant un rôle `admin` ou `hr` explicite. Ce choix réduit temporairement le périmètre : les délégations manager/salarié demandent encore définition et recette.
- **Ne pas utiliser de données RH réelles lors des tests.**

## Exécution locale
Depuis une copie du dépôt sur une base PostgreSQL de test jetable :

```bash
docker compose -f compose.yaml -f compose.ci.yaml config --quiet
bash scripts/ci.sh
```

CI attendue : construction Next.js / typecheck, migrations PostgreSQL, PHPUnit et Playwright.
Tests ciblés : `backend/tests/Feature/HumanResourcesMenuTest.php` et `frontend/tests/hr.spec.ts`.

## Limites et arbitrages non clôturés
Les règles complémentaires marquées **P** dans `M09_REGLES_METIER_ressources-humaines.md` exigent un arbitrage métier avant validation. Ne pas confondre écrans navigables et recette signée.

- Historique des remplacements de managers, délégations manager/salarié et permissions site fines non finalisés.
- Calcul automatique des congés, jours ouvrés et règles légales par pays non configurés ; `days` doit être saisi par RH et les soldes préalablement dotés.
- Contrôle prospectif des compétences obligatoires sur les plannings, capacité multi-équipe et intégration projets/GMAO non finalisés.
- Export sécurisé, taux de coût historiques et corrections de temps transféré non finalisés.
- Tests de concurrence réelle sous deux connexions, restauration, E2E complet et validation métier à confirmer par la CI et procès-verbal.
- Pas de fusion `dev` sans recette, revue sécurité et CI verte.

## Critères avant levée du brouillon
1. CI verte au dernier SHA, vérification de la migration sur PostgreSQL et de son rollback.
2. Recette de chaque sous-menu, décisions sur règles P, droits manager/salarié et preuve du tenant A/B.
3. Test de collision congé/planning, auto-approbation, certifications expirées et double imputation.
4. Revue conformité des données RH et exports.
