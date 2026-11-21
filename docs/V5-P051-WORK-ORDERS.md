# ATLAS ONE V5 — P051.01, P051.04, P051.05

DI qualifiée → BT unique et idempotent, BT open → in_progress → completed avec résolution obligatoire. Verrouillage de la DI, contrainte UNIQUE(company_id,maintenance_request_id), FK composites et audit. Rôles admin provisoires; délégation technicien/approbation de remise en service, BT parent/enfant, stocks/temps et contrôle qualité restant à faire. Pas de fusion avant CI, tests PostgreSQL et revue staging.
