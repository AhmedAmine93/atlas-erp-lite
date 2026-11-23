# M12 — Qualité & Conformité — Note de livraison V5.1

Branche : feature/m12-qualite-conformite-v5-1. Base : dev.

## Mapping des cinq sous-menus

| Fonction | Workspace | API | Base PostgreSQL |
| --- | --- | --- | --- |
| Contrôles M12.01 | quality-controls | /api/quality/controls ; /controls/{id}/checks | quality_plans, quality_checks |
| Non-conformités M12.02 | nonconformities | /api/quality/nonconformities | quality_nonconformities |
| CAPA M12.03 | corrective-actions | /api/quality/actions | quality_actions |
| Audits M12.04 | quality-audits | /api/quality/audits | quality_audits, quality_findings |
| Documents M12.05 | compliance-docs | /api/quality/documents ; /versions | quality_documents, quality_document_versions, quality_read_receipts |

Règles en place : appartenance explicite via company_user, middleware tenant, transactions, FK composites company_id, RLS PostgreSQL runtime, audit quality_events, idempotence Idempotency-Key, validation des transitions CAPA et NC par version, signature documentaire SHA-256.

## Recette fictive M12

Créer un plan de contrôle pour dix unités, mesurer un lot de dix avec une unité rejetée. Vérifier un contrôle FAIL, une non-conformité, une CAPA, et une seule écriture lors du rejeu avec la même Idempotency-Key. Contenir la NC et interdire la fermeture avant validation indépendante de l'efficacité CAPA. Soumettre un audit majeur à un autre réviseur pour fermeture. Valider un document par un autre utilisateur que son auteur puis publier une version avec accusés de lecture figés.

## Exécution

- cd backend && php artisan migrate --force && php artisan test --filter=QualityMenuTest
- cd frontend && npm ci && npx tsc --noEmit && npm run build
- bash scripts/ci.sh sur CI Linux/Docker, avec PostgreSQL réel.

## Bloquants de production — NO-GO

1. La quarantaine qualité est traçable mais NE retire PAS les quantités vendables dans le modèle Stock existant. Blocage transactionnel réel par lot/numéro de série, et tests inter-modules, restent indispensables.
2. Les pièces jointes sont une référence. Le stockage immuable de preuves, le scan antivirus, le hash des fichiers et la politique de conservation ne sont pas livrés.
3. Autorisations par entreprise et rôle qualité. Délégations par établissement et signatures qualifiées à spécifier.
4. Escalade CAPA en retard idempotente mais planification et notification métier non configurées.
5. Aucun connecteur en temps réel revendiqué et contrôles qualité non branchés automatiquement sur tous les imports Data.
6. Exécuter CI, tests RLS sous atlas_app_runtime, tests de concurrence et migration/restore sur copie, Playwright multi-navigateurs, scanners, recette métier et PV Go/No-Go avant production.

Les règles métier P du document V5.2 doivent être validées par le responsable qualité. Aucune fusion automatique vers dev ou main.