# ATLAS ONE V5 — P066.04 · Permissions fonctionnelles de bout en bout

**Références :** cahier V5, P066.04 (portail Next.js), P019.02/P019.05 (RBAC/ABAC, policies backend), B01 (rôles par société), S03 (isolation).
**Base de branche :** PR #36, elle-même empilée sur #35 et #30–#34. **Aucune mutation de production ni de main.**

## Problème observé dans le dépôt

Avant cette PR :
- `WorkspaceSidebar.tsx` masquait l'administration et ITSM, mais affichait d'autres modules non autorisés pour les comptes Stock ou Support.
- `workspaceRoutes.ts` refusait les écrans admin et support non autorisés, mais ne refusait pas CRM/ventes à Stock.
- `ContactController`, `ProductController`, `SalesController`, `InvoiceController` (incluant téléchargement PDF) et `DashboardController` autorisaient leurs lectures sans vérifier systématiquement le rôle métier. Les modèles avec `BelongsToTenant` limitaient la société, mais **cela ne remplaçait pas la politique par rôle**.
- L'API `/dashboard` retournait des indicateurs financiers à Stock alors que l'IHM n'affichait pas les tuiles financières.

## Portée de l'incrément

Définir des Gates distinctes **lecture** (`crmRead`, `productsRead`, `movementsRead`, `salesRead`, `invoicesRead`, `dashboardRead`) en plus des Gates d'écriture existantes (`crm`, `stock`, `sales`, `admin`, `support`). Le rôle vient exclusivement de `company_user.role_id` de l'entreprise de session, via `TenantRoles`. Ne jamais utiliser le rôle global historique de `users.role_id` pour autoriser.

| Rôle société | CRM tiers | Catalogue | Mouvements | Devis/Commandes | Factures/PDF | Dashboard ERP | Support |
|---|---:|---:|---:|---:|---:|---:|---:|
| admin | lecture/écriture | lecture/écriture | lecture | lecture/écriture | lecture/écriture | finance+stock | oui |
| commercial | lecture/écriture | lecture | non | lecture/écriture | lecture/écriture | finance+stock | non |
| stock | non | lecture/écriture | lecture | non | non | stock seul, JSON expurgé | non |
| reader | lecture | lecture | lecture | lecture | lecture | finance+stock | non |
| support_n2 | non | non | non | non | non | non (panel support distinct) | oui |
| support_n3 | non | non | non | non | non | non (panel support distinct) | oui + diagnostic |

*Les permissions d'écriture serveur préexistantes ne sont pas étendues.* Les entrées de menu fonctionnelles et les liens profonds utilisent la même matrice côté interface. Toute navigation directe ou demande HTTP doit être rejetée par Laravel, sans fuite d'information. Les modules non développés restent désactivés.

Les réponses de `/api/dashboard` à Stock ne doivent jamais transmettre les chiffres, commandes ou produits les plus vendus ; les compteurs et listes de stock restent réels. Cette limitation est une mesure de **minimisation des données** à la couche API et pas uniquement CSS.

## Tests / définition de terminé

- [ ] `FunctionalReadPermissionsTest` passe avec utilisateur dont `users.role_id=admin` mais `company_user.role_id=stock/support/reader`.
- [ ] Stock : refus 403 CRM, ventes, factures et PDF ; catalogue/stock permis ; JSON dashboard sans montant ni commande.
- [ ] Support : refus 403 routes ERP, accès incidents autorisé.
- [ ] Commercial : catalogue visible et ventes/CRM autorisés, mouvements et incidents interdits.
- [ ] Lecteur : accès lecture autorisé et création CRM refusée.
- [ ] Playwright : absence de liens interdits et d'actions d'édition pour les profils limités ; navigation admin intacte.
- [ ] CI sur le **SHA exact** réussie ; revue d'absence de régression sur facturation et recherche.
- [ ] Revue manuelle des chemins qui exposent des téléchargements, des données personnelles ou des tableaux de bord nouveaux.

## Limites conscientes et suites

- P066.05 reste à faire : cache, requêtes tardives, changement tenant en plusieurs onglets, exports, endpoints secondaires.
- P019.03–06 restent à réaliser : politique fine par **opération + ressource + établissement**, dates d'affiliation, ABAC et décisions de délégation.
- P018/P021/P024 et DBX02 doivent empêcher toute falsification SQL du contexte tenant sous un compte applicatif compromis. Les Gates ci-dessus ne suffisent pas à démontrer l'isolation face à une injection SQL.
- Le contrôle SSR/Next.js et l'inventaire des endpoints de nouveaux modules seront revalidés en P066.06. Ne pas conclure que l'application est prête à être exposée à Internet.

**NO-GO production** avant intégration de la pile, revue de sécurité et Go/No-Go L/S.
