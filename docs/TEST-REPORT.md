# Rapport de vérification

Dernière vérification fonctionnelle : **16 novembre 2026**. Capture navigateur et contrôle PDF : **14 novembre 2026**.

## Résultats réellement obtenus

| Contrôle | Résultat observé |
|---|---|
| Migrations et seeder PostgreSQL | Exécutés avec succès sur une base isolée |
| PHPUnit, PostgreSQL | **21 tests, 162 assertions, zéro échec** |
| Concurrence du stock | Deux processus demandent la dernière unité : un succès, un refus, stock 0, un mouvement |
| Concurrence de numérotation | Quatre créations simultanées réussies, aucun numéro dupliqué |
| Smoke HTTP réel | **29 contrôles réussis**, sessions et CSRF actifs |
| TypeScript | `npm run typecheck` réussi |
| Next.js production | `npm run build` réussi ; version compilée servie et consultée |
| OpenAPI | 41 opérations ; validé par `openapi-spec-validator` |
| Docker Compose | Configuration principale et variante monitoring acceptées par `docker compose config --quiet` |
| YAML infrastructure | 7 fichiers parsés sans erreur ; ce n’est pas une validation de déploiement Kubernetes/Ansible |
| PDF | Fichier téléchargé depuis l’interface, signature PDF vérifiée ; première page rendue avec Poppler et inspectée visuellement |

## Couverture métier automatisée

- Authentification, déconnexion, droits lecture seule et séparation Commercial/Stock.
- CRUD clients/fournisseurs, doublons, recherche paramétrée, pagination et historique.
- Catalogue, stock initial nul, ajustements, refus du stock négatif, suppression protégée.
- Arrondis monétaires, TVA à 5,5 %, zéro TVA, grands montants et totaux imposés par le serveur.
- Devis converti une seule fois et conservation des prix d’origine.
- Validation unique de commande et rollback de toutes les lignes si un produit manque.
- Facturation réservée aux commandes validées, unicité par commande et numérotation.
- Transitions de facture, conservation des snapshots, PDF et annulation sans remise en stock.
- Période du dashboard, CA, encours et produits vendus.
- Gestion utilisateurs, protection du dernier administrateur, audit sans mot de passe.
- Contraintes SQL PostgreSQL, identifiants inconnus et tentative d’injection dans la recherche.
- Monitoring : jeton obligatoire, absence de configuration refusée, format de jauges.

Le smoke HTTP exerce une vraie connexion et rejette une connexion sans CSRF avec HTTP 419. Il crée un client, deux produits, leurs entrées de stock, un devis à deux lignes, une commande, un PDF numéroté et une facture payée. Il vérifie aussi les doubles actions refusées et le HTTP 401 après déconnexion. Il ne remplace pas le contrôle visuel du navigateur.

## Parcours navigateur effectué

1. Connexion administrateur, affichage des données PostgreSQL.
2. Création de « Studio Démonstration ».
3. Devis : 2 bureaux à 349 € HT et 1 caisson à 129 € HT.
4. Conversion puis validation : stocks observés de 27 à 25 et de 17 à 16.
5. Facture `FAC-2026-00006` : 827 € HT, 165,40 € TVA, **992,40 € TTC**.
6. Téléchargement du PDF, puis passage à « Payée ».
7. Contrôle à 390 × 844 : pas de débordement horizontal du document (largeur utile et largeur déroulante égales, 375 px avec scrollbar).
8. Déconnexion, connexion lecteur : absence des boutons de création client et d’administration.
9. Retour administrateur et capture du dashboard de la version compilée.

Les captures sont dans `docs/dashboard.jpg` et `docs/mobile.jpg`. La capture mobile provient du serveur de développement ; la capture desktop finale provient de la version compilée. Le PDF conservé reflète son état au téléchargement (« Émise »), avant le passage à « Payée ».

## Environnement et incidents résolus

Windows, Node.js 24.16.0, PHP 8.4.27 portable, PostgreSQL 17.10, Laravel 12.72.0, Next.js 16.4.6. Une instance PostgreSQL propre au projet écoute sur `127.0.0.1:55432`, avec des données fictives uniquement. Les bases de l’utilisateur n’ont pas été utilisées.

Les contrôles de droits de fichiers de PHP retournaient de faux négatifs dans l’environnement Windows restreint, alors que les lectures/écritures réelles réussissaient. Un adaptateur **local au dossier de travail**, absent des sources livrées et des images Docker, a remplacé ces sondes par de vraies ouvertures de fichiers pendant les tests. Les règles métier, verrous et requêtes SQL sont ceux du code livré. Linux/PHP-FPM reste à vérifier indépendamment.

Un premier build `standalone` a échoué lors de copies de fichiers sous Windows. Le projet utilise maintenant le mode serveur Next.js standard ; son build a réussi. Après la reprise du 16 novembre, certains tests ont d’abord échoué faute d’accès au serveur PostgreSQL lancé avant rétablissement des permissions réseau. Les services ont été relancés, puis les résultats ci-dessus ont été obtenus.

## Non exécuté ou non démontré

- Construction des Dockerfiles et démarrage de la stack conteneurisée.
- Pipeline sur un serveur Jenkins, playbook Ansible et déploiement Kubernetes/AWS.
- Collecte réelle Prometheus et rendu du dashboard Grafana ; seul l’endpoint applicatif est testé.
- Exécution CLI des deux tests Playwright fournis. Les fichiers passent TypeScript ; le parcours a été réalisé via le navigateur piloté, sans prétendre à une exécution Playwright.
- Tests sur Safari/Firefox, charge importante, audit de sécurité externe, restauration de sauvegarde et validation comptable.
- Suite SQLite non relancée : les résultats annoncés concernent PostgreSQL.

Le moteur Docker local étant indisponible, aucune fonctionnalité d’infrastructure n’est annoncée opérationnelle sur la seule base de sa configuration.
