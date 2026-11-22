# ATLAS ONE V5 — Données fictives complémentaires GMAO et Hôtellerie

Le générateur existant MassiveDemoSeeder est enrichi pour les modules P053 et P132, sans toucher aux données métiers réelles. Les garde-fous existants restent obligatoires : environnement local/testing, PostgreSQL atlas dédié, confirmation ATLAS_DEMO_DATA_CONFIRM=YES_LOCAL_ONLY.

Après un seul passage dans une base locale isolée, le jeu de démonstration contient **72 plans préventifs calendaires** (24 par société), **216 missions de nettoyage** (3 hôtels x 24 chambres x 3 sociétés) et **540 événements d'avancement** représentant des états répartis entre dirty, in_progress, clean et inspected.

Les trois sociétés restent séparées par company_id ; les missions pointent vers la chambre et l'hôtel de la même société. Chaque événement est enregistré une seule fois à chaque étape, avec validation de l'existence préalable pour préserver l'idempotence. L'horodatage de journée d'exploitation est calculé en Europe/Paris pour ce jeu fictif.

La CI synthetic-load vérifie ces trois volumes **après deux exécutions consécutives** du seeder, puis effectue à nouveau le test de charge de 120 utilisateurs. Ne jamais exécuter ce seeder sur une base de production ni sur une base hôtelière réelle.

Les données sont des scénarios de démonstration, pas des certificats de conformité : les inspections sont simulées, les rapports d'opérations préventives ne sont pas générés automatiquement, et aucun client réel n'est utilisé.