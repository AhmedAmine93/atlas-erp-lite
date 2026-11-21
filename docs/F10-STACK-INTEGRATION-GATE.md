# ATLAS ONE — Integration gate F01–F09 (PR #12–#19)

Status: **review required**, not deployed or merged. Snapshot: 2026-11-21.

## Source of truth

- PR #12 \`feature/erp-navigation-foundation\` → \`main\`: navigation foundation.
- PR #13 \`feature/erp-admin-organizations\` → #12: organizations.
- PR #14 \`feature/erp-tenant-security-foundation\` → #13: tenant isolation.
- PR #15 \`fix/organization-tenant-reference-validation\` → #14: 422/404 regression fix.
- PR #16 \`feature/atlas-f04-shell-assembly\` → #15: modular login + multi-tenant shell.
- PR #17 \`feature/atlas-f05-demo-tenants\` → #16: opt-in fictional fixtures.
- PR #18 \`feature/atlas-f06-company-membership-roles\` → #17: tenant-scoped membership roles.
- PR #19 \`feature/atlas-f07-f09-support-itsm\` → #18: tenant-scoped ITSM incidents and calendar SLA.
- PR #20 \`feature/atlas-f10-f12-support-diagnostics\` → #19: read-only diagnostics (pending).

**Do not merge PR #11** \`feat/support-incidents-v1\` as-is: its migration creates a global incident table without \`company_id\`. The intended functionality was adapted and incorporated into #19 with tenant isolation, enhanced tests, and different migrations. Merging #11 would create duplicate/misleading migrations.

## Merge / promotion order

1. Review the diff of each PR against *its own base*, confirm business ownership and data model. Ensure the final \`main\` has no stale or duplicate migration.
2. Re-run the **full** CI on the latest aggregate head (at present #19, later #20) against the full inherited stack. Inspect PHPUnit, smoke HTTP, Next.js TypeScript/build and Playwright artifacts.
3. Before accepting #14, verify tenant backfill and recoverability with a cloned staging database. Pay special attention to historical \`company_id\` fields, \`company_user\` memberships, foreign keys and unique indexes.
4. Before accepting #18, rehearse the backfill of \`company_user.role_id\` from global \`users.role_id\`. Count null roles: expected zero. Verify active-tenant role changes do not affect other companies.
5. Before accepting #19, verify there is no deployment which already applied the non-tenant PR #11 schema. If there is, explicitly write and test a non-destructive incident migration rather than running the new create-table migration against an occupied table.
6. Merge sequentially **#12 → #13 → #14 → #15 → #16 → #17 → #18 → #19**, updating each stacked PR base as previous branches merge; re-run CI and address conflicts if a stack changes. Include #20 only after its own green CI and review.
7. Build an immutable release candidate, back up staging, rehearse migration and rollback, run cross-tenant tests, verify restoration, then obtain authorization before production release.

## Hard stop conditions

- Any red CI or missing migration rehearsal.
- Data returned across tenant boundaries, including incidents, audit logs, attachments or diagnostics.
- N2 or reader able to access N3 diagnostic endpoints.
- A new membership missing an explicit role or a company with no administrator.
- Any real credentials, personal data or production logs in fixtures or Git history.
- Existing #11 schema observed in an environment without an approved reconciliation migration.
- No tested restoration procedure.

## Completed evidence at this snapshot

PR #19 head \`bd7956b62746a7a56974298d638ed1b537bd4b87\`:
[CI run 44915027717](https://github.com/AhmedAmine93/atlas-erp-lite/actions/runs/44915027717) was green:
40 PHP tests, 383 assertions, 29 HTTP smoke checks, 4 Playwright scenarios, Docker and Next.js build.

**The CI is proof for that tested commit, not proof of overall production readiness or of an absence of vulnerabilities.**

## Independent review priorities

- Validate authorization and data separation outside Eloquent global scopes (raw SQL, relationships, exports, queues, cache).
- Require tenant-safe foreign key relationships for incident timeline events and assigned users, including direct database writes.
- Check session invalidation, audit integrity and user roles across tenant switching.
- Review dependabot upgrades as separate, compatible PRs; do not mix major dependency upgrades into incident migrations.
- Exercise backup/restore, fault injection, scalability, threat modeling, dependency scanning and an authorized security assessment.
