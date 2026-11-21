# F04 — Assemblage du shell multi-tenant

## Base et dépendances

- Branche de base : `fix/organization-tenant-reference-validation` (PR #15), elle-même empilée sur PR #14 → #13 → #12.
- Branche historique `refactor/01-login-component` examinée, sans écrasement du shell multi-tenant actuel.
- Branche ITSM `feat/support-incidents-v1` (PR #11) **non fusionnée** : intégration métier prévue en F07–F09.

## Modifications

1. Extraire la connexion dans `frontend/src/components/auth/Login.tsx`, à partir du code modulaire existant dans `refactor/01-login-component`.
2. Extraire la navigation, la recherche et le sélecteur d'entreprise dans `frontend/src/components/layout/WorkspaceSidebar.tsx`.
3. Laisser dans `Workspace` l'état authentifié, la navigation métier, les modales et les contrôles de changement de tenant.
4. Rafraîchir le profil `/api/me` après la création d'une société afin que le sélecteur propose immédiatement la nouvelle entreprise.
5. Corriger le message obsolète de l'écran d'administration indiquant que l'isolation n'était pas active.
6. Ajouter le test navigateur `frontend/tests/shell.spec.ts` pour le parcours connexion → clients → entreprises → nouvelle entreprise → changement de tenant → tableau de bord → clients isolés.

## Garanties conservées

- La logique de permission backend Laravel reste la source d'autorité.
- Le changement de tenant passe par `POST /api/tenant/switch`, puis recharge le document pour purger les états et requêtes de l'ancien contexte.
- Les entrées futures de navigation restent désactivées.
- Les opérations API n'utilisent pas d'identifiant de société fourni par l'interface pour outrepasser les contrôles backend.
- Les tests existants sur ventes/factures et la suite multi-tenant sont conservés.

## Commandes de vérification

```sh
cd frontend
npm ci
npm run typecheck
npm run build
npm run test:e2e
```

La CI du dépôt exécute également Laravel/PostgreSQL et les contrôles HTTP depuis `bash scripts/ci.sh`.

## Limites et étapes suivantes

Le composant Workspace reste volumineux : la branche historique contient des composants Dashboard/List/Form/Detail dont la réutilisation doit être réalisée ultérieurement en tenant compte des différences fonctionnelles et multi-tenant. Ne pas cherry-picker son ancien Workspace en entier : il ne connaît ni le nouveau catalogue des menus ni le sélecteur de tenant.

**Suite :** après validation CI, poursuivre F05–F06 (fixtures et rôles par affiliation), puis F07–F09 (ITSM et isolation par société/établissement). Aucun service n'est déployé en production dans cette mission.
