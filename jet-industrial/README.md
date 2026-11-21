# ATLAS ONE V5 — Oracle JET GMAO : premier lot P067 / P068

Le module industriel est une vraie application Oracle JET Virtual DOM 21 générée via l'outil officiel Oracle, et non une imitation visuelle de JET. Le dépôt versionne les écrans ATLAS à appliquer au template officiel ; le contenu généré et les dépendances sont ignorés par Git.

## Périmètre livré dans cette PR

| Phase | Résultat |
| --- | --- |
| P067.01 | Générateur officiel Oracle JET 21 (VDOM basic) et surcouche ATLAS |
| P067.02 | Partiel : réemploi local du cookie de session Laravel via passerelle même origine, sans OIDC/SSO de production |
| P067.03 | Partiel : endpoints /api/maintenance/* actuels, client typé, contrôle tenant ; contrat /api/v1 futur |
| P067.04 | Arbre site → équipements, tableaux DI et BT |
| P067.05 | Création de sites, équipements, DI ; qualification, BT et clôture avec validation et audit serveur |
| P067.06 | Pipeline séparé avec vrai build Oracle JET ; tests de gateway et politique de version |
| P068.01–03 | Premier parcours GMAO ; planning et ordonnancement non implémentés |
| P068.04–06 | Rapports, dashboards industriels avancés, E2E navigateur et mobile restent à faire |

## Installation et démarrage sous PowerShell

Depuis la racine de atlas-erp-lite, après récupération de la branche de cette PR et avec Node.js LTS disponible :

1. Générer le template Oracle officiel et appliquer les écrans : node jet-industrial/scripts/bootstrap.mjs
2. Lancer l'ERP existant : docker compose up --build -d --wait
3. Dans un autre terminal : cd jet-industrial/app ; npx @oracle/ojet-cli@21.0.0 serve
4. Dans un troisième terminal depuis la racine : node jet-industrial/scripts/proxy.mjs
5. Se connecter avec un admin de test sur http://127.0.0.1:3100 puis ouvrir http://127.0.0.1:8100

Utiliser exclusivement 127.0.0.1 pour les deux applications, car les cookies partagés sont liés à l'hôte (pas au port). Le gateway :8100 est limité au loopback ; /api/* est transféré à Next.js :3100, le reste à Oracle JET :8000. Pas de modification CORS ni de secrets committés.

## Tests

Contrôle de la passerelle : node --test jet-industrial/tests/gateway.test.mjs
Vrai build JET : cd jet-industrial/app ; npx @oracle/ojet-cli@21.0.0 build

La CI crée le squelette officiel par téléchargement NPM puis compile la surcouche. Ce lot ne prouve pas une intégration SSO OIDC, la sécurité d'un gateway exposé à Internet, ni un rendu vérifié sur tous les navigateurs. Le rôle admin reste requis côté Laravel pour toute opération GMAO.

Ne jamais ouvrir le proxy local sur une interface publique ; il ne possède ni TLS ni authentification de passerelle indépendante. Production : configurer une passerelle/BFF sécurisée, des cookies Secure, le SSO OIDC, les permissions terrain et les tests E2E dédiés avant d'activer ce module publiquement.
