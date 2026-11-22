# ATLAS ONE — M06 Finance & Facturation V5.1/V5.2

Branche : feat/menu-06-finance-facturation-v51
Base initiale : dev / 1e4fddc959b167f1c12069dab8a1b371af84b2f7
Sources : M06_finance-facturation.md, M06_REGLES_METIER_finance-facturation.md, annexe V5.
Statut : implémentation en PR brouillon, recette non signée. NE PAS déployer en production.

## Couverture et traçabilité

| Sous-menu | Frontend | API | Base | Couvert par cette PR | À compléter/valider |
|---|---|---|---|---|---|
| M06.01 Factures | Workspace.tsx existant | InvoiceController, FinanceController balance | invoices + due_at, currency | Numéro existant, snapshot, agrégats, immutabilité SQL après émission | transitions paid/cancelled legacy, numérotation FAC propre au tenant |
| M06.02 Avoirs | FinanceWorkspace.tsx | credit | finance_credit_notes | plafond sur encours éligible, motivation, source, replay | fiscalité et quantités par ligne d'origine |
| M06.03 Encaissements | FinanceWorkspace.tsx | receipt, allocate | finance_receipts, finance_receipt_allocations | versements, rapprochement explicite, limite des sommes affectées, idempotence | webhook signé, remboursement, intégration banque |
| M06.04 Relances impayés | FinanceWorkspace.tsx | policy, setPolicy, dispute, decideDispute, reminder | finance_reminder_policies, finance_reminders, finance_disputes | échéance, cadence, blocage par litige, préparation sans envoi | opt-out, envoi e-mail réellement branché, réconciliation statut |
| M06.05 Dépenses | FinanceWorkspace.tsx | costCenter, expense, decideExpense | finance_cost_centers, finance_expenses | digest justificatif, anti-duplication, second approbateur | upload documentaire protégé et délégués métier |
| M06.06 Budgets | FinanceWorkspace.tsx | budget, approveBudget, budgetMovement, budgetSummary | finance_budget_versions, finance_budget_movements | versionnement, quatre yeux, synthèse engagé/consommé | réservations et reconversion d'engagement achats sans double compte |
| M06.07 Exports | FinanceWorkspace.tsx | createAccount, createJournal, createPeriod, entry, postEntry, closePeriod, taxRate, fxRate, export, download | finance_gl_*, finance_fx_rates, finance_tax_versions, finance_export_batches | équilibrage, période fermée, snapshot CSV, checksum | FEC réglementaire, accusé cible, écritures auto issues de tous modules |

## API et sécurité

Toutes les routes sont préfixées /api/finance/ derrière Sanctum, throttle, middleware tenant.
Lecture : rôles admin, commercial, reader existants (Gate invoicesRead).
Écriture : rôle admin membre de la société active (Gate admin).
Les tables finance possèdent company_id, FK composites entre relations financières et règles RLS PostgreSQL pour atlas_app_runtime.
Le rôle runtime n'a pas DELETE sur les tables finance et ne peut pas modifier les tables append-only.
Les montants sont en centimes entiers, le montant de facture historique est conservé.
Pour toute création sensible, request_key identifie l'opération et son digest : un replay identique renvoie l'ID existant ; un replay altéré est rejeté.

## Recette chiffrée principale

Facture émise 12 000 centimes TTC, avoir de 2 400, encaissement de 6 000 affecté explicitement. Solde attendu : 3 600 centimes.
Les écritures comptables exigent au moins deux lignes, débit = crédit, approbation par un second acteur et période ouverte. Une période clôturée ne reçoit pas d'écritures nouvelles.
Les relances sont créées au statut prepared seulement : aucun service SMTP/SMS tiers n'est connecté.
Les exports sont des fichiers CSV internes atlas-gl-v1, PAS des exports FEC conformes ni des transferts acceptés par un logiciel externe.

## Tests à exécuter (non présumés verts)

Backend : composer install, php artisan migrate --force, php artisan test --filter=FinanceMenuTest, php artisan test.
Frontend : npm ci, npm run typecheck, npm run build, npm run test:e2e.
PostgreSQL : faire tests RLS avec le vrai rôle runtime, données sociétés A/B, contention de deux transactions sur le même paiement et la même facture, et test de restauration sur copie.
Données du test FinanceMenuTest exclusivement synthétiques.

## Critères de Go / No-Go

Ne pas fusionner ou livrer sans : CI verte sur SHA exact, preuve de migration PostgreSQL, tests multi-tenant, validation de la séparation des tâches, recette Windows/Docker, preuve d'absence de double écriture, export/rejeu, test navigateurs, analyse de dépendances et validation des règles P proposées dans le cahier V5.2.
Aucune synchronisation externe live, aucun remboursement réel, aucune preuve de conformité fiscale française et aucun stockage de pièces justificatives sécurisées ne sont revendiqués dans cette version.
Les outils de Grand Livre et Périodes restent rattachés au sous-menu Exports et ne constituent pas de nouvelles entrées principales approuvées.
