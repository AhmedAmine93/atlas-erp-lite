# ATLAS ONE V5 — P066.06 : qualification du portail Next.js

**Source :** cahier V5, programme I, P066.06 — *Compléter les tests E2E, SSR, responsive et multi-navigateurs après fusion de la pile IHM*. **Parent** : P066.05 PR #38, non fusionnée ; cette PR est donc une qualification **sur pile de branches candidate**, et non une certification de `main` ni de staging.

## Couverture du lot

- **SSR et URL directe** : contrôle HTTP 200 sur `/workspace/customers` avant connexion ; présence de la page de connexion avec identité et métadonnées, et absence de données CRM de démonstration dans le HTML initial.
- **Navigation clavier / accessibilité** : lien d'évitement visible au focus et cible principale `#atlas-main`, activation Enter ; contrôles de champs de connexion (type mot de passe, étiquettes, tabulation).
- **Responsive** : vérification de l'absence de débordement horizontal sur écrans 360, 390, 768 et 1280 px (connexion publique) et 390, 768 et 1280 px (espace authentifié Lecteur). Les tests existants continuent de couvrir le menu mobile.
- **Moteurs de navigateur** : Chromium garde la suite complète (métier, support, RBAC, tenant et expérience). Firefox et WebKit exécutent une **suite de compatibilité limitée**, dans `playwright.compat.config.ts`, sans connexion ni écriture de données, pour ne pas déclencher le throttle sur les mêmes comptes.
- **CI** : le script `scripts/ci.sh` installe les trois moteurs et exige la réussite de la suite Chromium puis de la suite Firefox/WebKit sur le SHA exact. Les preuves Playwright sont conservées.

## Critères d'acceptation

- [ ] `npm run build`, typecheck et API HTTP passent sur la même révision.
- [ ] Toutes les suites Chromium antérieures restent vertes, notamment les chemins profonds P066.02, la recherche P066.03, les rôles P066.04 et les deux onglets P066.05.
- [ ] Tests Firefox + WebKit : login non authentifié, lien profond, clavier et quatre largeurs sans défilement horizontal.
- [ ] Lecteur authentifié : aucune action de modification, lien d'évitement clavier fonctionnel et 390/768/1280 sans débordement.
- [ ] Revue manuelle de l'accessibilité (WCAG/EN 301 549 applicable), lecteurs d'écran, zoom 200 %, préférences de réduction des animations, Safari iOS/Android physiques et flux métier sur appareils.
- [ ] Qualification **après** consolidation des PR empilées #24–#38, sur staging public via HTTPS avec comptes fictifs et scénarios A/B.

## Limites / non-objectifs

Les moteurs Firefox et WebKit sont testés uniquement sur les parcours publics essentiels ; leurs parcours ERP authentifiés complets et les appareils physiques **restent à qualifier**. Des tests de sémantique clavier ne constituent ni un audit WCAG complet, ni une homologation de sécurité. Le rendu serveur de `Workspace` est une coquille de chargement avant vérification de session côté client : cette recette vérifie **la sécurité du HTML initial**, pas un SSR des données métier. Aucun module non implémenté n'est activé.

**NO-GO** : aucune fusion ni déploiement automatique, ni déclaration « interface complète », jusqu'aux preuves de CI, consolidation, revues et PV release.
