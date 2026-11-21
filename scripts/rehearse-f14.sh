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
      *000005_add_tenant_composite_fks.php|*000006_tenant_ownership_on_document_items.php|*000007_pilot_incident_readonly_rls.php|*000008_create_runtime_db_privilege_boundary.php|*000009_harden_runtime_write_privileges.php) ;;
      *) cp "$file" /tmp/atlas-f14-pre-upgrade/ ;;
    esac
  done

  # Create the F13 schema only; new Eloquent models expect F14 fields,
  # so they cannot be used to seed historical tables.
  php artisan migrate --force --realpath --path=/tmp/atlas-f14-pre-upgrade
'

# Exercise the ACTUAL pre-F14 layout with raw, fictional SQL. In particular,
# quote_items and order_items intentionally have NO company_id column yet.
dc exec -T db psql -U atlas -d "$legacy_db" -v ON_ERROR_STOP=1 <<'SQL'
INSERT INTO users (name, email, password, role_id, created_at, updated_at)
VALUES ('F14 historical actor', 'f14-historic@atlas.example', 'not-a-real-password-hash', 1, now(), now());

INSERT INTO company_user (company_id, user_id, role_id)
SELECT c.id, u.id, 1 FROM companies c, users u
WHERE c.code = 'ATLAS_DEMO' AND u.email = 'f14-historic@atlas.example';

INSERT INTO customers (company_id, name, email, created_at, updated_at)
SELECT id, 'F14 historical customer', 'f14-historic-customer@atlas.example', now(), now()
FROM companies WHERE code = 'ATLAS_DEMO';

INSERT INTO products (company_id, reference, name, category, price_cents, vat_bps, stock, alert_threshold, created_at, updated_at)
SELECT id, 'F14-HISTORIC', 'F14 historical product', 'Synthetic', 1000, 2000, 10, 1, now(), now()
FROM companies WHERE code = 'ATLAS_DEMO';

INSERT INTO quotes (company_id, number, customer_id, status, subtotal_cents, tax_cents, total_cents, created_by, created_at, updated_at)
SELECT c.company_id, 'DEV-F14-HIST', c.id, 'draft', 1000, 200, 1200, u.id, now(), now()
FROM customers c JOIN users u ON u.email = 'f14-historic@atlas.example'
WHERE c.email = 'f14-historic-customer@atlas.example';

INSERT INTO quote_items (quote_id, product_id, reference, name, quantity, price_cents, vat_bps, subtotal_cents, tax_cents, total_cents)
SELECT q.id, p.id, p.reference, p.name, 1, 1000, 2000, 1000, 200, 1200
FROM quotes q JOIN products p ON p.company_id = q.company_id
WHERE q.number = 'DEV-F14-HIST' AND p.reference = 'F14-HISTORIC';

INSERT INTO orders (company_id, number, customer_id, quote_id, status, subtotal_cents, tax_cents, total_cents, created_by, created_at, updated_at)
SELECT q.company_id, 'CMD-F14-HIST', q.customer_id, q.id, 'draft', 1000, 200, 1200, q.created_by, now(), now()
FROM quotes q WHERE q.number = 'DEV-F14-HIST';

INSERT INTO order_items (order_id, product_id, reference, name, quantity, price_cents, vat_bps, subtotal_cents, tax_cents, total_cents)
SELECT o.id, p.id, p.reference, p.name, 1, 1000, 2000, 1000, 200, 1200
FROM orders o JOIN products p ON p.company_id = o.company_id
WHERE o.number = 'CMD-F14-HIST' AND p.reference = 'F14-HISTORIC';

INSERT INTO invoices (company_id, order_id, status, snapshot, subtotal_cents, tax_cents, total_cents, created_at, updated_at)
SELECT o.company_id, o.id, 'draft', '{"demo":true}', 1000, 200, 1200, now(), now()
FROM orders o WHERE o.number = 'CMD-F14-HIST';

INSERT INTO stock_movements (company_id, product_id, quantity, balance_after, reason, order_id, user_id, created_at, updated_at)
SELECT o.company_id, p.id, 1, 10, 'F14 synthetic historic', o.id, u.id, now(), now()
FROM orders o JOIN products p ON p.company_id = o.company_id
JOIN users u ON u.email = 'f14-historic@atlas.example'
WHERE o.number = 'CMD-F14-HIST' AND p.reference = 'F14-HISTORIC';

INSERT INTO support_incidents (company_id, reference, title, description, severity, status, environment, reported_by, created_at, updated_at)
SELECT c.id, 'INC-F14-HIST', 'F14 historical incident', 'Synthetic only', 'P3', 'open', 'demo', u.id, now(), now()
FROM companies c JOIN users u ON u.email = 'f14-historic@atlas.example'
WHERE c.code = 'ATLAS_DEMO';

INSERT INTO support_incident_events (company_id, support_incident_id, actor_id, action, created_at, updated_at)
SELECT i.company_id, i.id, u.id, 'created', now(), now()
FROM support_incidents i JOIN users u ON u.email = 'f14-historic@atlas.example'
WHERE i.reference = 'INC-F14-HIST';

INSERT INTO support_diagnostic_runs (company_id, support_incident_id, triggered_by, check_name, status, duration_ms, created_at, updated_at)
SELECT i.company_id, i.id, u.id, 'database', 'ok', 1, now(), now()
FROM support_incidents i JOIN users u ON u.email = 'f14-historic@atlas.example'
WHERE i.reference = 'INC-F14-HIST';
SQL

# Migrate populated historical schema, then verify migration idempotence.
dc run --rm -e DB_DATABASE="$legacy_db" tests sh -ec '
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
if [[ "$pre" != "$post" ]]; then
  echo "F14 restore count mismatch: original=$pre restored=$post" >&2
  exit 1
fi
if [[ "$post" != "1:1:1:1:1:1:1" ]]; then
  echo "F14 synthetic fixture contains unexpected row counts: $post" >&2
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
