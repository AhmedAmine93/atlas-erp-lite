# ATLAS ONE V5 — P089 : Préparation des lots Data

## Implémentation candidate

Validation contrôlée de sources Data puis déclaration d'un manifeste de lot avec source autorisée, empreinte SHA-256, nombre de lignes attendu et clé d'idempotence. Aucune donnée source ni credential stocké, aucun ETL exécuté, aucune garantie de traitement CDC. L'ingestion réelle, déduplication concurrente et orchestration restent à faire.

## Sécurité et exploitation

- API protégée par authentification Laravel, middleware tenant, limitation de débit et Gate administrateur.
- Données métier isolées par `BelongsToTenant`; clés étrangères composites quand nécessaire.
- Transitions d'état sensibles verrouillées par `lockForUpdate` dans des transactions.
- Données de tests exclusivement fictives ; aucune donnée utilisateur réelle copiée.
- Pas de menu activé tant que les autorisations métier et l'UX ne sont pas complètes.

## Critères de validation

- [ ] CI sur SHA exact, migrations et tests PHP verts
- [ ] Tests de rôle, société A/B et transitions métier réussis
- [ ] Vérification de conflits, tentatives de répétition et état illicite
- [ ] Revue migration, contrainte DB, opérations de reprise et index
- [ ] Revue de sécurité et scénarios de concurrence avant consolidation sur staging

## Livraison

PR fille de la PR de fondation correspondante, en brouillon et non déployée. La réussite CI n'autorise ni fusion automatique dans main ni certification produit.
