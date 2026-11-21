#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."

# F20 destructive fixture test: disposable atlas-ci PostgreSQL only.
if [[ "${COMPOSE_PROJECT_NAME:-}" != atlas-ci* || -z "${ATLAS_STAGING_RUNTIME_PASSWORD:-}" ]]; then
  echo 'F20 BLOCKED: disposable CI stack and ephemeral runtime credential required.' >&2
  exit 2
fi
dc() { docker compose -f compose.yaml -f compose.ci.yaml "$@"; }
owner() { dc exec -T db psql -U atlas -d atlas -X -v ON_ERROR_STOP=1 "$@"; }
runtime() {
  dc exec -T -e PGPASSWORD="$ATLAS_STAGING_RUNTIME_PASSWORD" db \
    psql -h 127.0.0.1 -U atlas_app_runtime -d atlas \
      -X -tA -v ON_ERROR_STOP=1 -v VERBOSITY=verbose "$@"
}
errfile="$(mktemp)"
trap 'rm -f "$errfile"' EXIT

# These records exist only in the throwaway PostgreSQL volume. Never run this
# script against staging with retained or real customer records.
owner >/dev/null <<'SQL'
INSERT INTO public.companies (code,name,created_at,updated_at)
VALUES ('F20_SQL_A','F20 synthetic A',now(),now()),
       ('F20_SQL_B','F20 synthetic B',now(),now())
ON CONFLICT (code) DO NOTHING;
INSERT INTO public.customers (company_id,name,email,created_at,updated_at)
SELECT id, 'F20 customer A','f20-customer-a@example.test',now(),now()
FROM public.companies WHERE code='F20_SQL_A'
ON CONFLICT (company_id,email) DO NOTHING;
INSERT INTO public.customers (company_id,name,email,created_at,updated_at)
SELECT id, 'F20 customer B','f20-customer-b@example.test',now(),now()
FROM public.companies WHERE code='F20_SQL_B'
ON CONFLICT (company_id,email) DO NOTHING;
INSERT INTO public.support_incidents
(company_id,reference,title,description,severity,status,environment,reported_by,created_at,updated_at)
SELECT c.id, 'F20_SQL_A', 'F20 synthetic incident A', 'CI only', 'P3', 'open','demo',u.id,now(),now()
FROM public.companies c CROSS JOIN public.users u
WHERE c.code='F20_SQL_A' AND u.email='admin@atlas.example'
ON CONFLICT (reference) DO NOTHING;
INSERT INTO public.support_incidents
(company_id,reference,title,description,severity,status,environment,reported_by,created_at,updated_at)
SELECT c.id, 'F20_SQL_B', 'F20 synthetic incident B', 'CI only', 'P3', 'open','demo',u.id,now(),now()
FROM public.companies c CROSS JOIN public.users u
WHERE c.code='F20_SQL_B' AND u.email='admin@atlas.example'
ON CONFLICT (reference) DO NOTHING;
SQL

a_id="$(owner -tAX -c "SELECT id FROM companies WHERE code='F20_SQL_A'")"
b_id="$(owner -tAX -c "SELECT id FROM companies WHERE code='F20_SQL_B'")"
if [[ ! "$a_id" =~ ^[0-9]+$ || ! "$b_id" =~ ^[0-9]+$ || "$a_id" == "$b_id" ]]; then
  echo 'F20 BLOCKER: fictitious tenant IDs invalid.' >&2
  exit 1
fi

policy_count="$(runtime -c "SELECT count(*) FROM pg_policies WHERE schemaname='public' AND policyname='atlas_f20_runtime_tenant_policy' AND 'atlas_app_runtime'=ANY(roles);")"
if [[ "$policy_count" != 14 ]]; then
  echo "F20 BLOCKER: expected 14 RLS policies, found $policy_count" >&2
  exit 1
fi

# Global Prometheus gauges are provided by a *fixed* owner-defined
# SECURITY DEFINER aggregate, not by disabling RLS on business tables.
expected_metrics="$(owner -tAX -c "SELECT
  (SELECT count(*) FROM public.orders WHERE status='validated')::text || ':' ||
  (SELECT coalesce(sum(total_cents),0) FROM public.invoices WHERE status='issued')::text || ':' ||
  (SELECT count(*) FROM public.products WHERE stock <= alert_threshold)::text;")"
actual_metrics="$(runtime -c "SELECT orders_validated::text || ':' ||
  invoices_outstanding_cents::text || ':' || products_low_stock::text
  FROM public.atlas_f20_global_metrics();")"
if [[ "$actual_metrics" != "$expected_metrics" ]]; then
  echo 'F20 BLOCKER: global metrics aggregate differs from owner totals.' >&2
  exit 1
fi

# Prove lack of context fails closed and same authenticated TCP connection
# sees only its own data in A and B, with no residue across COMMIT.
out="$(runtime <<SQL
SELECT 'LOGIN:' || session_user || ':' || current_user;
SELECT 'NONE_CUSTOMER:' || count(*) FROM public.customers WHERE email LIKE 'f20-customer-%';
SELECT 'NONE_INCIDENT:' || count(*) FROM public.support_incidents WHERE reference LIKE 'F20_SQL_%';
BEGIN;
SELECT set_config('atlas.tenant_id','$a_id',true);
SELECT 'A_CUSTOMER:' || coalesce(string_agg(name,',' ORDER BY name),'')
 FROM public.customers WHERE email LIKE 'f20-customer-%';
SELECT 'A_INCIDENT:' || coalesce(string_agg(reference,',' ORDER BY reference),'')
 FROM public.support_incidents WHERE reference LIKE 'F20_SQL_%';
COMMIT;
SELECT 'AFTER_A:' || CASE WHEN nullif(current_setting('atlas.tenant_id',true),'') IS NULL THEN 'clear' ELSE 'LEAK' END;
BEGIN;
SELECT set_config('atlas.tenant_id','$b_id',true);
SELECT 'B_CUSTOMER:' || coalesce(string_agg(name,',' ORDER BY name),'')
 FROM public.customers WHERE email LIKE 'f20-customer-%';
SELECT 'B_INCIDENT:' || coalesce(string_agg(reference,',' ORDER BY reference),'')
 FROM public.support_incidents WHERE reference LIKE 'F20_SQL_%';
COMMIT;
SELECT 'AFTER_B:' || CASE WHEN nullif(current_setting('atlas.tenant_id',true),'') IS NULL THEN 'clear' ELSE 'LEAK' END;
SELECT 'END_CUSTOMER:' || count(*) FROM public.customers WHERE email LIKE 'f20-customer-%';
SQL
)"
for expected in \
  'LOGIN:atlas_app_runtime:atlas_app_runtime' \
  'NONE_CUSTOMER:0' 'NONE_INCIDENT:0' \
  'A_CUSTOMER:F20 customer A' 'A_INCIDENT:F20_SQL_A' 'AFTER_A:clear' \
  'B_CUSTOMER:F20 customer B' 'B_INCIDENT:F20_SQL_B' 'AFTER_B:clear' \
  'END_CUSTOMER:0'; do
  if ! grep -Fxq "$expected" <<<"$out"; then
    echo "F20 BLOCKER: expected protected SQL observation missing: $expected" >&2
    exit 1
  fi
done

denied() {
  local label="$1" sql="$2"
  if runtime -c "$sql" >"$errfile" 2>&1; then
    echo "F20 BLOCKER: $label unexpectedly allowed." >&2
    exit 1
  fi
  if ! grep -q '42501' "$errfile"; then
    echo "F20 BLOCKER: $label failed for the wrong reason." >&2
    cat "$errfile" >&2
    exit 1
  fi
  echo "F20 PASS: $label rejected with SQLSTATE 42501"
}
denied 'cross-tenant customer INSERT' \
  "BEGIN; SELECT set_config('atlas.tenant_id','$a_id',true); INSERT INTO public.customers(company_id,name,email,created_at,updated_at) VALUES ($b_id,'F20 forbidden','f20-forbidden@example.test',now(),now()); COMMIT;"
denied 'cross-tenant customer owner UPDATE' \
  "BEGIN; SELECT set_config('atlas.tenant_id','$a_id',true); UPDATE public.customers SET company_id=$b_id WHERE email='f20-customer-a@example.test'; COMMIT;"

# Actual business INSERT succeeds on the authenticated role under correct
# tenant context; rollback makes it nonpersistent.
runtime >/dev/null <<SQL
BEGIN;
SELECT set_config('atlas.tenant_id','$a_id',true);
INSERT INTO public.customers(company_id,name,email,created_at,updated_at)
VALUES ($a_id,'F20 allowed','f20-allowed@example.test',now(),now());
ROLLBACK;
SQL

owner >/dev/null <<'SQL'
DELETE FROM public.support_incidents WHERE reference IN ('F20_SQL_A','F20_SQL_B');
DELETE FROM public.customers WHERE email IN ('f20-customer-a@example.test','f20-customer-b@example.test');
DELETE FROM public.companies WHERE code IN ('F20_SQL_A','F20_SQL_B');
SQL

mkdir -p reports
printf 'F20 policies=14; actual runtime SQL A/B isolation=PASS\nF20 no-context and post-commit reset=PASS\nF20 cross-tenant INSERT and owner UPDATE denied=PASS\nF20 in-tenant INSERT and rollback=PASS\n' > reports/f20-tenant-rls.txt
echo 'F20 PASS: authenticated runtime RLS denies cross-tenant rows and resets context on commit.'
