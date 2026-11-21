# ATLAS ONE V5 — P131.02 / P131.06 — Anti-surbooking (incrément)

- Confirmation et annulation verrouillées en transaction par réservation et chambre.
- PostgreSQL: exclusion GiST tenant/chambre et dates de séjour demi-ouvertes `[arrivée, départ[`, pour statuts `confirmed` et `checked_in`. Le contrôle ne repose pas sur un simple `SELECT` applicatif.
- Seuls les brouillons sont confirmables. Une annulation retire le séjour de la contrainte. Pas de confirmation automatique depuis un canal ou une source externe.
- Cas testés: chevauchement, même-journée de turnover, double confirmation, annulation et séparation entre tenants; test SQL PostgreSQL contournant l'API.
- Non couverts: inventaire par catégorie, séjours multi-chambres, no-show, paiements, channel manager, fermeture de nuitée et performance haute concurrence. Revue sécurité et test concurrent multiconnexions restent nécessaires.
- DDL nécessite `btree_gist` sur la base cible. Vérifier permissions d'extension et migration sur copie staging avant promotion.
