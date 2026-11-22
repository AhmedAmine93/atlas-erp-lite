# ATLAS ONE V5 — P036 : Cycle achats : soumission et validation croisée

## Implémentation candidate

Soumission atomique de brouillon, approbation administrateur distinct du demandeur, verrou de ligne pour empêcher transitions répétées, audit et attribution approbateur. Pas encore de délégations d’achat par budget, sourcing, commande fournisseur ni engagement comptable. Les rôles restent admin-only en attente P019 ABAC.

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
