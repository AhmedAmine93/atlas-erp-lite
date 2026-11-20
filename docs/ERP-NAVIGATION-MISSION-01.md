# Mission 01 — Fondations de navigation ATLAS ERP 2.0

## Portée

- 16 sections métier (dont CRM, ventes, achats, stocks, finance, GMAO, RH, projets, ITSM, qualité, BI, intégrations, IA, administration et exploitation).
- 101 entrées de menu au total, dont **10 vues existantes** fonctionnelles.
- Navigation repliable par section et recherche par nom de menu ou de section.
- Les **91 fonctions futures** sont volontairement désactivées et identifiées par « À venir » : aucun faux écran, aucun appel API fictif.
- Les sections Administration & Sécurité sont masquées pour les non-administrateurs, comme auparavant pour Utilisateurs et Journal d'activité.
- Les contrôles de permission métiers existants restent côté frontend et surtout côté Laravel. Les nouvelles pages nécessiteront des politiques serveur dédiées.
- Aucun changement de modèle de données, migrations, endpoints ou données réelles.

## Fichiers

- `frontend/src/lib/navigation.ts` : configuration et état de disponibilité des vues.
- `frontend/src/components/Workspace.tsx` : nouvelle navigation, recherche et repli des sections.
- `frontend/src/app/globals.css` : styles dédiés.

## Vérifications demandées avant fusion

```sh
cd frontend
npm ci
npm run typecheck
npm run build
```

Exécuter aussi les tests Laravel et les tests Playwright existants sur une installation avec services actifs et `DEMO_PASSWORD` défini. Les parcours historiques doivent continuer à accéder aux boutons « Clients », « Devis », « Commandes » et « Factures ».

## Suite

Mission 02 : référentiel Entreprises / établissements / sites, avec tests d'isolation et permissions multi-tenant, avant de déployer les modules métiers.

> Aucun test local n'a pu être exécuté depuis cet environnement, faute d'accès réseau pour cloner le dépôt. Ne pas fusionner avant validation des tests automatiques.
