# ATLAS ONE V5 — P050 : Demandes d'intervention GMAO

## Implémentation candidate

Création DI référencée à un équipement de la société active et transition new vers qualified, état métier vérifié sous verrou SQL, contrôle priorité, audit, tests sociétés A/B. À compléter : refus/acceptation, affectation, BT, transitions métier, SLA et site permissions.

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
