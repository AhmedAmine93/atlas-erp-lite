#!/usr/bin/env bash
# Run on the Linux host. /opt/atlas/.env is provisioned outside GitHub.
set -Eeuo pipefail
umask 077
release=$(realpath "${1:?Pass the release directory}")
[[ "$release" =~ ^/opt/atlas/releases/[a-f0-9]{40}$ ]] || { echo 'Invalid release path' >&2; exit 1; }
root=/opt/atlas
test -f "$root/.env"
test -f "$release/release.env"
exec 9>"$root/deploy.lock"
flock -n 9 || { echo 'Deployment already running' >&2; exit 1; }
# Never source an artifact as executable shell commands.
grep -Eq '^RELEASE_SHA=[a-f0-9]{40}$' "$release/release.env"
for component in BACKEND FRONTEND; do
  grep -Eq "^${component}_IMAGE=ghcr.io/[a-z0-9_./-]+@sha256:[a-f0-9]{64}$" "$release/release.env"
done
dc() { docker compose -p atlas-prod --env-file "$root/.env" --env-file "$release/release.env" -f "$release/compose.production.yaml" "$@"; }
previous=$(readlink -f "$root/current" || true)
dc config --quiet
dc --profile tools pull
dc up -d --wait --wait-timeout 120 db
mkdir -p "$root/backups"
backup="$root/backups/$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
# Halt writes before taking a consistent backup and applying migrations.
dc stop frontend api-web backend
rollback() {
  result=$?
  trap - ERR
  echo "Deployment failed. Database backup: $backup" >&2
  if [[ -n "$previous" && -f "$previous/release.env" ]]; then
    docker compose -p atlas-prod --env-file "$root/.env" --env-file "$previous/release.env" -f "$previous/compose.production.yaml" up -d --no-deps --force-recreate --wait --wait-timeout 180 backend api-web frontend || true
  else
    dc stop frontend api-web backend || true
  fi
  echo 'Database is NOT automatically restored. Inspect migration compatibility before recovery.' >&2
  exit "$result"
}
trap rollback ERR
dc exec -T db pg_dump -U atlas atlas | gzip > "$backup"
dc run --rm --no-deps migrate
dc up -d --wait --wait-timeout 240
endpoint=$(dc port frontend 3000)
status=$(curl --silent --show-error --max-time 15 -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "http://$endpoint/api/me")
[[ "$status" == 401 ]]
if [[ -n "$previous" ]]; then ln -sfn "$previous" "$root/previous"; fi
ln -sfn "$release" "$root/current"
trap - ERR
echo "Release $(basename "$release") healthy. Backup: $backup"
