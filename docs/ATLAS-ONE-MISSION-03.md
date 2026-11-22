# ATLAS ONE — Mission 03 : sécurité multi-entreprises et vision universelle

## Vision produit

ATLAS ONE est une plateforme modulaire combinant ERP, GMAO, CRM, data engineering, analytics, automatisation, MLOps, DevOps et IA propriétaire.

L'objectif à long terme est de créer des modèles de langage puissants, entraînés sur une infrastructure adaptée, et des modèles spécialisés au sein d'ATLAS AI Lab. **Cette mission ne prétend pas entraîner un LLM géant.** Elle construit l'isolation nécessaire pour que les futurs modèles, agents, pipelines, datasets, embeddings, clés d'API et expériences ne mélangent jamais des clients distincts.

## Ce que la mission livre

- Tenant historique `ATLAS_DEMO`, migration des données métier préexistantes.
- Affiliations `company_user`, résolution du tenant en session, refus sans affiliation.
- Changement explicite d'entreprise via `POST /api/tenant/switch`.
- `GET /api/me` expose les entreprises accessibles et le tenant actif.
- Scopes Eloquent *fail closed* et remplissage serveur de `company_id` sur huit modèles métier.
- Validation des références de clients, produits, commandes et mouvements dans le tenant.
- Restriction des requêtes analytiques SQL au tenant ; les métriques techniques sous jeton restent agrégées.
- Administration : listes par affiliation et création d'une nouvelle entreprise attachée à son administrateur.
- Tests unitaires/fonctionnels d'isolation, changement de tenant, accès refusés et compatibilité historique.

## Contrat sécurité pour la Data Platform, l'IA et les agents

Tout document, événement outbox, lot de données, dataset, embedding, exécution de pipeline, modèle, prédiction, log de tool call et permission de l'agent devra porter un `company_id` vérifié à la frontière de chaque service.

1. Aucun `company_id` provenant du navigateur n'autorise à lui seul une requête.
2. L'API résout le tenant sur la session / l'identité authentifiée.
3. Le stockage analytics et les workers doivent prendre en charge le contexte tenant explicitement.
4. Les prévisions, l'entraînement, la recherche RAG, et les index de vecteurs doivent filtrer le tenant en amont.
5. Les outils des agents doivent appliquer les permissions côté serveur et auditer toutes les actions.
6. Pour des données de clients réels, imposer consentement, conformité, stratégie de conservation et non-entraînement par défaut sur données non autorisées.

## Limites et prochaines étapes de sécurité

Cette livraison applique les contrôles applicatifs sur les principaux endpoints et modèles historiques. Elle **ne constitue pas encore une isolation de défense en profondeur certifiée**.

- Ajouter politiques PostgreSQL RLS, tests d'accès SQL directs, contraintes relationnelles composites inter-tenant, et audit des accès via jobs/console.
- Faire évoluer le rôle global vers un rôle par affiliation (`company_user.role_id`) avec migration progressive.
- Concevoir le tenant context des services asynchrones avant d'activer Outbox, Kafka, Airflow et agents.
- Vérifier par migration PostgreSQL, smoke test HTTP, PHPUnit, tests navigateur et contrôle des sessions.
- Analyser spécifiquement les liens historiques SQL, les exports PDF, les métriques Prometheus, les routes et toutes les relations Eloquent.
- Ne pas utiliser cette branche pour héberger des données réelles d'entreprises tant que l'audit de sécurité n'est pas finalisé.

## Vérifications

```bash
cd backend
php artisan test
cd ../frontend
npm ci
npm run typecheck
npm run build
```

GitHub Actions exécute une vérification CI de la PR avec PostgreSQL, concurrence, HTTP et navigateur. Aucune fusion automatique dans `main`.
