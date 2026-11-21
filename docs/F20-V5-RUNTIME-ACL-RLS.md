# F20 — Extended V5 runtime ACL + tenant RLS

The existing F16 role did not have privileges for V5 tables created after its original allowlist. This change explicitly grants SELECT/INSERT/UPDATE (or SELECT/INSERT for immutable CSV rows), without DELETE, and installs a tenant-scoped PostgreSQL row-level policy on **all 11 company-owned V5 tables**. It grants identity sequence usage only to the allowed tables. PostgreSQL role is never allowed DDL or migration table read.

Integration tests require a PostgreSQL runtime role and check allowed read on every V5 table, blocked DELETE, tenant A/B visibility and denied cross-tenant insert (SQLSTATE 42501). Owner migration account remains separate; production migration rollback must be rehearsed and approved. Mutable session GUC is not a complete SQL-injection defense and role separation must remain part of the security review.
