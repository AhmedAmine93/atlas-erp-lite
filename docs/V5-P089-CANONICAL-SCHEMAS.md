# ATLAS ONE V5 — P089.02 : schémas canoniques Data (fondation)

Parent PR #46 (catalogue des systèmes sources). Ce lot ajoute la déclaration de contrats de données immuables, versionnés **par source et par société**.

- `data_canonical_schemas` : source, version, nom, champs JSON strictement typés, empreinte SHA-256, créateur.
- Clé étrangère composite société/source : impossible de relier un schéma à une source d'une autre société.
- `POST /api/data/sources/{id}/schemas` et `GET /api/data/sources/{id}/schemas` réservés à l'administrateur du tenant.
- Types de champs supportés : `string`, `integer`, `decimal`, `boolean`, `date`, `datetime`, `money_cents`. 1 à 32 champs, noms SQL-safe uniques et champs obligatoires `required`, `pii`.
- Pas de SQL arbitraire, code exécutable, connecteur, url, secret, mot de passe ni stockage de données issues des sources.
- Champs PII interdits pour une source classée publique; la revue RGPD reste nécessaire pour `internal` et au-delà.
- Contrat une fois créé = **immuable**. Une évolution passe par une nouvelle version entière.
- Scénarios PHPUnit : auth, rôle, injection de schéma, refus de clé étrangère autre tenant, version dupliquée et classification.

**Restant** : P089.03–.05 ingestion réelle API/SQL/CSV, mapping, gestion d'erreurs, rejets, files, idempotence et P089.06 volumes/performances. Cette PR ne constitue pas un pipeline ETL prêt à l'emploi. Aucun déploiement en production.
