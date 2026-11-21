#!/usr/bin/env bash
# ATLAS ONE RC1: read-only PostgreSQL structural release preflight.
# Run inside the disposable CI compose project AFTER migrations and PHPUnit.
set -Eeuo pipefail
cd "$(dirname "$0")/.."
dc=(docker compose -f compose.yaml -f compose.ci.yaml)
dbname="${RC_DB_NAME:-atlas_test}"

# Restrict use to synthetic CI/test databases; no live database modifications.
[[ "$dbname" == *_test ]] || {
  echo "Refusing RC1 preflight outside a *_test database." >&2
  exit 2
}

scalar() {
  "${dc[@]}" exec -T db psql -X -U atlas -d "$dbname" -At -v ON_ERROR_STOP=1 -c "$1" | tr -d '\r'
}
assert_eq() {
  local name="$1" actual="$2" expected="$3"
  if [[ "$actual" != "$expected" ]]; then
    echo "RC1 BLOCKER: $name expected=$expected actual=$actual" >&2
    exit 1
  fi
  echo "RC1 OK: $name = $actual"
}

# The obsolete, non-tenant PR #11 migration must not be reintroduced.
if [[ -f backend/database/migrations/2026_11_19_000001_create_support_incidents_table.php ]]; then
  echo "RC1 BLOCKER: obsolete global incident migration from PR #11" >&2
  exit 1
fi

assert_eq "validated F14 composite foreign keys" "$(scalar "SELECT COUNT(*) FROM pg_constraint WHERE contype = 'f' AND convalidated AND conname LIKE 'f14_%_tenant_fk'")" 12
assert_eq "required membership roles not null" "$(scalar "SELECT COUNT(*) FROM company_user WHERE role_id IS NULL")" 0
assert_eq "document lines crossing tenant boundaries" "$(scalar "SELECT COUNT(*) FROM quote_items qi JOIN quotes q ON q.id=qi.quote_id WHERE qi.company_id IS DISTINCT FROM q.company_id")" 0
assert_eq "order lines crossing tenant boundaries" "$(scalar "SELECT COUNT(*) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.company_id IS DISTINCT FROM o.company_id")" 0
assert_eq "support events crossing tenant boundaries" "$(scalar "SELECT COUNT(*) FROM support_incident_events ev JOIN support_incidents i ON i.id=ev.support_incident_id WHERE ev.company_id IS DISTINCT FROM i.company_id")" 0
assert_eq "diagnostic runs crossing tenant boundaries" "$(scalar "SELECT COUNT(*) FROM support_diagnostic_runs d JOIN support_incidents i ON i.id=d.support_incident_id WHERE d.company_id IS DISTINCT FROM i.company_id")" 0

assert_eq "incident table RLS enabled" "$(scalar "SELECT relrowsecurity::int FROM pg_class WHERE oid='public.support_incidents'::regclass")" 1
assert_eq "incident reader policy" "$(scalar "SELECT COUNT(*) FROM pg_policies WHERE schemaname='public' AND tablename='support_incidents' AND policyname='atlas_f15_incident_read_policy'")" 1
assert_eq "incident reader elevated privileges" "$(scalar "SELECT (rolcanlogin::int + rolsuper::int + rolbypassrls::int + rolcreaterole::int + rolcreatedb::int) FROM pg_roles WHERE rolname='atlas_f15_incident_reader'")" 0
assert_eq "reader access to users table" "$(scalar "SELECT has_table_privilege('atlas_f15_incident_reader','public.users','SELECT')::int")" 0
assert_eq "reader UPDATE permission" "$(scalar "SELECT has_table_privilege('atlas_f15_incident_reader','public.support_incidents','UPDATE')::int")" 0

mkdir -p reports
printf 'RC1 SYNTHETIC STRUCTURAL PREFLIGHT: PASS\nrun_sha=%s\n' "${GITHUB_SHA:-local}" > reports/rc1-preflight.txt
echo "RC1 synthetic structural preflight passed. This is NOT a staging or production authorization."
