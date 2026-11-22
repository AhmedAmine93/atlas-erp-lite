# ATLAS ONE V5 — Vague hôtelière groupée P131/P132

Branche unique temporaire : `feature/v5-hotel-multi-module-wave-01` ; cible exclusivement `dev`.

## Trois parcours intégrés

1. **Check-in sécurisé (P131)** — `POST /api/hotel-bookings/{id}/checkin`. Requiert une réservation confirmée, une journée d'arrivée comprise dans le séjour dans le fuseau de l'hôtel, une inspection housekeeping pour la journée d'exploitation, et l'absence de blocage/occupation concurrente sous transaction et verrou sur la chambre. Audit `hotel.booking.checked_in`. Les utilisateurs hors tenant et sans rôle admin sont refusés. Cette transition ne constitue **pas** encore le checkout ni la clôture journalière.
2. **Consultation disponibilité Next.js** — navigation `Disponibilité locale` activée ; état par chambre, motifs d'indisponibilité, pagination, dates et fuseau. L'écran indique explicitement l'absence de contrôle PMS/CRS, prix, allotements ou garanties externes. L'appel API possède le handshake tenant existant ; une consultation n'effectue pas de réservation.
3. **Options et maintenance** — formulaire dédié pour créer les retenues temporaires avec expiration et les blocages de maintenance/restriction, consultation et levée auditée via l'API P131 existante. Toutes les modifications restent soumises au backend et au RLS.

## Contrôles

- `backend/tests/Feature/HotelCheckinTest.php` : refus d'un check-in brouillon, refus sans inspection courante, ancien jour non réutilisable, check-in valide puis rejeu refusé, indisponibilité physique, isolation tenant A/B, refus lecteur.
- `frontend/tests/hotel-availability.spec.ts` : création d'un hôtel et d'une chambre fictifs, affichage de disponibilité locale, blocage maintenance et libération.
- Tests existants : `hotel_concurrency.php`, housekeeping, GiST PostgreSQL, contrôles RLS, Playwright général.
- Gate de fusion : CI intégrale verte + absence de conflit de branche. Aucune fusion ni déploiement sur `main`.

## Limites explicites

Ne pas annoncer ce périmètre comme moteur de réservation externe ou comme intégration PMS/CRS. Ne pas fermer #72 avant intégration effective des canaux externes et validation fonctionnelle du flux de bout en bout. Le check-out, les folios, la fermeture de journée et les métiers de réception délégués restent à développer et à faire valider par le métier.
