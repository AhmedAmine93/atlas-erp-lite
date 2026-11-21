# ATLAS ONE — P066.03 : Recherche transverse (portail Next.js)

**Source canonique :** Cahier des charges V4, programme I, phase P066 « Portail Next.js », sous-étape **P066.03 — Ajouter recherche transverse** ; entrée P066.03 du plan d'exécution des 864 sous-étapes.

**Statut :** implémentation candidate, PR empilée sur P066.02 (#35). Aucun déploiement ni fusion dans `main`.

## Livré

- Recherche globale depuis la barre supérieure de l'espace authentifié, déclenchable par bouton ou **Ctrl+K**, fermeture avec Échap, délai de saisie de 250 ms, annulation des requêtes obsolètes.
- `GET /api/search?q=...` : nouveau point d'entrée Laravel authentifié sous middleware `tenant` et limitation de débit `throttle:api`. Validation d'entrée (2–100 caractères), requêtes SQL paramétrées, caractères spéciaux LIKE traités littéralement, plafond **5 résultats par catégorie**.
- Sept catégories fondées sur les modèles métier existants : clients, fournisseurs, produits, devis, commandes, factures et incidents ITSM.
- Les modèles Eloquent conservent leur `BelongsToTenant` global scope. Le rôle est récupéré depuis l'adhésion à **l'entreprise de session** via `TenantRoles`, jamais depuis un champ client ou le rôle global.
- Matrice de découverte limitée : administrateur (sept catégories) ; commercial/lecteur (CRM, produits et documents commerciaux) ; stock (produits) ; support N2/N3 (incidents uniquement).
- Réponse strictement minimale : identifiant, type, titre et catégorie, sans description d'incident, email, token, secret, numéro d'entreprise ni accès direct aux journaux.
- Cliquer sur clients, devis, commandes et factures ouvre le détail par l'API existante ; autres types rejoignent la liste autorisée. Pas d'activation d'un module prévu mais non développé.
- Recherche et résultats réinitialisés au changement d'utilisateur, d'entreprise ou de rôle ; le changement d'entreprise provoque déjà le rechargement complet du shell.

## Tests

- [ ] CI du SHA exact : Next.js build, PHP, HTTP et Playwright.
- [ ] API : accès anonyme rejeté, longueur de recherche validée et requête sans résultat.
- [ ] API : données fictives A/B jamais mélangées.
- [ ] API : utilisateur stock ne retrouve pas un client ; support N2 n'obtient que les incidents.
- [ ] E2E navigateur : Ctrl+K, recherche d'une référence produit de démonstration, clic et navigation.
- [ ] E2E : liens directs préexistants et workflow commercial conservés.
- [ ] Revue manuelle : clavier, lecteur d'écran, responsive, performances sur base volumineuse.

## Limitations et risques

Ce premier lot est une recherche **bornée, non indexée full-text** : pour de grands volumes, les recherches `LOWER(colonne) LIKE '%terme%'` exigent une étude d'indexation PostgreSQL (trigrammes, index composites, limites par entreprise), des mesures sur données fictives réalistes, puis une stratégie multi-tenant. Pas de classement IA, pas de recherche par contenu de documents ni de recherche sur tables non autorisées. Le seul contrôle UI n'est jamais une autorisation ; Laravel et PostgreSQL doivent continuer à imposer les limites de société et de rôle.

F16–F20 (sécurité PostgreSQL) restent une pile de PR séparée et non fusionnée. La recherche ne permet pas de déclarer le produit sécurisé en production. **NO-GO livraison** avant CI, revue sécurité, recette et consolidation des branches.
