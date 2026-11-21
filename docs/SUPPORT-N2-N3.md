# ATLAS ONE — F07–F09 : support applicatif N2/N3 multi-tenant

## Reprise de la PR #11

Le composant React, les routes REST et les transitions de la PR #11 ont été
repris et adaptés au socle multi-tenant F06. **La PR #11 elle-même reste
ouverte et n'est pas fusionnée.**

Le schéma non-tenant de la PR #11 n'a jamais été fusionné dans la branche
courante : la nouvelle migration crée donc directement une table avec
\`company_id\` non nullable. Si une instance extérieure a déjà appliqué le
schéma de #11, une migration de données explicite et validée doit être conçue
avant tout déploiement. Aucune suppression implicite des anciens incidents.

## F07 — Registre d'incidents sécurisé

- Rôles d'affiliation \`support_n2\` et \`support_n3\`, ajoutés au catalogue.
- API limitée aux membres de la société active ayant les rôles
  \`admin\`, \`support_n2\` ou \`support_n3\`.
- Modèle \`SupportIncident\` avec scope tenant fail-closed, et \`company_id\`
  défini exclusivement côté serveur.
- Création, recherche, filtres, pagination, détail et cycle de transitions.
- Résolution obligatoire pour passer de \`investigating\` à \`resolved\`.

## F08 — Historique, assignation et traçabilité

- Timeline immuable des événements : création, transitions,
  commentaires et escalades.
- Attribution seulement à un utilisateur membre de l'entreprise
  **et** possédant un rôle support ou admin dans cette entreprise.
- Relations et détails consultés uniquement depuis un incident déjà isolé.
- Traces d'audit des opérations sensibles.

## F09 — SLA de démonstration et escalade

Les SLA ci-dessous sont **des règles configurées dans le code de démonstration**,
non des engagements contractuels et non des calendriers de jours ouvrés.

| Priorité | Première réponse | Résolution |
| --- | ---: | ---: |
| P1 | 15 minutes | 4 heures |
| P2 | 1 heure | 8 heures |
| P3 | 4 heures | 48 heures |
| P4 | 8 heures | 120 heures |

- Les échéances sont figées à la création de l'incident.
- Le passage à \`investigating\` marque la première réponse.
- Le passage à \`resolved\` marque la résolution.
- Le passage à \`closed\` marque la clôture.
- L'API présente \`pending\`, \`met\` ou \`breached\` pour chaque SLA.
- Le tableau de bord donne le nombre d'incidents ouverts, d'escalades
  N3 et de SLA en dépassement.
- Escalade N2 → N3 avec justification obligatoire, bloquée si déjà
  escaladée ou après clôture.

## API

- GET/POST \`/api/support/incidents\`
- GET \`/api/support/incidents/summary\`
- GET/PATCH \`/api/support/incidents/{id}\`
- POST \`/api/support/incidents/{id}/comments\`
- POST \`/api/support/incidents/{id}/escalate\`

Toutes les routes utilisent les middlewares \`auth:sanctum\`, \`throttle:api\`
et \`tenant\`, ainsi que le Gate \`support\` fondé sur \`company_user.role_id\`.

## Exclusions et lots suivants

Pas encore de calendrier ouvré ou SLA contractuel, notifications externes,
modèle d'escalade à plusieurs équipes, email ingestion, pièces jointes,
automatisation de diagnostics SQL, exécution en production ou intégration
d'éditeurs ITSM externes. Toutes ces capacités nécessitent des sous-missions
et une validation de sécurité dédiée.

## Vérifications

- Laravel/PostgreSQL : \`SupportIncidentTest\` (rôles et isolation inclus).
- Tests existants F01–F06, ERP, ventes et facturation.
- Next.js build et typecheck.
- Playwright, dont scénario support.
- Smoke tests Docker/HTTP.

Ne pas copier de véritables journaux, tokens ou données client dans les
incidents de démonstration. Aucune fusion automatique vers \`main\`.
