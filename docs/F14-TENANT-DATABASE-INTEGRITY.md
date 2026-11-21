# ATLAS ONE V4 — F14 Database tenant integrity and migration rehearsal

## Context / risk

The application has tenant-scoped Eloquent models, but a classic FK on
\`quotes.customer_id\` does **not** prove that the customer is in the quote's
company. The same applies to document lines, stock movements, orders, invoices,
support incident timeline and diagnostic history. Raw SQL, a bug in an import,
or an internal process could create cross-company relations without composite
integrity enforcement.

## F14/A — Parent relations

The migration \`2026_11_21_000005_add_tenant_composite_fks.php\`:

1. Executes **preflight counts** on existing rows before making changes.
2. Fails with an explicit error if any reference crosses tenant boundaries or
   its parent is missing. It **never silently reassigns** corrupt rows.
3. Adds composite unique indexes on \`(company_id, id)\` for
   customers, products, quotes, orders and support incidents.
4. Adds composite FKs for the 8 relationships:
   - quotes → customers;
   - orders → customers;
   - orders → quotes (nullable);
   - invoices → orders;
   - stock movements → products;
   - stock movements → orders (nullable);
   - support incident events → incidents;
   - support diagnostic runs → incidents.
5. Uses PostgreSQL \`NOT VALID\` followed by \`VALIDATE CONSTRAINT\`.
   No table rows are deleted and historical content is preserved.

## F14/B — Line-item ownership

\`2026_11_21_000006_tenant_ownership_on_document_items.php\`:

1. Adds nullable \`company_id\` on quote and order items.
2. Backfills each line from its parent quote/order.
3. Validates the parent and product tenant IDs match the line, refusing
   incomplete or cross-tenant historical lines.
4. Makes \`company_id\` non-nullable and adds tenant-scoped indexes.
5. PostgreSQL composite FKs enforce document-line and line-product ownership.
6. QuoteItem and OrderItem receive the same fail-closed Eloquent tenant trait
   as the main ERP models. Their model creating hook assigns the tenant
   server-side; cross-tenant line inserts are rejected by PostgreSQL.

## Test matrix

\`TenantDatabaseIntegrityTest.php\` verifies with **raw SQL**, deliberately
bypassing Eloquent's scope, that cross-company references fail with PostgreSQL
SQLSTATE \`23503\` on quotes, orders, invoices, stock movements, document
items, support events and diagnostic history. Tests also cover company_id
spoofing and the normal quote → order → stock → invoice flow.

Existing complete CI includes Laravel/PostgreSQL tests, concurrency,
API smoke, Next.js build and Playwright. The targeted F14 verification is
part of the same CI.

## Synthetic populated migration rehearsal

\`bash scripts/rehearse-f14.sh\` (executed inside \`scripts/ci.sh\`):

1. Creates a **dedicated disposable PostgreSQL database**.
2. Runs all pre-F14 migrations from a temporary migration directory.
3. Seeds a **fictional** ERP dataset (quotes, lines, invoices, movements).
4. Applies the two F14 migrations and executes migrate again to prove no
   outstanding migrations.
5. Takes a temporary logical \`pg_dump\` archive, restores into a separate
   disposable database and checks relevant row counts and presence of the
   12 composite tenant foreign keys.
6. Runs migrate again on the restored database and stores only a synthetic
   count summary in \`reports/f14-rehearsal.txt\`.
7. Drops both temporary DBs and deletes the temporary dump archive.

The dump is never committed or uploaded as a CI artifact, and this exercise
does not access production data.

## Release gates / production limitations

- **DO NOT merge or deploy without DBA/security review.**
- This is a PostgreSQL-focused constraint implementation; SQLite deliberately
  lacks the 12 composite constraints and is **not** a valid security proof.
- These tests verify a synthetic populated upgrade, **not** migration of an
  actual production/staging snapshot. Rehearse against an appropriately
  anonymized, representative staging copy before deployment.
- Check table sizes, locks, index creation times, disk headroom and rollback
  strategy. In production, \`CREATE INDEX CONCURRENTLY\` may be needed,
  with an operationally different migration plan; this migration uses
  ordinary index creation inside Laravel's transaction.
- Identify and reconcile any historical cross-tenant inconsistency **before**
  applying F14; do not silently fix records.
- A composite FK proves the referenced *record* belongs to the same tenant.
  It does not prove an actor was authorized at the time of a historical event,
  and not all global-user and polymorphic audit relationships are covered.
- Backups, point-in-time recovery, RLS, encrypted tenant secrets,
  comprehensive penetration testing and high-availability are separate
  projects. This release does not claim unhackability.
- Sequential stack: F14 PR #22 depends on #21→#20→...→#12.
  Original ITSM PR #11 must **not** be merged independently.
