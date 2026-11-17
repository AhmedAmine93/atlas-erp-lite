# Déploiement et exploitation

État au 16 novembre 2026 : les fichiers ci-dessous sont fournis pour démonstration. Docker Compose a passé `config --quiet`. Les images, Jenkins, Ansible, Kubernetes, Prometheus et Grafana n’ont pas été exécutés dans cette session : le moteur Docker local est indisponible. Aucun déploiement cloud n’a été effectué.

## Docker Compose

Prérequis : Docker Engine/Desktop actif, Compose v2 récent, Node.js 24 pour générer les secrets locaux.

```sh
node scripts/setup.mjs
docker compose config --quiet
docker compose up --build -d --wait --wait-timeout 180
docker compose ps
docker compose logs init
```

Ouvrir http://localhost:3100. Les ports sont limités à la boucle locale. PostgreSQL et PHP-FPM ne sont pas exposés. `init` attend PostgreSQL, applique les migrations puis charge les données si `SEED_DEMO=true`. Le seeder ne recharge pas un jeu déjà présent. Ne pas utiliser `migrate:fresh` sur une base à conserver.

Le Dockerfile backend sépare désormais les étages `test` et `production` (sans dépendances dev). La CI exécute les tests sur un conteneur dédié et les parcours HTTP/navigateur sur les images de production. La livraison utilise les digests des images effectivement testées. Voir [CI-CD.md](CI-CD.md).

```sh
docker compose exec -T db createdb -U atlas atlas_test
docker compose -f compose.yaml -f compose.ci.yaml run --build --rm tests php artisan test
docker compose -f compose.yaml -f compose.ci.yaml run --rm tests php tests/concurrency.php
ATLAS_URL=http://localhost:3100 node scripts/smoke.mjs
docker compose stop
```

La base `atlas_test` est jetable. Le test de concurrence la reconstruit après vérification du suffixe `_test`. Le smoke HTTP ajoute des données fictives à la base de démonstration.

Les données PostgreSQL vivent dans le volume `pgdata`. `docker compose down` conserve ce volume ; **`docker compose down -v` le supprime**.

## Jenkins

Le `Jenkinsfile` est un adaptateur facultatif vers le même `scripts/ci.sh` que GitHub Actions. Prérequis : agent Linux `docker-linux`, Node.js 24, Docker Compose et dépendances système Chromium (l'installation Playwright utilise sudo). Il archive les résultats PHPUnit et Playwright. Il ne publie pas d'images : GitHub Actions reste la voie de livraison de référence. Aucun Jenkins n'est actuellement fourni ni testé.

## Ansible

Le playbook cible Ubuntu 24.04. Il installe Docker, Compose, Git et les certificats, active Docker et prépare `/opt/atlas`. Il ne copie aucun secret et ne modifie pas le pare-feu.

```sh
cp infra/ansible/inventory.example.ini infra/ansible/inventory.ini
# Renseigner l’hôte et l’utilisateur SSH.
ansible-playbook -i infra/ansible/inventory.ini infra/ansible/prepare.yml --check
ansible-playbook -i infra/ansible/inventory.ini infra/ansible/prepare.yml
```

Pour installer les sources sans Node sur le serveur : copier le dépôt et le fichier `.env` créé localement via un canal privé, puis lancer Compose avec `sudo`. Conserver `.env` hors Git avec les permissions 600. L’accès au groupe Docker donne des privilèges élevés ; le playbook n’y ajoute pas automatiquement d’utilisateur.

## Kubernetes démonstratif

Prérequis : cluster de test Kind/Minikube avec stockage dynamique et `kubectl`. Aucun Ingress public n’est créé. PostgreSQL est mono-instance et les pods applicatifs ne sont pas configurés pour une exploitation hautement disponible.

```sh
docker build -t atlas-erp-lite-backend:local backend
docker build --build-arg API_INTERNAL_URL=http://api-web -t atlas-erp-lite-frontend:local frontend
# Kind :
kind load docker-image atlas-erp-lite-backend:local atlas-erp-lite-frontend:local
kubectl apply -f infra/k8s/00-base.yaml
```

Créer hors Git un fichier `atlas-secrets.env` contenant `APP_KEY`, `DB_PASSWORD`, `DEMO_PASSWORD` et `METRICS_TOKEN`, à partir de `.env`. Puis :

```sh
kubectl -n atlas create secret generic atlas-secrets --from-env-file=atlas-secrets.env
kubectl -n atlas rollout status statefulset/db --timeout=180s
kubectl apply -f infra/k8s/10-migrate.yaml
kubectl -n atlas wait --for=condition=complete job/atlas-migrate --timeout=180s
kubectl apply -f infra/k8s/20-app.yaml
kubectl -n atlas rollout status deployment/backend --timeout=180s
kubectl -n atlas rollout status deployment/frontend --timeout=180s
kubectl -n atlas port-forward service/frontend 3100:3000
```

Le job de migration est exécuté une fois. Pour une nouvelle version, créer un nouveau nom de job ou supprimer uniquement le job terminé avant de le relancer. Les cookies non sécurisés sont uniquement prévus pour le tunnel HTTP local. Pour un Ingress HTTPS, activer `SESSION_SECURE_COOKIE=true` et adapter `APP_URL`.

## Prometheus et Grafana

```sh
node scripts/setup.mjs
docker compose -f compose.yaml -f compose.monitoring.yaml config --quiet
docker compose -f compose.yaml -f compose.monitoring.yaml up -d
```

Prometheus : http://localhost:9090 ; Grafana : http://localhost:3101. Identifiant Grafana `admin`, mot de passe généré dans `.env` (`GRAFANA_PASSWORD`). Le dashboard est provisionné automatiquement.

`/metrics` requiert un jeton Bearer dédié et est désactivé si `METRICS_TOKEN` est absent. Il expose trois jauges : commandes validées, encours en centimes et produits en alerte. `up{job="atlas"}` mesure le succès de collecte. Aucun nom de client ni identifiant utilisateur n’est exposé. Ce socle ne mesure pas encore latence, taux d’erreur HTTP, CPU ou mémoire. Le fichier de jeton est lisible par le conteneur, dans un dossier privé sur l’hôte ; ne pas l’ajouter à Git.

## AWS : démonstration privée sur EC2

Guide de préparation, non exécuté. Pas d’achat ni de création de ressources dans cette session.

1. Choisir une VM Ubuntu 24.04 avec au moins 2 vCPU et 4 Go de RAM pour les builds. Utiliser une clé SSH privée et un rôle IAM minimal si des services AWS sont nécessaires.
2. Limiter le Security Group au SSH depuis votre IP, ou utiliser Systems Manager. Aucun port PostgreSQL, FPM ou monitoring ne doit être public.
3. Préparer l’hôte avec Ansible, copier le projet et les secrets, lancer Compose. Vérifier les logs et `/up` via un tunnel.
4. Ouvrir `ssh -L 3100:127.0.0.1:3100 ubuntu@IP_EC2`, puis http://localhost:3100. Cela suffit pour une démonstration privée.
5. Avant toute exposition publique : HTTPS via reverse proxy/ALB, cookies sécurisés, suppression des comptes de démonstration, secrets gérés, sauvegarde/restauration éprouvée et revue de sécurité. Le backend n’implémente pas encore MFA ni récupération de mot de passe.
6. Sauvegarder PostgreSQL avec `pg_dump` et tester la restauration sur une autre base. Pour un service durable, déplacer PostgreSQL vers RDS privé, utiliser ECR et un processus de livraison versionné. Arrêter les ressources de démonstration après usage.

## Références

- [Ordre de démarrage Compose](https://docs.docker.com/compose/how-tos/startup-order/)
- [Sondes Kubernetes](https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/)
- [Configuration Prometheus](https://prometheus.io/docs/prometheus/latest/configuration/configuration/)
