#!/usr/bin/env bash
set -Eeuo pipefail
cd "$(dirname "$0")/.."

# F19: authenticated grant verification in the disposable F17 CI cluster.
# This is not a rollout or a script for live client databases.
if [[ "${COMPOSE_PROJECT_NAME:-}" != atlas-ci* || -z "${ATLAS_STAGING_RUNTIME_PASSWORD:-}" ]]; then
  echo 'F19 BLOCKED: ephemeral CI project and runtime secret required.' >&2
  exit 2
fi
dc() { docker compose -f compose.yaml -f compose.ci.yaml "$@"; }
runtime() {
  dc exec -T -e PGPASSWORD="$ATLAS_STAGING_RUNTIME_PASSWORD" db \
    psql -h 127.0.0.1 -U atlas_app_runtime -d atlas \
    -X -v ON_ERROR_STOP=1 -v VERBOSITY=verbose "$@"
}
errfile="$(mktemp)"
trap 'rm -f "$errfile"' EXIT
denied() {
  local label="$1" sql="$2"
  if runtime -c "$sql" >"$errfile" 2>&1; then
    echo "F19 BLOCKER: runtime allowed $label" >&2
    exit 1
  fi
  if ! grep -q '42501' "$errfile"; then
    echo "F19 BLOCKER: $label failed for an unexpected reason." >&2
    cat "$errfile" >&2
    exit 1
  fi
  echo "F19 PASS: $label denied by PostgreSQL 42501"
}
expect_acl() {
  local table="$1" acl="$2" expected="$3" actual
  actual="$(runtime -tAX -c \
    "SELECT has_table_privilege(current_user,'public.$table','$acl')::int;")"
  if [[ "$actual" != "$expected" ]]; then
    echo "F19 BLOCKER: $table $acl expected $expected but got $actual" >&2
    exit 1
  fi
}

# Verify both positive and negative access, including historic journals.
expect_acl roles SELECT 1
for privilege in INSERT UPDATE DELETE; do expect_acl roles "$privilege" 0; done
for table in audit_logs stock_movements support_incident_events support_diagnostic_runs; do
  expect_acl "$table" SELECT 1
  expect_acl "$table" INSERT 1
  expect_acl "$table" UPDATE 0
  expect_acl "$table" DELETE 0
  denied "$table UPDATE" "UPDATE public.$table SET id=id WHERE false;"
  denied "$table DELETE" "DELETE FROM public.$table WHERE false;"
done
for table in companies support_incidents; do
  expect_acl "$table" UPDATE 1
  expect_acl "$table" DELETE 0
  denied "$table DELETE" "DELETE FROM public.$table WHERE false;"
done
denied 'roles INSERT' "INSERT INTO public.roles(name) VALUES ('F19_FORBIDDEN');"
denied 'roles UPDATE' "UPDATE public.roles SET name=name WHERE false;"
denied 'roles DELETE' "DELETE FROM public.roles WHERE false;"

# INSERT to the append-only audit journal must remain usable for business
# operations. Roll back the synthetic row explicitly; it never reaches a
# retained environment.
runtime >/dev/null <<'SQL'
BEGIN;
-- F20 introduces tenant RLS on audit_logs; keep the positive F19 write
-- fixture inside an authenticated transaction-local tenant.
SELECT set_config('atlas.tenant_id',
    (SELECT id::text FROM public.companies WHERE code='ATLAS_DEMO'), true);
INSERT INTO public.audit_logs
(company_id,user_id,action,entity_type,created_at,updated_at)
SELECT c.id, u.id, 'f19.positive', 'SyntheticCI', now(), now()
FROM public.companies AS c
JOIN public.users AS u ON u.email='admin@atlas.example'
WHERE c.code='ATLAS_DEMO';
ROLLBACK;
SQL

mkdir -p reports
printf 'F19 restricted role SQL grants: PASS\nF19 append-only history deny UPDATE/DELETE: PASS\nF19 audit INSERT: PASS\n' > reports/f19-write-hardening.txt
echo 'F19 PASS: authenticated runtime SQL cannot mutate history or system roles; append-only INSERT works.'
