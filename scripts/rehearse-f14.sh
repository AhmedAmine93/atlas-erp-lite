#!/usr/bin/env bash
# F14 synthetic PostgreSQL migration and logical restore rehearsal.
# Run only in the disposable CI Docker Compose project after DB is healthy.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
dc() { docker compose -f compose.yaml -f compose.ci.yaml "$@"; }
legacy_db="atlas_f14_legacy"
restored_db="atlas_f14_restored"
archive="$(mktemp)"
cleanup() {
  rm -f "$archive"
  dc exec -T db dropdb --if-exists -U atlas "$restored_db" >/dev/null 2>&1 || true
  dc exec -T db dropdb --if-exists -U atlas "$legacy_db" >/dev/null 2>&1 || true
}
trap cleanup EXIT

dc exec -T db createdb -U atlas "$legacy_db"
# shellcheck disable=SC2016 # Intentionally evaluated in the container, not by the host.
dc run --rm -e DB_DATABASE="$legacy_db" tests sh -ec '
  set -eu
  mkdir -p /tmp/atlas-f14-pre-upgrade
  for file in database/migrations/*.php; do
    case "$file" in
      *000005_add_tenant_composite_fks.php|*000006_tenant_ownership_on_document_items.php) ;;
      *) cp "$file" /tmp/atlas-f14-pre-upgrade/ ;;
    esac
  done

  # Simulate populated F13 database before F14 is applied.
  php artisan migrate --force --realpath --path=/tmp/atlas-f14-pre-upgrade
  php artisan db:seed --force

  # F14 must upgrade populated tables, and running migrations again is safe.
  php artisan migrate --force
  php artisan migrate --force
'

dc exec -T db pg_dump -U atlas -Fc "$legacy_db" > "$archive"
dc exec -T db createdb -U atlas "$restored_db"
dc exec -T db pg_restore -U atlas --no-owner --no-acl -d "$restored_db" < "$archive"

count_sql="SELECT (SELECT count(*) FROM customers)::text || ':' ||
 (SELECT count(*) FROM quotes)::text || ':' ||
 (SELECT count(*) FROM quote_items)::text || ':' ||
 (SELECT count(*) FROM orders)::text || ':' ||
 (SELECT count(*) FROM order_items)::text || ':' ||
 (SELECT count(*) FROM invoices)::text || ':' ||
 (SELECT count(*) FROM stock_movements)::text"
pre="$(dc exec -T db psql -U atlas -d "$legacy_db" -Atc "$count_sql" | tr -d '\r')"
post="$(dc exec -T db psql -U atlas -d "$restored_db" -Atc "$count_sql" | tr -d '\r')"
if [[ "$pre" != "$post" || -z "$pre" ]]; then
  echo "F14 restore count mismatch: original=$pre restored=$post" >&2
  exit 1
fi

fk_count="$(dc exec -T db psql -U atlas -d "$restored_db" -Atc \
 "SELECT count(*) FROM pg_constraint WHERE conname LIKE 'f14_%_tenant_fk'" | tr -d '\r')"
if [[ "$fk_count" -lt 12 ]]; then
  echo "F14 restore missing composite FKs; count=$fk_count" >&2
  exit 1
fi

dc run --rm -e DB_DATABASE="$restored_db" tests php artisan migrate --force

mkdir -p reports
printf 'F14 SYNTHETIC MIGRATION + RESTORE: PASS\ncounts=%s\nfk_count=%s\n' \
  "$post" "$fk_count" > reports/f14-rehearsal.txt
echo "F14 rehearsal passed: $post; $fk_count composite tenant FKs on restored PostgreSQL."
