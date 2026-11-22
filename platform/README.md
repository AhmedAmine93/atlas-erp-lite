# ATLAS ONE V5 — socle transverse Redis · Kafka · Python · Oracle JET

**État : pilote d'intégration local. Ne pas déployer ce profil en production.**

Ce lot conserve les bases Next.js, Laravel et PostgreSQL. Il fournit des services optionnels sans modifier la topologie de production existante. La migration crée un outbox désactivé par défaut. Seul le profil local, avec confirmation explicite `ATLAS_PLATFORM_LOCAL=YES_ISOLATED_LOCAL`, active le trigger après les migrations.

## Démarrage local (Docker Desktop)

Depuis le dépôt, sur une **base de démonstration jetable** :

```sh
node scripts/setup.mjs
docker compose -f compose.yaml -f compose.platform.yaml config --quiet
docker compose -f compose.yaml -f compose.platform.yaml up -d --build --wait --wait-timeout 300
docker compose -f compose.yaml -f compose.platform.yaml ps
```

L'application Next.js reste sur **http://127.0.0.1:3100** si `WEB_PORT=3100`.
Redis et Kafka n'exposent aucun port hôte : uniquement réseau Compose local. Redis est utilisé par le cache Laravel (`CACHE_STORE=redis`, extension phpredis) ; sessions et queue restent stockées en base pour ne pas casser les processus existants. Les données PostgreSQL demeurent la source de vérité.

Le `publisher` Python verrouille une ligne d'outbox et n'inscrit `published_at` qu'après accusé Kafka. Si le processus tombe entre l'accusé Kafka et le commit PostgreSQL, le message est republié (livraison **au moins une fois**). Le `consumer` compare le message au document canonique de l'outbox, stocke le reçu sous contrainte unique et met à jour son compteur dans la même transaction. Le commit Kafka arrive après le commit SQL : un doublon ne recompte pas.

- Sujet : `atlas.audit.v1` ; 3 partitions, un broker local, aucune réplication HA.
- Clé Kafka : `company_id` numérique ; le consommateur compare la clé, l'identité entreprise et le contenu à la ligne PostgreSQL.
- Contenu publié : `version`, `event_id`, `company_id`, `event_type`, `occurred_at`, `payload.entity_type`, `payload.entity_id`.
- **Aucune métadonnée d'audit, coordonnées client, mot de passe ou cookie n'est publiée.**
- Reçus : `platform_event_receipts`, compteur non-financier `platform_event_counters`, partitionné logiquement par entreprise. Ce compteur ne doit jamais servir à calculer un chiffre d'affaires.
- Le trigger est en SQL PostgreSQL ; le test SQLite ne simule pas les politiques RLS ni ce trigger.

## Recette du flux

```sh
docker compose -f compose.yaml -f compose.platform.yaml exec -T redis redis-cli ping
docker compose -f compose.yaml -f compose.platform.yaml run --rm \
  -e ATLAS_PLATFORM_SMOKE=YES_ISOLATED_LOCAL publisher python -m atlas_platform.smoke
docker compose -f compose.yaml -f compose.platform.yaml logs --tail=100 publisher consumer
```

Le smoke insère un **audit fictif** dans `ATLAS_DEMO` et attend sa réception unique en PostgreSQL. Il est interdit de le lancer sur une base réelle. Les tests Python se lancent avec :

```sh
python -m pip install -r platform/requirements.txt
python -m unittest discover -s platform/tests -v
```

La CI dédiée `.github/workflows/platform-core.yml` réalise ces vérifications dans une base et des volumes jetables, ainsi qu'un contrôle de la passerelle JET.

## Oracle JET — raccordement du portail local

Le portail admin affiche **Oracle JET GMAO · local** uniquement sous `localhost` / `127.0.0.1`. Ce lien n'est pas un SSO de production. Pour ouvrir JET :

```sh
node jet-industrial/scripts/bootstrap.mjs
# Avec l'ERP déjà actif :
cd jet-industrial/atlas-jet-gmao
npx @oracle/ojet-cli@21.0.0 serve
# Dans un second terminal, depuis la racine :
node jet-industrial/scripts/proxy.mjs
```

Se connecter à **http://127.0.0.1:3100**, puis ouvrir **http://127.0.0.1:8100**. Les deux interfaces doivent impérativement employer le **même nom d'hôte** pour partager la session, même si leurs ports diffèrent. Le proxy est limité à `127.0.0.1` et doit rester non exposé. La GMAO JET réutilise les API métier Laravel : elle ne parle pas directement à Kafka ni à PostgreSQL.

## Python et Airflow

Le flux nouvellement ajouté exécute une publication et consommation d'audits en Python. Le DAG Airflow de `data/airflow` reste un **pilote séparé de validation CSV** : il ne déclenche pas encore les tâches Kafka. Ne pas présenter ce lot comme un ETL hôtelier ou un service d'IA opérationnel.

## Limites et sécurité avant production

- **Ne jamais utiliser `compose.platform.yaml` en production ou sur un serveur accessible à Internet.**
- Le broker utilise PLAINTEXT sans SASL/TLS, Redis n'utilise pas ACL/TLS et les workers Python partagent exceptionnellement le propriétaire PostgreSQL `atlas` **uniquement en local jetable**.
- Avant toute mise en production, créer des comptes DB distincts à droits strictement limités, des secrets managés, Redis ACL/TLS, Kafka TLS/SASL ou un broker managé, DLQ et politiques de rétention, replay contrôlé, monitoring des offsets et backlog, contrôles multi-tenant/rôles, sauvegarde et restauration, supervision et tests de charge.
- Ne jamais envoyer de données personnelles dans `AuditLog.metadata` puis les republier : le pipeline refuse par conception les champs supplémentaires.
- Le compteur est dérivé et peut être recalculé depuis les reçus ; l'outbox durable reste en PostgreSQL. Le consommateur ne modifie pas les ventes, les réservations ou les stocks.
- L'outbox est initialement **désactivé après migration**. Une installation standard n'accumule donc pas silencieusement des événements. Le chemin d'activation local est audit-able et refuse `APP_ENV=production`.
- Les messages incorrects ou falsifiés peuvent nécessiter une revue opérationnelle du backlog ; il n'existe pas encore de DLQ de production.
- Aucun effort de migration destructive de données métier. Pour une suppression ou modification de ces tables, créer une migration corrective explicite après sauvegarde et contrôle des reçus.

L'intégration de services ne remplace pas les cahiers des charges des 18 menus. Les API et validations métier restent à implémenter menu par menu ; ce socle leur offre un mécanisme transverse versionné.
