# ATLAS ONE V5 — P132 housekeeping hotelier : premier lot

Une mission de nettoyage par chambre et journée d'exploitation, statut sale → en cours → propre → inspectée, journal d'événements en écriture seule, et API de lecture de l'état de commercialisation. PostgreSQL RLS par société et FKs composites empêchent les associations cross-tenant.

Routes admin : GET/POST /api/hotels/housekeeping/tasks ; POST /api/hotels/housekeeping/tasks/{id}/transition ; GET /api/hotels/housekeeping/readiness?room_id=N&business_date=YYYY-MM-DD.

Important : le statut de propreté est indépendant du statut physique de la chambre. Une chambre inspectée et physiquement bloquée/hors-service n'est PAS prête à vendre. L'endpoint readiness est uniquement consultatif ; les parcours de confirmation de réservation existants ne le consultent pas encore. Aucune promesse de synchronisation mobile ni de remise en vente automatique n'est faite.

Tests : workflow d'états ordonnés, nécessité d'inspection, journal immutable, chambre hors-service reste non commercialisable, refus rôles insuffisants et isolation société A/B. Suite complète CI PostgreSQL avant fusion.

Restent hors périmètre de ce lot : carte interactive d'étages, gestion des équipes et affectations, mobile offline et notifications, travaux techniques, moteur de disponibilité fusionné aux réservations.