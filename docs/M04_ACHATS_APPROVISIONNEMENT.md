# ATLAS ONE M04 — Achats & Approvisionnement

Branche : \`feature/m04-achats-approvisionnement\` — PR #83 vers \`dev\`.

## Sources métier

- Pack V5.1 : \`M04_achats-approvisionnement.md\` (M04.01 à M04.06).
- Pack V5.2 : \`M04_REGLES_METIER_achats-approvisionnement.md\`.
- Règles transverses : authentification, séparation par entreprise, preuve de validation, audit, idempotence.

## Correspondance écrans / API

| Écran | API | Parcours |
|---|---|---|
| \`suppliers\` | \`/api/suppliers\`, \`/api/purchasing/suppliers\` | Création, archive, blocage, IBAN en demande + décision indépendante |
| \`purchase-requests\` | \`/api/purchasing/requests\` + \`/{id}/lines\`, \`/{id}/history\` | Brouillon, soumission, refus, validation à quatre yeux, révision |
| \`supplier-quotes\` | \`/api/purchasing/quotes\`, \`/{id}/award\` | Offre documentée et attribution motivée |
| \`purchase-orders\` | \`/api/purchasing/orders\`, \`/{id}/lines\`, \`/{id}/transition\` | Création depuis offre attribuée, envoi, confirmation, annulation du reliquat |
| \`purchase-receipts\` | \`/api/purchasing/receipts\`, \`/{id}/lines\` | Réception partielle, acceptation et rejet, stock des acceptés uniquement |
| \`supplier-invoices\` | \`/api/purchasing/invoices\`, \`/{id}/credits\` | Numéro normalisé, rapprochement 3 voies et avoirs |

## Invariants techniques

- Les API sont dans le groupe \`auth:sanctum + tenant + throttle\`; rôle admin de la société active requis pour les nouvelles opérations.
- Le champ \`company_id\` est toujours dérivé de la session, jamais d'un POST.
- Relations tenant composites \`(company_id, id)\` imposées sous PostgreSQL.
- Tables nouvelles protégées par PostgreSQL RLS pour \`atlas_app_runtime\`. Ce compte ne possède pas de DDL; les historiques sensibles n'autorisent pas DELETE.
- Les montants sont exprimés en centimes entiers; quantités positives et CHECK SQL pour réceptions.
- Opérations transactionnelles avec \`SELECT ... FOR UPDATE\`; références uniques par société.
- Une réception rejouée avec la même référence et le même payload renvoie la réception existante; un payload différent est refusé.
- Un IBAN est chiffré en base; seule la fin masquée est retournée à la vue. La personne ayant demandé le changement ne peut pas l'approuver.
- Le rapprochement facture calcule un montant couvert par les quantités acceptées, puis un reste litigieux; aucune API d'émission de paiement automatique.

## Scénario de recette fictif

1. Deux administrateurs habilités de la même entreprise existent.
2. L'utilisateur A crée puis soumet une demande. Il ne peut pas s'approuver.
3. L'utilisateur B approuve, reçoit une offre de 10 unités à 20 EUR, et motive l'attribution.
4. Une commande de 200 EUR est envoyée; 8 unités sont acceptées, 2 restent non réceptionnées.
5. Une facture fournisseur de 200 EUR est enregistrée : 160 EUR rapprochés, 40 EUR litigieux, statut \`disputed\`.
6. Une réception identique rejouée ne crée ni un second bon ni un mouvement supplémentaire.
7. Un autre tenant et un rôle lecteur sont refusés sur les endpoints de mutation et les données étrangères.
8. Le changement bancaire exige un second utilisateur.

Les tests d'automatisation reprennent ces invariants dans \`ProcurementM04Test.php\` et \`purchasing-m04.spec.ts\`.

## Vérifications avant fusion

Depuis une copie propre du dépôt :

\`\`\`bash
cd backend
php artisan migrate --force
php artisan test --filter=ProcurementM04Test
php artisan test --filter=Purchase
php artisan test
cd ../frontend
npm ci
npm run typecheck
npm run build
DEMO_PASSWORD='<mot de passe du compte de démonstration>' npm run test:e2e -- purchasing-m04.spec.ts
\`\`\`

Exécuter le scénario sur PostgreSQL et sur une instance de staging isolée, puis faire relire les migrations, les permissions runtime, les journaux de preuve et le plan de restauration.

## Limitations et décisions métier restantes

Les propositions V5.1/V5.2 demandent des paramètres métiers qui ne sont pas établis dans les archives : seuils de délégation, tolérances d'écarts, cycle de service fait, calcul budgétaire, calendrier, comptabilité multi-devises et profils acheteur/valideur/comptable indépendants. Le code refuse le changement implicite de devise, le dépassement des quantités et le paiement automatique, mais ne constitue PAS une validation de ces processus.

L'annulation de reliquat ne calcule pas encore une libération budgétaire comptable, l'offre attribuée n'a pas de workflow de ré-arbitrage, les avenants de commande et la signature du service fait restent à concevoir. Les paiements externes, ERP tiers et connecteurs bancaires ne sont pas intégrés. Ne pas considérer les pages navigables ou la PR ouverte comme une recette complète du domaine.
