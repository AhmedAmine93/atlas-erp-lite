# ATLAS ONE — Oracle JET / Node.js / Redis / PostgreSQL industriel V5.2

**Profil LOCAL de développement. Pas de production ni de port exposé sur Internet.**
Branche feature/jet-industrial-node-redis-pg-rules-v2, PR #101 vers dev.

## Architecture

Navigateur Oracle JET 21 (port 8100) → passerelle locale Node (8100).
La passerelle conserve l’API historique /api/* vers Next.js/Laravel 3100 et ajoute la route /api/industrial-jet/* vers le nouveau BFF Node 8300.
Node 8300 → /api/me (session Laravel et entreprise vérifiées À CHAQUE requête) → /api/industrial/* Laravel → fonctions PL/pgSQL PostgreSQL 17, RLS, tables métier et audit.
Node 8300 → Redis dédié au cache court (TTL 10 secondes), versions par entreprise, invalidation avec PUBLISH après écriture.

**PostgreSQL ne supporte pas les PACKAGE Oracle.** Les règles métier transactionnelles sont des fonctions PL/pgSQL dans le schéma atlas_industrial ; aucun Oracle Database n’est connecté et aucune donnée n’est dupliquée dans Redis. L'API Node ne possède **aucun identifiant PostgreSQL** et ne peut pas exécuter de requête SQL.

L’API Node refuse le rôle non-admin, les entreprises incorrectes, les routes arbitraires, les corps de mutation sans CSRF, les corps JSON invalides et les paramètres de tenant injectés. Redis ne contient jamais de cookie/session et chaque HIT cache requiert préalablement la validation Laravel.

## Navigation — quatre groupes, treize sous-menus

| Groupe | Sous-menus |
|---|---|
| Pilotage industriel | Cockpit & supervision, Vue générale, Rapports GMAO |
| Parc et interventions | Sites et équipements, Demandes d'intervention, Bons de travail |
| Exploitation & fiabilité | Compteurs & relevés, Modèles de contrôle, Inspections & conformité, Règles SQL métier |
| Planification et préventif | Techniciens & équipes, Planning technique, Maintenance préventive |

Les huit espaces existants restent. Les cinq nouveaux écrans sont connectés aux API, avec formulaires, listes et documentation des règles SQL. L’écran Règles SQL métier décrit les invariants mais n’est pas un éditeur SQL.

## Invariants métier

- IND-01 : tenant et rôle vérifiés dans Laravel, RLS et FKs composites PostgreSQL.
- IND-02 : compteur monotone par équipement et type, verrouillage de ligne sur l'équipement, valeur non négative.
- IND-03 : même clé idempotente / même valeur = même relevé, changement de valeur = HTTP 409.
- IND-04 : contrôle uniquement sur modèle actif appartenant à la même société que l'équipement.
- IND-05 : inspection non conforme = une DI qualifiée et un BT correctif créés atomiquement dans la transaction Laravel.
- IND-06 : journaux de mesures et inspections avec INSERT/SELECT seulement au rôle runtime, jamais UPDATE/DELETE.
- IND-07 : Redis cache ne confère pas d’autorisation, chaque requête revalide la session; TTL 10 s; PUBLISH informatif, non fiable pour livraison durable.
- IND-08 : mutation auditée par Laravel.

## Démarrage CMD Windows — sur dev après fusion

Terminal 1 (ERP, Redis et API Node) :

    cd C:\Users\amine\Downloads\atlas-erp-lite
    git switch dev
    git pull origin dev
    node scripts/setup.mjs
    docker compose -f compose.yaml -f compose.jet.yaml up -d --build --wait

Terminal 2 (Oracle JET sur 8200, puisque le 8000 est occupé par Laravel Docker) :

    cd C:\Users\amine\Downloads\atlas-erp-lite
    node jet-industrial/scripts/bootstrap.mjs
    cd jet-industrial\atlas-jet-gmao
    npx @oracle/ojet-cli@21.0.0 serve --server-port=8200

Terminal 3 (passerelle locale) :

    cd C:\Users\amine\Downloads\atlas-erp-lite
    set ATLAS_JET_PORT=8200
    set ATLAS_INDUSTRIAL_NODE_PORT=8300
    node jet-industrial/scripts/proxy.mjs

Se connecter sur http://127.0.0.1:3100 avec un administrateur fictif puis ouvrir http://127.0.0.1:8100. Utiliser le même nom d'hôte, pas localhost dans l’un et 127.0.0.1 dans l’autre.

L’API de santé Node sur http://127.0.0.1:8300/health expose seulement le statut, sans données métier. Ne pas la rendre publique.

## Tests

    npm --prefix jet-industrial/node-api install --no-audit --no-fund
    npm --prefix jet-industrial/node-api test
    node --test jet-industrial/tests/gateway.test.mjs
    node --experimental-transform-types --test jet-industrial/tests/api-context.test.mjs
    node jet-industrial/scripts/bootstrap.mjs
    cd jet-industrial/atlas-jet-gmao
    npx @oracle/ojet-cli@21.0.0 build

La CI principale exécute PHPUnit sur PostgreSQL réel avec migration, contrôle d'idempotence, non-conformité, tenant A/B et RLS. Les tests Node couvrent auth sur HIT cache, erreurs CSRF, invalidation et panne Redis.

## Limites et prérequis production

Profil de développement uniquement : pas de SSO/OIDC, pas de rôle technicien fin, pas de Redis Sentinel/Cluster/ACL/TLS, pas de mTLS entre services, pas de tests de charge, pas de versionnement formel des modèles, pas de supervision des événements Redis. Les PUBLISH Redis n'assurent aucune livraison durable. Il faudra des secrets managés, une identité DB runtime non propriétaire, Redis/Kafka managés ou sécurisé TLS, CI E2E navigateur, schémas OpenAPI, migration de restauration, SLA, sauvegarde et recette industrielle signée avant toute production.

Le but de ce lot est un vertical fonctionnel et testable, pas de remplacer les 18 modules ni de simuler un moteur Oracle PL/SQL.
