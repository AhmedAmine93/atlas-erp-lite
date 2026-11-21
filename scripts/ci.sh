#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."
root=$PWD
: "${COMPOSE_PROJECT_NAME:=atlas-ci}"
export COMPOSE_PROJECT_NAME
dc=(docker compose -f compose.yaml -f compose.ci.yaml)
mkdir -p reports
chmod 777 reports # Disposable CI directory, writable by container www-data.
cleanup() {
  result=$?
  cd "$root"
  "${dc[@]}" logs --no-color > reports/compose.log 2>&1 || true
  "${dc[@]}" down -v --remove-orphans || true
  exit "$result"
}
trap cleanup EXIT
node scripts/setup.mjs
DEMO_PASSWORD=$(node --input-type=module -e "import fs from 'node:fs'; process.stdout.write(fs.readFileSync('.env','utf8').match(/^DEMO_PASSWORD=(.+)$/m)[1].trim())")
export DEMO_PASSWORD
"${dc[@]}" config --quiet
# Structural verification only: no credentials are created and no staging
# services are started. The placeholder exists solely for Compose parsing.
ATLAS_STAGING_RUNTIME_PASSWORD=ci-config-only-not-a-real-password \
  docker compose -f compose.yaml -f compose.staging.yaml config --quiet
"${dc[@]}" --profile test build --pull
"${dc[@]}" up -d --wait --wait-timeout 240
"${dc[@]}" exec -T db createdb -U atlas atlas_test
"${dc[@]}" run --rm tests vendor/bin/phpunit --log-junit /reports/phpunit.xml
bash scripts/rehearse-f14.sh
bash scripts/verify-rc1.sh
# F17: ephemeral authenticated runtime login and negative SQL privilege tests.
# Following HTTP/browser tests now run against the restricted runtime service.
bash scripts/verify-f17.sh
"${dc[@]}" run --rm tests php tests/concurrency.php
# Separate dedicated browser account; login throttling remains intact.
"${dc[@]}" exec -T -e ATLAS_BROWSER_E2E_CONFIRM=YES_ISOLATED_CI backend php artisan db:seed --class=BrowserE2ESeeder --force
"${dc[@]}" run --rm tests composer validate --strict
"${dc[@]}" run --rm tests composer audit --locked
"${dc[@]}" exec -T frontend npm audit --omit=dev
export ATLAS_URL="http://127.0.0.1:${WEB_PORT:-3100}"
node scripts/smoke.mjs
cd frontend
npm ci --no-fund
npx playwright install --with-deps chromium
npm run test:e2e
