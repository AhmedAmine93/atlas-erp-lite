# Journal de progression — ATLAS Enterprise 360

## Règles de travail

- Version de référence locale : `C:\Users\amine\Downloads\atlas-erp-lite`.
- Préserver les refactorings locaux ; ne pas remplacer les fichiers par une ancienne version de `main`.
- Aucun commit, push, merge, pull request ou déploiement sans autorisation explicite préalable.
- Aucun ZIP. Tests ciblés pendant le développement, validation complète aux jalons.
- Aucune migration structurelle sur une base contenant des données sans accord.

## Sprint 01 — 17 novembre 2026

Départ : branche `refactor/01-login-component`, commit `5538adf`, arbre de travail propre. Les extractions Login, DashboardView, RevenueChart, Badge, Empty, ListView, EditorForm et les utilitaires partagés étaient déjà présentes. Le contrôle TypeScript initial passait.

### Modifications locales

- Extraction de `components/details/Detail.tsx` et `components/ui/Modal.tsx`.
- `Workspace.tsx` conserve l'orchestration : session, navigation actuelle, actions, notifications et ouverture des panneaux. Réduction de 824 à 358 lignes par extraction et suppression du code commenté devenu redondant.
- Contrats `View`, `DetailState`, `DocumentAction` et `DetailProps` centralisés dans `lib/erp-types.ts`. Le détail distingue maintenant client, devis/commande et facture ; les réponses API sont typées selon leur nature.
- `DashboardView` partage le type de navigation. Imports inutilisés supprimés.
- CSS, classes du DOM et contrats d'authentification du backend inchangés.
- Deux tests navigateur ciblés ajoutés : fermeture/focus clavier/historique client, puis erreur PDF et nouvelle tentative.

Le type générique `Row` existant reste utilisé par les listes et formulaires. Son remplacement par des contrats par domaine relève du Sprint 03 ; il n'a pas été étendu aux nouveaux détails.

### Validation

- `npm run typecheck` : réussi après extraction.
- `npx tsc --noEmit --noUnusedLocals --noUnusedParameters` : réussi après extraction.
- `npm run build` : réussi après extraction.
- Diff CSS vide.
- Une recompilation de fin de test a rencontré un verrou de cache Windows (`EPERM` dans `.next`). Après arrêt des services de test et suppression du seul cache généré `.next`, le build propre a réussi avec la configuration habituelle, sans URL de test persistée dans le code.
- Playwright Chromium : **4 tests réussis en 25,1 secondes**, contre le vrai backend et la version Next.js compilée : client → devis → commande → facture PDF → payée ; mobile/lecture seule ; modale/clavier/focus/historique ; erreur PDF simulée puis téléchargement réel réussi.
- `git diff --check` : réussi. Aucun changement CSS, backend ou workflow CI/CD.

Docker n'est pas disponible dans le contexte d'exécution de cette session. La validation navigateur utilise PHP 8.4.27 et PostgreSQL 17.10 natifs sur une base jetable distincte, créée vide pour ce sprint. Aucune migration n'a été appliquée à une base préexistante. Les dépendances backend sont réutilisées à partir d'une installation existante dont le `composer.lock` est strictement identique ; aucun changement de dépendance n'est effectué. Le contournement local des sondes de droits de fichiers PHP sous Windows, décrit dans TEST-REPORT.md, est conservé uniquement dans le dossier de travail externe au dépôt.

Le backend testé est celui de cette branche locale. Le frontend était servi sur `127.0.0.1:3201`, l'API sur `127.0.0.1:18201`, le port 8000 n'étant pas utilisable dans cette session. Ces réglages ne modifient pas la configuration versionnée. Le rapport HTML est dans `frontend/playwright-report/index.html` (ignoré par Git). Aucun commit, push, merge, changement de branche, pull request ou déploiement n'a été effectué pendant ce sprint. Les tests PHP complets et une reconstruction Docker de cette branche ne sont pas relancés pour cette extraction uniquement frontend ; ils restent au jalon du Sprint 04.

État : développement et validations du Sprint 01 terminés. **Commit local autorisé par l'utilisateur, sans push**, sur `refactor/01-login-component`. Message : `refactor(frontend): extract detail and modal with shared types`. Toute publication et tout nouveau commit restent soumis à une autorisation distincte.

### Point de référence CI/CD antérieur

Le [run GitHub 44067011531](https://github.com/AhmedAmine93/atlas-erp-lite/actions/runs/44067011531), au commit `ba96d73`, a réussi les tests et publié les images GHCR avant cette nouvelle mission. Il porte sur l'état antérieur aux extractions locales et ne valide pas à lui seul ce sprint. Aucune publication automatique n'est réalisée dans cette mission.

### Suite

Sprint 02 : routes Next.js et navigation navigateur. Sprint 03 : organisation par domaines et clients API. Sprint 04 : validation complète et documentation DevOps. Sprint 05 : proposition multi-entreprises, sans migration de données existantes.
