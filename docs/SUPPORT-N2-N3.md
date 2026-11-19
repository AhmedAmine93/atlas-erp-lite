# ATLAS ERP Lite — Support applicatif N2/N3 (lot 1)

Ce lot ajoute un registre d'incidents technique, isolé des processus ERP existants. Les incidents et scénarios de démonstration doivent être **entièrement fictifs**. Ne jamais copier de logs réels, identifiants, jetons, données personnelles ou chaînes de connexion dans la description.

## Périmètre livré

- Migration `support_incidents` (PostgreSQL et SQLite pour tests).
- API REST authentifiée, réservée aux administrateurs (première version).
- Création, consultation, recherche, pagination, filtres par statut et criticité.
- Cycle de vie contrôlé : `open → investigating → resolved → closed`.
- Résolution écrite obligatoire pour passer à `resolved`.
- Traçabilité dans le journal d'audit existant.
- Tests de permissions, validations et transitions.

## API

| Méthode | Endpoint | Usage |
|---|---|---|
| GET | `/api/support/incidents` | Liste paginée, `status`, `severity`, `search` |
| POST | `/api/support/incidents` | Créer un incident |
| GET | `/api/support/incidents/{id}` | Détail |
| PATCH | `/api/support/incidents/{id}` | Transition de statut |

Exemple de création :

```json
{
  "title": "Latence SQL sur commandes (fictif)",
  "description": "Le traitement de validation dépasse 2 s dans l'environnement de démo.",
  "severity": "P2",
  "environment": "demo"
}
```

Le client doit utiliser la session Sanctum et la protection CSRF existantes.

## Vérification

Depuis `backend`, exécuter `php artisan test --filter=SupportIncidentTest`. Ensuite vérifier les migrations et les endpoints sur une base de test jetable. Les tests ne sont **pas annoncés comme exécutés** dans cette branche tant que la CI n'a pas confirmé leur succès.

## Lots suivants (non livrés)

1. Navigation et interface Support dans Next.js, rôles technicien N2/N3 dédiés.
2. Historique détaillé des commentaires/assignations, pièces jointes contrôlées, SLA et escalades.
3. Jobs de diagnostic **en lecture seule**, métriques SQL, supervision, alertes et cas de laboratoire reproductibles.
4. Données de démonstration volumineuses, scénarios de charge et rapports d'incidents.

Ne pas exposer d'outil d'exécution SQL arbitraire dans l'API ni de logs bruts non filtrés.
