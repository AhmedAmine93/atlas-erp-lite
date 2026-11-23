# ATLAS ONE — M04 Achats & Approvisionnement

**Statut :** implémentation complète des parcours principaux, recette globale non validée.  
**Branche :** `feature/m04-achats-approvisionnement` · **PR métier :** #83 vers `dev` (brouillon).  
**Sources de vérité :** `M04_achats-approvisionnement.md` V5.1, `M04_REGLES_METIER_achats-approvisionnement.md` V5.2.

## Six sous-menus

| Menu | Navigation | API / processus |
| --- | --- | --- |
| M04.01 Fournisseurs | `suppliers` | Registre, identité fiscale, blocage/archive, IBAN chiffré et validation indépendante |
| M04.02 Demandes d'achat | `purchase-requests` | Lignes historisées, révisions, approbation à quatre yeux, délégations configurables par devise et plafond |
| M04.03 Consultations | `supplier-quotes` | Offres avec coûts annexes, comparaison TTC et délai, expiration, attribution/re-arbitrage et historique |
| M04.04 Commandes | `purchase-orders` | Prix figé depuis l'offre, lignes biens/services, envoi/confirmation, avenants approuvés et journal d'engagements |
| M04.05 Réceptions | `purchase-receipts` | Réception partielle, acceptation/rejet qualité, stock accepté uniquement, prestations signées et rejeu idempotent |
| M04.06 Factures fournisseurs | `supplier-invoices` | Détection doublons, rapprochement 3 pièces, litiges, avoirs, re-rapprochement et bon à payer à quatre yeux |

## Sécurité et intégrité

Les routes sont sous `auth:sanctum`, limitation de débit et middleware de tenant actif.
Les nouvelles routes sont réservées aux administrateurs du tenant. Les plafonds d'approbation s'appliquent dès qu'une délégation est configurée pour la devise ; sans délégation, la compatibilité historique autorise la procédure admin à quatre yeux. L'auteur ne peut ni approuver sa propre demande, ni s'accorder un plafond.

La base conserve des relations composites `(company_id,id)`, des contraintes PostgreSQL CHECK et des politiques RLS pour `atlas_app_runtime`. Les montants sont exprimés en centimes, sans calcul monétaire en flottants. Les transitions complexes utilisent les transactions et verrous de ligne. Les IBAN sont vérifiés modulo 97 et chiffrés en stockage. Une modification bancaire en attente bloque l'autorisation de paiement.

Le bon à payer signifie **autorisation interne** ; aucun appel de virement bancaire n'est effectué. Les exports CSV sont bornés, limités au tenant, audités et neutralisent les préfixes de formules de tableur.

## Recette fictive

1. Un demandeur crée une demande de 1 001 EUR. Le tenant configure un plafond de 1 000 EUR pour un approbateur et 2 000 EUR pour un second.
2. L'auto-approbation et l'approbation au-dessus du plafond doivent être refusées. Un valideur distinct disposant de 2 000 EUR peut approuver.
3. L'acheteur compare plusieurs offres puis attribue une offre valable. Un nouvel arbitrage avant commande est conservé dans l'historique.
4. Une commande de 10 articles à 20 EUR est envoyée. Une réception accepte huit articles. Une facture de 200 EUR ne rapproche que 160 EUR ; les 40 EUR restants sont litigieux.
5. Le rejeu du même bon de réception ne crée pas de seconde écriture. Des réceptions complémentaires ou une attestation de service fait permettent un nouveau rapprochement.
6. Un bon à payer n'est autorisable qu'après contrôles, en l'absence de modification bancaire en attente. Une autre personne que l'enregistreur de la facture prend la décision.

## Tests et vérification

Tests présents :
- `backend/tests/Feature/ProcurementM04Test.php`
- `backend/tests/Feature/ProcurementM04CompletionTest.php`
- `backend/tests/Feature/PurchaseLifecycleTest.php`
- `backend/tests/Feature/PurchaseRevisionTest.php`
- `frontend/tests/purchasing-m04.spec.ts`

Dernière CI réellement exécutée avant les derniers correctifs : 115 tests backend réussis ; 15/16 tests navigateur réussis. L'unique échec navigateur identifié portait sur un sélecteur de champ dans le test fournisseur ; le test a été modifié. **Les changements ultérieurs n'ont pas encore été validés par une CI au SHA final.** Le test navigateur ne prouve pas encore le parcours E2E complet des six sous-menus.

```bash
cd backend
php artisan migrate --force
php artisan test --filter=ProcurementM04
php artisan test --filter=Purchase
php artisan test
cd ../frontend
npm ci
npm run typecheck
npm run build
DEMO_PASSWORD='<mot-de-passe-test>' npm run test:e2e -- purchasing-m04.spec.ts
```

Exécuter sur PostgreSQL et staging, puis joindre les journaux, les captures et le résultat de la procédure de restauration à la PR #83.

## Décisions et limites nécessitant une recette métier

Les documents V5.1/V5.2 incluent des propositions **P** à arbitrer : contrôle de fractionnement, tolérances de réception et de rapprochement, hiérarchie exacte de délégation, établissement/site, clôture des litiges et notifications. Le suivi des engagements existe mais **aucune vérification d'enveloppe comptable externe** n'est intégrée. Les devises différentes ne sont pas converties automatiquement sans taux validés. Pas de signature électronique qualifiée, de connecteur bancaire actif ni de synchronisation comptable tierce.

Les écrans sont implémentés et activés dans la branche ; cela ne remplace **ni CI verte au SHA courant, ni validation métier, ni test de restauration**. La PR reste brouillon et ne doit pas être fusionnée automatiquement.
