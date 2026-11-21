# ATLAS ONE V5 — P066.05 : invalidation du contexte société

**Références normatives :** P066.05 (extension du reset aux caches, requêtes tardives, exports et autres clients) ; P018.02/.05/.06 ; B01 ; S03.  
**Branche parent :** PR #37 P066.04. **Ne pas fusionner automatiquement dans main ni déployer en production.**

## Écart constaté

L'IHM initiale rechargeait uniquement l'onglet dans lequel l'utilisateur changeait de société. Les autres onglets partageaient la même session Laravel mais conservaient leurs listes, fiches, modales et requêtes API en cours, pouvant entraîner une lecture ou une écriture adressée au nouveau tenant avec les anciennes intentions utilisateur.

## Décisions d'implémentation

1. **Garde serveur facultatif et rétrocompatible** : le portail Next.js joint `X-Atlas-Expected-Company` aux appels API métier après chargement de `/api/me`. Le middleware `tenant` compare avec l'entreprise réellement autorisée en session. En cas de divergence, réponse **409** et en-tête `X-Atlas-Context-Stale: 1`, avant les contrôleurs métier et **avant toute écriture**. Les endpoints `me`, `logout` et `tenant/switch` restent accessibles pour réconciliation (mais continuent à authentifier et à contrôler les affiliations). Les clients API historiques sans en-tête restent compatibles — une protection stricte côté serveur demandera des contrats clients versionnés.
2. **Réponses périmées rejetées** : `frontend/src/lib/api.ts` maintient uniquement en mémoire l'entreprise vérifiée et un compteur de génération. Toute réponse d'une requête commencée dans un ancien contexte est rejetée, y compris après une résolution tardive du CSRF ou du corps HTTP. Les requêtes utilisent `cache: no-store` comme auparavant. Le **téléchargement binaire des factures PDF** passe maintenant par `fetchTenantBlob`, transmet l'en-tête de contexte et vérifie la génération après `response.blob()` avant de créer un lien de téléchargement.
3. **Onglets cohérents** : après switch ou déconnexion confirmés, l'onglet annonce une révision aléatoire via l'événement `storage` (jamais d'identifiant, token ou donnée métier dans localStorage). Les autres onglets invalident immédiatement leurs vues, effacent les modales et URL de détail puis rechargent un `/workspace/dashboard` neuf.
4. **Retour sur un onglet** : `focus` et `visibilitychange` couvrent les navigateurs bloquant localStorage et les sessions modifiées par d'autres clients. L'interface passe temporairement en écran de chargement pendant `/api/me`. Un tenant, rôle ou utilisateur différent provoque un reset complet.
5. **Espace de préférences** : l'existant Dashboard Builder isole déjà ses préférences par utilisateur, société et rôle ; cette PR n'efface pas les préférences des autres tenants mais évite que leur état soit affiché pendant une transition.

## Tests et définition de terminé

- [ ] Backend : société A puis switch vers B avec l'en-tête « A » : `/api/customers`, `/api/dashboard` et `POST /api/customers` renvoient 409 ; aucune écriture n'est créée dans B.
- [ ] Backend : `/api/me` permet la réconciliation et le même endpoint métier avec en-tête « B » fonctionne.
- [ ] PDF : ancienne société refusée par le middleware en 409, aucun blob ancien contexte délivré au client.
- [ ] Backend : en-tête invalide (zéro, texte, débordement, négatif) refusé.
- [ ] Navigateur : utilisateur connecté, entreprise de démo B créée, deux onglets avec vue A ; bascule vers B dans l'un, l'autre revient au dashboard B sans afficher de client A ; bascule inverse fonctionne.
- [ ] CI exact SHA : migrations PostgreSQL, tests PHP, API HTTP et Playwright.
- [ ] Recette supplémentaire à planifier : téléchargement PDF interrompu au cours d'un changement de tenant en test de latence ; exports, workers, caches Redis et moteurs de recherche ajoutés dans des lots ultérieurs.

## Limites / non-objectifs

Ce correctif protège les interactions du portail et les requêtes portant explicitement l'en-tête de contexte. **Il ne garantit pas** un contrôle de toutes les sessions simultanées, ne rend pas le contexte SQL RLS inviolable et ne gère pas encore les travaux asynchrones, fichiers déjà téléchargés, clients natifs ou exportations futures. Le tenant reste défini côté serveur à partir de la session et des affiliations ; les identifiants fournis par l'IHM ne sont jamais un droit d'accès.

La PR doit rester **DRAFT**, sans fusion dans `main` ni déploiement, jusqu'à revue, CI, recette manuelle et consolidation de la pile. **NO-GO prod** avant contrôles S/L et P018/P019/P024.
