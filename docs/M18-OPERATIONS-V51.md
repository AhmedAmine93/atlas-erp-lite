# M18 — Exploitation & DevOps : bilan de réalisation

## Six sous-menus

- M18.01 Supervision générale : agrégation des observations enregistrées.
- M18.02 Journaux techniques : événements filtrables, UUID de corrélation, écriture append-only.
- M18.03 État des services : santé technique et aptitude métier distinctes.
- M18.04 Jobs et batchs : registre, idempotence, version et transitions.
- M18.05 Sauvegardes : références, empreintes, chiffrement et attestation de restauration isolée.
- M18.06 Déploiements : registre de digests, Go/No-Go et preuves préalables.

## Contrôles

Middleware auth:sanctum et tenant, contrôle role admin Laravel, entreprise active imposée serveur, PostgreSQL RLS runtime et droits minimaux. Séparation du registre et de l'exécution réelle, avec audit. Filtrage des secrets fréquents dans les résumés.

## Limites critiques

Ce lot est un **registre déclaratif**, pas un orchestrateur de déploiement ou un outil de sauvegarde. Aucune commande shell, scrape Prometheus, exécution de job, pg_dump, restauration, action Kubernetes, validation de SBOM ou test de rollback n'est exécutée par les écrans. Les attestations restent déclarées par des opérateurs ; des connecteurs sécurisés et des preuves issues d'un environnement isolé sont nécessaires pour une validation réelle.

Les règles RM-M18.01 à RM-M18.06 sont donc **partiellement couvertes**. Les objectifs RPO/RTO, les seuils SLO, la rétention et l'astreinte nécessitent validation métier et technique.

## Recette restante

1. CI sur PostgreSQL jetable : migrations, API, isolation tenant A/B et concurrence.
2. TypeScript typecheck, Next.js build et Playwright.
3. Reprise réseau tardive d'un worker réel, vérification des effets en base.
4. Backup réellement chiffré et restauration sur instance isolée, mesure RPO/RTO.
5. Vérification cryptographique image/SBOM/provenance et smoke sur staging.
6. Simulation migration incompatible avec expand/contract et PV Go/No-Go.
7. Validation signée DevOps/SRE/DBA avant toute fusion.

Aucun test ou scénario réel n'est réputé passé avant l'obtention d'une preuve CI et recette.
