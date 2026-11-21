#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."

# F17 is a destructive/authentication test for the throwaway CI stack ONLY.
# Never call this against production or any persistent staging database.
if [[ "$COMPOSE_PROJECT_NAME" != atlas-ci* ]]; then
  echo 'F17 blocked: disposable atlas-ci Compose project required.' >&2
  exit 2
fi

dc_owner() { docker compose -f compose.yaml -f compose.ci.yaml "$@"; }
dc_runtime() { docker compose -f compose.yaml -f compose.ci.yaml -f compose.staging.yaml "$@"; }

# Unique ephemeral password, never written to Git or to a CI report.
runtime_password="$(node -e 'process.stdout.write(require("node:crypto").randomBytes(32).toString("hex"))')"
export ATLAS_STAGING_RUNTIME_PASSWORD="$runtime_password"
errfile="$(mktemp)"
trap 'rm -f "$errfile"; unset runtime_password ATLAS_STAGING_RUNTIME_PASSWORD' EXIT

# Migration/seed already ran as the owner in the disposable CI database.
# Pipe the secret into psql on STDIN; do not log commands or shell trace.
printf "ALTER ROLE atlas_app_runtime LOGIN PASSWORD '%s';\n" "$runtime_password" \
  | dc_owner exec -T db psql -U atlas -d atlas -X -v ON_ERROR_STOP=1 >/dev/null

runtime_psql() {
  dc_owner exec -T -e PGPASSWORD="$runtime_password" db \
    psql -h 127.0.0.1 -U atlas_app_runtime -d atlas \
      -X -v ON_ERROR_STOP=1 -v VERBOSITY=verbose "$@"
}

login="$(runtime_psql -tAX -c "SELECT session_user || ':' || current_user;")"
if [[ "$login" != atlas_app_runtime:atlas_app_runtime ]]; then
  echo 'F17 BLOCKER: separate authenticated PostgreSQL runtime login was not used.' >&2
  exit 1
fi

expect_denied() {
  local label="$1" sql="$2"
  if runtime_psql -c "$sql" >"$errfile" 2>&1; then
    echo "F17 BLOCKER: runtime permitted $label" >&2
    exit 1
  fi
  if ! grep -q '42501' "$errfile"; then
    echo "F17 BLOCKER: $label failed for an unexpected reason." >&2
    cat "$errfile" >&2
    exit 1
  fi
  echo "F17 PASS: $label rejected with SQLSTATE 42501"
}

expect_denied 'migrations SELECT' 'SELECT COUNT(*) FROM public.migrations;'
expect_denied 'schema CREATE' 'CREATE TABLE public.atlas_f17_forbidden (id integer);'
expect_denied 'table ALTER' 'ALTER TABLE public.companies ADD COLUMN atlas_f17_forbidden integer;'
expect_denied 'table DROP' 'DROP TABLE public.companies;'

# The F15 reader must remain a narrower SQL role, with zero rows when
# atlas.tenant_id is absent, even after an authenticated runtime login.
n3_count="$(runtime_psql -tAX -c \
  "BEGIN; SET LOCAL ROLE atlas_f15_incident_reader; SELECT current_user || ':' || COUNT(*)::text FROM public.support_incidents; ROLLBACK;")"
if ! grep -qx 'atlas_f15_incident_reader:0' <<<"$n3_count"; then
  echo 'F17 BLOCKER: F15 reader did not fail closed without tenant context.' >&2
  exit 1
fi
expect_denied 'N3 reader access to users' \
  'BEGIN; SET LOCAL ROLE atlas_f15_incident_reader; SELECT COUNT(*) FROM public.users;'

# Recreate services in dependency order so nginx does not retain the old
# backend address. The db and privileged one-shot init are not recreated.
dc_runtime up -d --no-deps --force-recreate backend
dc_runtime up -d --no-deps --force-recreate api-web
dc_runtime up -d --no-deps --force-recreate frontend
dc_runtime up -d --no-deps --wait --wait-timeout 240 api-web frontend
dc_runtime exec -T backend php artisan atlas:verify-runtime-db

mkdir -p reports
printf 'F17 actual PostgreSQL password authentication: PASS\nF17 migration/DDL denial and F15 no-context RLS: PASS\nF17 Laravel connected identity: PASS\n' > reports/f17-runtime.txt
echo 'F17 PASS: authenticated runtime is active. Next CI smoke and Playwright run with this identity.'
