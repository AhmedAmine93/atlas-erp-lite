# ATLAS ONE — P066.02 / M04.04 — Liens profonds et workspaces Next.js

**Référence canonique V4 :** Programme I, P066 « Portail Next.js », sous-étape P066.02 « Créer deep links et workspaces », mission héritée M04.04 (URL de détail, retour, restauration du contexte après connexion). **Base :** PR #34 P06 Dashboard Builder, elle-même empilée sur les PR IHM #30–#33.

## Portée de ce lot

Le frontend initial ne suivait que `useState(view)`, sans URL par module ni restauration lors d'un rechargement. Le présent lot remplace ce comportement pour les **modules déjà implémentés** seulement :

- `/workspace/dashboard`, `/workspace/customers`, `/workspace/products`, `/workspace/quotes`, `/workspace/orders`, `/workspace/invoices`, `/workspace/support-incidents`, `/workspace/admin-companies` et les autres modules actifs répertoriés dans `nav`.
- `/workspace/customers/:id`, `/workspace/quotes/:id`, `/workspace/orders/:id`, `/workspace/invoices/:id` ouvrent un détail via l'API Laravel existante et **après** vérification de l'identité.
- `/` et `/workspace` restent compatibles avec l'accueil existant.
- `frontend/src/app/workspace/[[...slug]]/page.tsx` rend l'espace ERP sur chargement direct d'une URL, sans nouvelle API ni route de données.
- `workspaceRoutes.ts` vérifie les modules actifs, l'identifiant positif et une limitation de navigation UX liée au rôle. Les noms de modules prévus mais non développés sont rejetés.
- `Workspace.tsx` synchronise les clics de navigation avec `history.pushState`, restaure la vue sur `popstate`, ouvre les fiches avec URL et réinterroge toujours l'API. La fermeture revient sur la route de la liste. Les requêtes de fiche obsolètes ne peuvent pas réafficher un ancien détail après navigation.
- Lors du changement de société, l'URL est remplacée par `/workspace/dashboard` **avant** le rechargement complet, et le backend revérifie toujours la société active. Déconnexion : l'URL privée est remplacée par `/`.

## Tests et acceptation

- [ ] Build Next.js et TypeScript passent sur le SHA exact de cette PR.
- [ ] Après connexion administrateur, le menu « Clients » met l'URL à `/workspace/customers`; rechargement = même vue.
- [ ] « Historique » d'un client ouvre `/workspace/customers/:id`, rechargement = nouvelle lecture API et ouverture de la fiche; fermer = retour à la liste.
- [ ] Le bouton Retour du navigateur restaure la vue précédente sans données fictives.
- [ ] Un lien direct `/workspace/customers` utilisé **avant connexion** restitue la vue autorisée après login pour le rôle lecteur.
- [ ] Les régressions E2E commerciales, facturation/PDF, incidents N2/N3 et changement de tenant passent.
- [ ] Vérification manuelle : URL d'un autre tenant ou identifiant absent affiche un refus sans révéler de données; connexion directe par rôle non autorisé revient à une vue sûre.

## Frontières de sécurité

Le paramètre `id` de l'URL n'est jamais une preuve d'autorisation. L'application appelle systématiquement l'endpoint existant sous session Laravel; la règle RBAC/multi-tenant côté serveur reste l'unique autorité. Le contrôle de navigation côté frontend n'autorise aucun nouveau privilège. Aucun jeton, secret ou ID de société n'est ajouté à l'URL ou au stockage local.

Cette PR **ne** crée pas les modules Oracle JET, Angular, mobile, Form Builder, Report Builder, recherche transverse, ni les processus métier absents. Les autres sous-étapes P066.03–.06, P065, P067–P072 restent à compléter suivant la V4. La conformité production exige aussi l'intégration de la pile de sécurité PostgreSQL F16–F20 et la revue manuelle L/S.

**NO-GO pour livraison** tant que les tests, la recette manuelle, la revue sécurité et la consolidation des branches ne sont pas accomplis. Aucune fusion vers `main` ni déploiement n'est autorisé par ce lot.
