#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."

# F18: only the disposable F17 CI PostgreSQL cluster. Not a deployment script.
if [[ "${COMPOSE_PROJECT_NAME:-}" != atlas-ci* || -z "${ATLAS_STAGING_RUNTIME_PASSWORD:-}" ]]; then
  echo 'F18 BLOCKED: disposable CI project and ephemeral runtime password required.' >&2
  exit 2
fi
dc() { docker compose -f compose.yaml -f compose.ci.yaml "$@"; }
owner() { dc exec -T db psql -U atlas -d atlas -X -v ON_ERROR_STOP=1 "$@"; }
runtime() {
  dc exec -T -e PGPASSWORD="$ATLAS_STAGING_RUNTIME_PASSWORD" db \
    psql -h 127.0.0.1 -U atlas_app_runtime -d atlas -tAX -v ON_ERROR_STOP=1 "$@"
}

# Two artificial tenants/records, created by the schema owner in isolated CI.
# Never use this against any retained or real customer environment.
owner >/dev/null <<'SQL'
INSERT INTO public.companies (code,name,created_at,updated_at)
VALUES ('F18_SQL_A','F18 synthetic SQL tenant A',now(),now()),
       ('F18_SQL_B','F18 synthetic SQL tenant B',now(),now())
ON CONFLICT (code) DO NOTHING;

INSERT INTO public.support_incidents
(company_id,reference,title,description,severity,status,environment,reported_by,created_at,updated_at)
SELECT c.id, 'F18_SQL_A', 'F18 synthetic incident A',
       'CI only: no personal data', 'P3','open','demo',u.id,now(),now()
FROM public.companies c
CROSS JOIN public.users u
WHERE c.code='F18_SQL_A' AND u.email='admin@atlas.example'
ON CONFLICT (reference) DO NOTHING;

INSERT INTO public.support_incidents
(company_id,reference,title,description,severity,status,environment,reported_by,created_at,updated_at)
SELECT c.id, 'F18_SQL_B', 'F18 synthetic incident B',
       'CI only: no personal data', 'P3','open','demo',u.id,now(),now()
FROM public.companies c
CROSS JOIN public.users u
WHERE c.code='F18_SQL_B' AND u.email='admin@atlas.example'
ON CONFLICT (reference) DO NOTHING;
SQL

a_id="$(owner -tAX -c "SELECT id FROM public.companies WHERE code='F18_SQL_A'")"
b_id="$(owner -tAX -c "SELECT id FROM public.companies WHERE code='F18_SQL_B'")"
if [[ ! "$a_id" =~ ^[0-9]+$ || ! "$b_id" =~ ^[0-9]+$ || "$a_id" == "$b_id" ]]; then
  echo 'F18 BLOCKER: distinct synthetic tenant identifiers not found.' >&2
  exit 1
fi

# A and B must be isolated even while reusing the exact same authenticated
# PostgreSQL TCP session. SET LOCAL ROLE and GUC should expire at COMMIT.
out="$(runtime <<SQL
SELECT 'LOGIN:' || session_user || ':' || current_user;
BEGIN;
SET LOCAL ROLE atlas_f15_incident_reader;
SELECT set_config('atlas.tenant_id', '$a_id', true);
SELECT 'A_VISIBLE:' || coalesce(string_agg(reference,',' ORDER BY reference),'')
 FROM public.support_incidents WHERE reference LIKE 'F18_SQL_%';
SELECT 'A_SEES_B:' || count(*) FROM public.support_incidents WHERE reference='F18_SQL_B';
COMMIT;
SELECT 'AFTER_A_ROLE:' || current_user;
SELECT 'AFTER_A_CONTEXT:' || CASE
  WHEN nullif(current_setting('atlas.tenant_id', true),'') IS NULL THEN 'clear' ELSE 'LEAK' END;
BEGIN;
SET LOCAL ROLE atlas_f15_incident_reader;
SELECT set_config('atlas.tenant_id', '$b_id', true);
SELECT 'B_VISIBLE:' || coalesce(string_agg(reference,',' ORDER BY reference),'')
 FROM public.support_incidents WHERE reference LIKE 'F18_SQL_%';
SELECT 'B_SEES_A:' || count(*) FROM public.support_incidents WHERE reference='F18_SQL_A';
COMMIT;
SELECT 'AFTER_B_ROLE:' || current_user;
SELECT 'AFTER_B_CONTEXT:' || CASE
  WHEN nullif(current_setting('atlas.tenant_id', true),'') IS NULL THEN 'clear' ELSE 'LEAK' END;
BEGIN;
SET LOCAL ROLE atlas_f15_incident_reader;
SELECT set_config('atlas.tenant_id', '', true);
SELECT 'WITHOUT_CONTEXT:' || count(*) FROM public.support_incidents WHERE reference LIKE 'F18_SQL_%';
COMMIT;
SQL
)"

for expected in \
  'LOGIN:atlas_app_runtime:atlas_app_runtime' \
  'A_VISIBLE:F18_SQL_A' 'A_SEES_B:0' 'AFTER_A_ROLE:atlas_app_runtime' 'AFTER_A_CONTEXT:clear' \
  'B_VISIBLE:F18_SQL_B' 'B_SEES_A:0' 'AFTER_B_ROLE:atlas_app_runtime' 'AFTER_B_CONTEXT:clear' \
  'WITHOUT_CONTEXT:0'; do
  if ! grep -Fxq "$expected" <<<"$out"; then
    echo "F18 BLOCKER: missing expected session/RLS result: $expected" >&2
    exit 1
  fi
done

# Remove synthetic data. This is a disposable CI database regardless;
# the cleanup also prevents interference with later HTTP/browser assertions.
owner >/dev/null <<'SQL'
DELETE FROM public.support_incidents WHERE reference IN ('F18_SQL_A','F18_SQL_B');
DELETE FROM public.companies WHERE code IN ('F18_SQL_A','F18_SQL_B');
SQL

printf 'F18 same-session reader A/B separation: PASS\nF18 transaction-local role and tenant context reset: PASS\nF18 no-context fail-closed reader: PASS\n' > reports/f18-rls.txt
echo 'F18 PASS: scoped N3 reader isolates A/B in one authenticated SQL session and resets transaction context.'
