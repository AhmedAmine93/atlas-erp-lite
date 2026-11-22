# M11 — Support & ITSM — Livraison en branche dédiée

**Base :** dev · **Branche :** feature/m11-support-itsm-v5-2  
**Sources :** V5.1 M11_support-itsm.md + V5.2 M11_REGLES_METIER_support-itsm.md (archives 22/11/2026).  
**Statut :** développement soumis à recette — ne pas déclarer GO avant une CI PostgreSQL + Playwright vérifiée et validation métier.

## Matrice de couverture

| Sous-menu | Existant au départ | Apports de cette branche | Points à valider |
| --- | --- | --- | --- |
| M11.01 Centre de tickets | Navigation désactivée | Cases \`ticket\`, attribution, pagination, recherche, transitions versionnées, événements, liens incidents | File transverse multi-domaines, fermeture en lot, export filtré |
| M11.02 Incidents | API, interface, SLA, diagnostic N3, événements et tests existants | Liaison avec dossiers ITSM et application de versions SLA publiées aux incidents futurs | Matrice impact × urgence approuvée, réouverture contrôlée |
| M11.03 Problèmes | Navigation désactivée | Problèmes avec cause racine, contournement, correction permanente, historique et association incidents | Post-mortem, assignation d'actions préventives |
| M11.04 Demandes de service | Navigation désactivée | Catalogue tenant, éligibilité, workflow d'approbation, preuve d'exécution, séparation demandeur/approbateur sensible | Validation du catalogue et rôles détaillés |
| M11.05 Changements | Navigation désactivée | Fenêtre, risque, plan de retour, décision CAB indépendante, pipeline, contrôle de version et journal | Politique changement d'urgence, reprise/rollback |
| M11.06 SLA et escalades | SLA incident de référence | Politiques versionnées, publication prospective, minutes serveur et suivi d'acquittement | Calendrier métier hors 24h/24, pauses contractuelles |
| M11.07 Base de connaissances | Navigation désactivée | Articles, classification N3, revue indépendante critique, révisions et retrait sans suppression | Gestion des pièces jointes, moteur de recherche enrichi |

## Contrats techniques

- **Laravel 12 / Next.js 16 / PostgreSQL :** schéma additif M11, modèles avec filtre \`company_id\`, endpoints sécurisés par authentification, middleware tenant et Gates ITSM.
- **Isolation :** \`company_id\` imposé par les modèles, clés étrangères composites PostgreSQL, politiques RLS restreintes au rôle \`atlas_app_runtime\`, aucun \`DELETE\` accordé sur les nouvelles tables.
- **Concurrence :** \`SELECT ... FOR UPDATE\` et version sur les workflows ; réponse HTTP 409 pour versions obsolètes.
- **Rejeu :** \`Idempotency-Key\` obligatoire pour les écritures métier de dossiers et du catalogue, journal de digest et réponse. Les écritures des politiques SLA, escalades et articles doivent encore être uniformisées avant l'engagement final.
- **Traçabilité :** historique des dossiers, version des articles, audit applicatif. Les incidents existants ne sont pas supprimés ni migrés.
- **Données :** pas d'injection automatique en production ; scénarios de tests exclusivement fictifs.

## Vérifications à lancer sur le SHA final

\`\`\`bash
# Utiliser l'environnement de validation isolé, PostgreSQL avec rôle runtime restreint
cd backend && php artisan test --filter=SupportItsmMenuTest
cd frontend && npm ci && npm run typecheck && npm run build
# Dans le dépôt racine, exécuter l'ensemble de la CI Docker + Playwright :
bash scripts/ci.sh
\`\`\`

**Important :** les tests écrits ne constituent pas une preuve de réussite avant exécution. Ne fusionner vers \`dev\` qu'après contrôle des logs CI, du schéma PostgreSQL réel, d'une restauration en préproduction et d'une validation métier des règles \`P\` non encore approuvées.

## Scénarios de recette complémentaires

1. Incident 09:00 → prise en charge 09:15 → résolution 10:00 ; réponse 15 min, résolution 60 min.
2. Tenant A crée un incident et un problème ; tenant B obtient 404 sur les identifiants de A et 0 résultat.
3. Un acteur N2 prépare un changement en production ; un membre CAB différent approuve la bonne version ; tout changement ultérieur invalide l'accord.
4. Demande sensible : le demandeur ne peut pas se valider ; exécution impossible sans approbation et preuve.
5. Article réservé N3 absent pour N1/N2, y compris via la recherche ; le retrait conserve ses versions.
6. Rejouer même \`Idempotency-Key\` + même payload (une seule création) ; payload différent (409).
7. Vérifier absence de droits d'écriture inter-tenant avec le rôle SQL runtime réel.
8. Restauration depuis snapshot préalable et test de migration \`up\`/\`down\` sur base de qualification, jamais sur production.

## Décision

**NO-GO par défaut** en attente de CI complète, recette fonctionnelle N1/N2/N3/support manager/CAB et fermeture des écarts de la matrice. La navigation rendue \`ready\` dans cette branche ne doit pas être confondue avec la mise en production.
