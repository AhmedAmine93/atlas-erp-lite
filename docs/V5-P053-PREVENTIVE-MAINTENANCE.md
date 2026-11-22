# ATLAS ONE V5 — P053: maintenance préventive calendaire (premier incrément)

Cette branche ajoute deux tables PostgreSQL tenant-scoped et une génération explicite de DI/BT depuis les échéances de plans. Il ne s'agit pas encore d'un ordonnanceur automatique continu.

API : GET/POST /api/maintenance/preventive/plans ; GET /api/maintenance/preventive/occurrences ; POST /api/maintenance/preventive/run.

Un plan identifie l'actif, sa priorité, son intervalle de 1 à 3650 jours et sa prochaine échéance. Le POST run, réservé à un administrateur authentifié, traite au plus 20 plans dus par appel ; chaque occurrence crée dans une transaction une DI qualifiée, un BT ouvert, une trace d'occurrence et avance next_due_on.

La contrainte UNIQUE (company_id, plan_id, due_on) et le verrou SQL lockForUpdate sont les barrières anti-doublons. RLS PostgreSQL + FK composites protègent les sociétés, aucune permission DELETE n'est accordée au compte runtime. Les actions sont auditées.

Tests : création plan, refus company_id fourni par le client, refus du rôle reader, génération unique après deux appels, isolation entre tenants et clés d'actifs cross-tenant.

Restent explicitement hors périmètre : calendrier cron multi-tenants, compteurs, événements IoT, préventif conditionnel, coûts, gammes opératoires, affectation automatique des techniciens.

La PR doit passer la CI PostgreSQL et les tests backend avant d'être fusionnée dans dev. main et la production ne doivent pas être modifiées.