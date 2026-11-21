# ATLAS ONE — RC1 : consolidation du socle, revue et portes de staging

**Statut : CANDIDAT À LA REVUE. Aucun déploiement.**

## Objectif

Proposer un point de consolidation **unique, sans modifier `main`** pour le
socle fonctionnel et sécurité F01–F15. Les PR historiques sont *empilées*
et encore en brouillon. Le candidat hérite de l'intégralité de leurs changements
et ajoute un contrôle structurel PostgreSQL reproductible.

## Branches et chaîne source

| PR | Base fonctionnelle |
| --- | --- |
| #12 | Navigation ERP |
| #13 | Entreprises et établissements |
| #14 | Isolation multi-tenant |
| #15 | Validation des références inter-tenant |
| #16 | Shell et navigation |
| #17 | Fixtures synthétiques F05 |
| #18 | Rôles par affiliation F06 |
| #19 | Support N2/N3, événements et SLA |
| #20 | Diagnostics N3 en lecture seule |
| #21 | Protection des identités globales |
| #22 | Clés étrangères composites F14 et répétition migration/restauration |
| #23 | Pilote RLS F15 |

**La PR #11 originale est exclue** : son ancien schéma d'incidents n'a
pas `company_id` et ne doit pas être fusionné en parallèle de #19.

## Preuves déjà disponibles

Le commit F15 `dea2b8c19493e934490c79250bddedbf050119f0` a réussi
[GitHub Actions 44921898562](https://github.com/AhmedAmine93/atlas-erp-lite/actions/runs/44921898562) :

- 55 tests Laravel / PostgreSQL, 523 assertions ;
- 29 contrôles HTTP sur le parcours ERP ;
- 4 scénarios Playwright, incluant ITSM, entreprises et diagnostics N3 ;
- build TypeScript/Next.js et Docker ;
- exercice synthétique pré-F14 → migration → `pg_dump` →
  `pg_restore` avec 12 contraintes composites conservées.

La CI de la **PR RC1 vers main** doit elle aussi réussir sur son propre SHA.
Le script `scripts/verify-rc1.sh` ajoute des contrôles d'invariants SQL,
mais **ne constitue pas un audit de sécurité**.

## Points bloquants avant fusion / staging externe

1. **Séparation des rôles PostgreSQL — critique.** Aujourd'hui le runtime,
   les migrations et la propriété des tables utilisent `atlas`.
   Un propriétaire peut contourner la RLS. Séparer le propriétaire/migrateur
   de l'identité runtime sans `BYPASSRLS`, puis tester les privilèges.
   F15 protège seulement un chemin de diagnostic spécialisé.
2. **Revue des migrations.** Relire et mesurer les verrous des clés composites
   F14, la reprise `company_user.role_id` F06, les anciennes tables et
   l'éventuelle migration PR #11 déjà appliquée hors du dépôt.
3. **Identité et protection des utilisateurs.** Définir l'invitation des
   collaborateurs dans une entreprise, la vérification d'identité, la
   réinitialisation de mot de passe, MFA admin et une stratégie de révocation.
4. **Staging réellement isolé.** Domaine/TLS, réseau privé, secret manager,
   journaux protégés, base distincte, sauvegardes et contrôles d'accès.
   Ne pas publier les utilisateurs fictifs avec leurs mots de passe sur Internet.
5. **Restauration représentative.** Répéter sur une copie staging anonymisée
   et mesurer durée, cohérence métier, opérations de rollback/PITR.
   Le test actuel est **synthétique seulement**.
6. **Sécurité indépendante.** Analyse de vulnérabilités, revue multi-tenant,
   tests d'autorisation et test d'intrusion autorisé avant des données réelles.

## Attention à la fusion dans main

Le workflow `.github/workflows/ci.yml` publie des **images GHCR après
une CI verte sur push main**. Ce n'est pas une mise en production automatique,
mais cela crée des images destinées à la livraison. Il ne faut pas déclencher
ce processus avant le feu vert sur le candidat.

Pour préserver l'historique des PR empilées, la stratégie d'intégration
retenue doit être décidée en revue : fusion séquentielle, bases révisées
ou PR de consolidation unique. **Ne pas fusionner à la fois RC1 et les
mêmes PR une seconde fois.** Aucun rebase forcé ni fermeture des PR
originales n'est effectué dans cette mission.

## Exécution locale du contrôle structurel

La vérification est lecture seule et refuse tout nom de base ne finissant
pas par `_test` :

```sh
bash scripts/verify-rc1.sh
```

Elle exige Docker Compose, les services de CI et la base de test migrée.
Elle vérifie les 12 FKs composites F14, la RLS F15, l'absence de privilèges
sur les données utilisateurs et la cohérence de plusieurs relations
multi-entreprises.

## Critères pour considérer RC1 prêt à intégrer

- [ ] CI verte sur le SHA RC1 et artefacts conservés
- [ ] Diffs des PR #12–#23 revus et approuvés
- [ ] Migrations vérifiées sur staging anonymisé
- [ ] Identités DB runtime/migration séparées et testées
- [ ] Processus de restauration documenté et exécuté
- [ ] Gestion des secrets et du réseau staging approuvée
- [ ] Revue sécurité complète avant exposition Internet
- [ ] Stratégie de fusion choisie sans duplication des PR historiques

**RC1 valide une étape de consolidation du code, pas une aptitude à
héberger des données clients ou à lancer un SaaS ouvert.**
