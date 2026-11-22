# ATLAS ONE V5 — P090 : Airflow 3, premier pilote Data

Le dépôt contient un DAG Airflow 3 construit avec l'API officielle airflow.sdk et un validateur de manifeste CSV sans dépendances externes. Le Compose isolé compose.data.yaml lance Airflow 3.4.0 standalone **en local seulement** sur 127.0.0.1:8089.

Le DAG atlas_v5_manifest_quality_pilot a une cadence quotidienne à 04:00 UTC, sans catch-up et avec max_active_runs=1. Par défaut il reste mis en pause jusqu'à activation volontaire dans Airflow. Il retente deux fois après 5 minutes et écrit un rapport JSON synthétique de checksum, nombre de lignes, source et tenant fictif.

Le validateur contrôle : JSON versionné, identité société/source/lot, fichier CSV sans chemin arbitraire, taille 5 Mo maximum, SHA256, colonnes external_id + amount_cents, unicité de l'identifiant de ligne, montants positifs ou nuls, maximum 20000 lignes. Les relances écrivent le même rapport stable et refusent toute discordance.

Démarrage Windows avec Docker Desktop : docker compose -f compose.data.yaml up -d. Visiter http://127.0.0.1:8089 ; retrouver les identifiants d'authentification du standalone dans les logs locaux, ne jamais les diffuser. La base de métadonnées Airflow SQLite est séparée de PostgreSQL ATLAS.

Ce pilote ne lit **aucune** table métier PostgreSQL et n'écrit aucune donnée hôtelière, client ou industrielle. C'est la première brique réelle d'orchestration et de qualité de données, pas l'ingestion API/CDC complète. Le branchement à l'ingestion métier passera ultérieurement par des identités de service à privilèges limités, idempotence prouvée et contrôles de tenant.

Test CI : unittest standard Python, validation fixture + import du DAG depuis l'image officielle Apache Airflow 3.4.0. Aucune activation automatique en production. Une installation Airflow de production devra utiliser un executor et une base metadata adaptés, TLS, RBAC/SSO, journalisation et secrets managés.

Phases P090.01 à P090.06 : base de déploiement local, DAG, scheduling, retry et rerun idempotent sur manifeste. Les alertes, la reprise opérationnelle et l'intégration avec les tables Data restent à faire.