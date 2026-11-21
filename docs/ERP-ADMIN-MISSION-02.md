# Mission 02 — Administration : entreprises et établissements

## Périmètre implémenté

- Référentiel `companies` : code unique, raison sociale, e-mail, téléphone, adresse.
- Référentiel `establishments` : rattachement à une entreprise, code unique par entreprise, ville, adresse et état actif.
- Listes et formulaires de création / modification accessibles dans **Administration & Sécurité → Entreprises et établissements**.
- Routes `GET /api/companies`, `POST /api/companies`, `PUT /api/companies/{id}`, `GET /api/establishments`, `POST /api/establishments`, `PUT /api/establishments/{id}`.
- Vérification de l'accès administrateur via `Gate::authorize('admin')` côté Laravel pour chaque route.
- Validation des codes, contrôle d'unicité applicatif et en base, clés étrangères, journalisation des créations et modifications.
- Tests automatisés sur permissions, validation, unicité des codes, création/modification et audit.

## Limites de cette mission

- **Référentiel seulement** : les tables historiques (`users`, `customers`, `products`, `quotes`, `orders`, etc.) ne sont **pas** cloisonnées par entreprise.
- Aucune prétention à l'isolation multi-tenant : ajouter un `company_id` isolé sans propagation transactionnelle et autorisations de lecture/écriture serait trompeur et dangereux.
- Pas de suppression des sociétés/établissements, pour éviter de perdre des données de référence. Prévoir une stratégie d'archivage avant d'ajouter la suppression.
- Les rôles sont toujours les quatre rôles existants.
- La branche contient aussi, par héritage, la mission 01 de navigation modulaire non encore fusionnée.
- Aucune donnée client réelle n'a été ajoutée.

## Validation avant fusion

```bash
cd backend
php artisan test --filter=OrganizationTest
php artisan test
cd ../frontend
npm ci
npm run typecheck
npm run build
```

Sur PostgreSQL, exécuter les migrations sur une **base de test** et vérifier les contraintes et la compatibilité des anciennes données, puis les parcours HTTP/Playwright existants.

## Prochaine étape

Mission 03 : modèle d'organisation et de sécurité transversal (tenant/context, affectations utilisateur-entreprise, stratégies de migration des données existantes), avec démonstration de l'isolation en lecture/écriture et tests croisés avant activation réelle du multi-tenant.
