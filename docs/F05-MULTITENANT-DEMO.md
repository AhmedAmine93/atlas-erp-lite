# F05 — Sociétés fictives multi-entreprises (opt-in)

## Purpose

Provide safe, repeatable demo data for multi-tenant scenarios without altering
the existing default ERP seed, production data, or historic migrations.

## Fixture (fictional only)

- Company \`ATLAS_F05_A\`: Industrie (Paris + Lyon demo establishments).
- Company \`ATLAS_F05_B\`: Hôtellerie (Nice + Lille demo establishments).
- Shared user \`multi-f05@atlas.example\`: **reader in both companies at F05**,
  ready for company-specific role assignment in F06.
- Password is loaded from \`DEMO_PASSWORD\`, **never stored in source code**.

## Execution

Run after migrations in a disposable, local database:

\`\`\`sh
export APP_ENV=local
export DEMO_PASSWORD='<a-unique-local-demo-secret-at-least-12-chars>'
php artisan migrate
php artisan db:seed --class=MultiTenantDemoSeeder
\`\`\`

Do not invoke in staging or production. The seeder is **not** linked from
\`DatabaseSeeder\` or the deployment scripts.

## Acceptance tests

- Two deterministic company codes exist, with two establishments each.
- One shared reader belongs to both companies.
- Re-running the seeder does not duplicate records.
- The account can change between its companies.
- Each company's customers list is empty by default.
- Seeding a non-local/non-test environment fails closed.

## Next task

F06: move the security role from \`users.role_id\` to the \`company_user\`
membership and require tenant-specific roles in Gates and admin endpoints.
