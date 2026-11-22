# M16 — IA & Assistant ATLAS (V5.1 / règles V5.2)

## Périmètre de cette branche

Base : `dev@4a9f5e27d46a156465c523c069dd85a76005b94b`.
Branche : `feat/m16-ai-assistant-atlas-v5`.
Contrats de référence : B01, B12, B18 ; P107, P109–P112 ; RM-M16.01–05.

**Attention : ceci est une première réalisation fonctionnelle sécurisée et non une certification de livraison complète du cahier V5.1.** Les modèles externes, le vrai RAG/vector store, l'entraînement, le backtest automatisé, les agents multi-domaines et les échanges MLOps ne sont pas implémentés ; l'interface ne les présente pas comme fonctionnels. Le statut Go/No-Go est **NO-GO production** en attendant les validations S/L, une recette Windows/Docker et les décisions métier sur les règles P.

## Sous-menus, flux et API HTTP

Toutes les routes utilisent `/api/ai/v1`, l'authentification Sanctum, le contexte d'entreprise effectif, les contrôles de rôle Laravel et la RLS PostgreSQL runtime. Version de préfixe v1 dans l'API, migrations additives.

| Écran | Endpoints | Réalisation actuelle | Reste à faire avant V5.1 complet |
|---|---|---|---|
| M16.01 Assistant métier | `GET/POST /models`, `POST /models/{id}/activate`, `GET/POST /agents`, `POST /agents/{id}/activate`, `GET/POST /assistant` (assistant POST uniquement), `GET /runs` | Registry, configuration limitée aux outils read-only, réponses à partir de sources vérifiées, historisation | LLM + providers, orchestrateur P111, registry complet P109, versions de configs P112, budgets et quotas |
| M16.02 Recherche intelligente | `GET/POST /documents`, `POST /documents/{id}/revoke`, `GET /search?q=...` | Recherche lexicale de sources enregistrées non révoquées et de classification public/internal ; références affichées | Index RAG vectoriel, enrichissement multi-domaines, habilitations par établissement sur rôles intermédiaires, sync et purge d'index |
| M16.03 Anomalies | `GET/POST /anomalies`, `POST /anomalies/{id}/review` | Registre de scores et seuils fournis, preuve associée, décision et audit sans correction métier | Détecteurs opérationnels, seuils calibrés par tenant/asset, alerting |
| M16.04 Prévisions | `GET/POST /forecasts`, `POST /forecasts/{id}/stale` | Import contrôlé de prévisions, intervalles, version, date de coupe, population et preuve de backtest | Moteur de prévision réel, backtest calculé serveur, dérive automatisée/rollback et monitoring |
| M16.05 Suggestions | `GET/POST /suggestions`, `POST /suggestions/{id}/decision`, `POST /suggestions/{id}/execute` | Proposition de création BT à partir d'une DI qualifiée, hash de proposition, clé d'idempotence, approbation distincte, commande kill-switch OFF, exécution par API métier interne transactionnelle, verrou sur DI, aucun BT sur refus | Workflow multi-approbations, révisions de propositions, plafonds budgétaires avancés, agents CRM/Finance et supervision détaillée |

## Invariants garantis par conception

- `company_id` dérivé du middleware de session ; jamais accepté dans les payloads.
- Table `ai_*` tenant-owned. FK composites vers modèles, agents, établissements, demandes GMAO via la relation BT. Unique par `(company_id, id)` là où référencée ; contraintes de score/intervalle en PostgreSQL.
- DML accordée à `atlas_app_runtime` sans droit de suppression ; RLS sur chaque nouvelle table. La migration échoue si ce rôle runtime est absent ou privilégié.
- Toutes les actions nécessitent le rôle `admin` **dans l'entreprise active**. Les autres rôles restent refusés, y compris avec rôle global historique trompeur.
- Lecture des documents seulement sur classification `public` ou `internal`. Révocation immédiate dans la recherche ; documents `confidential/restricted` jamais retournés.
- Un texte récupéré est uniquement affiché comme source ; il n'est jamais injecté comme instruction d'outil. Pas de traitement d'une injonction de prompt récupérée.
- Suggestions : auteur et approbateur distincts, refus sans effet, approbation impossible après expiration. Identité et digest des paramètres approuvés revérifiés immédiatement avant exécution. Une DI ne produit qu'un BT grâce au verrou transactionnel + contrainte SQL unique existante.
- `ATLAS_AI_EXECUTION_ENABLED=false` par défaut ; si non activé, un POST d'exécution renvoie 503, sans écriture métier. L'approbation seule ne crée pas un BT.
- Registres `ai_events` en insertion seulement pour l'utilisateur runtime ; historiques accessibles sans API d'édition ni suppression.

## Installation et vérification

Environnement PostgreSQL réel compatible avec `atlas_app_runtime` créé par F16/F20. Ne jamais purger les données d'une base existante.

```bash
cd backend
php artisan migrate --force
php artisan test --filter=AiWorkspaceTest
cd ../frontend
npm ci
npm run typecheck
npm run build
```

Sur la branche et la PR, la CI standard (`.github/workflows/ci.yml`) exécute `scripts/ci.sh`, lequel doit confirmer PostgreSQL, build et navigateur. Contrôler les logs **du commit PR final**, les tests tenant A/B, les privilèges SQL réellement actifs (pas seulement tests SQLite), et relancer les scénarios de la spécification métier.

### Recette ciblée

1. Dans l'entreprise A, admin crée modèle, publie, crée agent avec outil `read_verified_documents`, puis active.
2. Créer un document interne fictif, rechercher et demander à l'assistant ; vérifier le titre, la source, l'absence de génération artificielle et le journal `ai_runs`.
3. Créer un document confidentiel fictif : aucun extrait ne doit apparaître ; révoquer un document interne : disparaît des résultats immédiatement.
4. Insérer un score d'anomalie synthétique, marquer faux positif ; vérifier que l'alerte n'est pas supprimée.
5. Insérer une prévision synthétique avec bornes et preuves ; la marquer périmée sans perte de version.
6. Sur une DI qualifiée, proposer un BT coût 94 EUR. Auteur ne peut pas s'auto-approuver. Approbateur refuse : zéro BT. Proposer de nouveau, faire approuver par un autre utilisateur. Exécution refusée si kill-switch éteint.
7. En environnement test isolé, activer temporairement `ATLAS_AI_EXECUTION_ENABLED`, exécuter la suggestion deux fois, vérifier **un seul BT**, journal, approbateur et hash.
8. Changer vers entreprise B : aucun modèle, document, conversation, anomalie, prévision ou suggestion de A. Tester écriture cross-tenant et rôle reader (403 ou 404).

### Dépendances et limites explicites

- La recherche actuelle **n'est pas un moteur RAG multi-domaines**. Elle porte sur des extraits explicitement importés ; la précision et le rappel ne sont pas qualifiés. Les références `source_ref` sont des identifiants, pas des URL libres.
- Les anomalies et prévisions sont des **enregistrements de résultats** ; aucun modèle ML n'est entraîné, inféré ou surveillé automatiquement.
- Les agents ne peuvent effectuer qu'une lecture de documents vérifiés ; le BT est seulement exécuté après deux actions humaines distinctes et un enablement opérationnel.
- Une validation métier reste nécessaire pour les seuils, quotas, délégations, durée de conservation, rétention RGPD, workflows de révision et règles proposées (P) V5.2.
- Exécuter la reprise/restauration et les tests concurrency avec PostgreSQL 17. Les tests SQL SQLite ne prouvent pas RLS.
- Pour abandonner cette branche, restaurer une sauvegarde de données avant rollback. Ne jamais exécuter `migrate:fresh` ni `docker compose down -v` sur un environnement réel.
