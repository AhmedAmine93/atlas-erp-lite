# F06 — Roles by company membership

## Problem

Before F06, \`users.role_id\` was global. A person who was admin in Company A
was consequently admin in Company B as well. This was not acceptable for
multi-tenant authorization.

## Design

- The new \`company_user.role_id\` is the authorization source.
- Migration copies the legacy global role to all existing memberships.
- \`users.role_id\` remains for compatibility, but no Gate may use it.
- \`TenantRoles\` resolves the role for the active tenant and produces the
  response to both login and \`/api/me\`.
- The admin user list, user updates, last-admin count and detach operations
  are scoped to the active company's membership.
- A company creator receives an explicit admin membership on the newly
  created company.
- Editing other companies or their establishments requires switching to
  that company first.
- A multi-company user's permissions in Company A cannot be increased by
  a role change in Company B.

## Data migrations

1. Add nullable \`company_user.role_id\` foreign key.
2. Backfill with \`users.role_id\` for existing users and memberships.
3. Reject null memberships; change role_id to NOT NULL.
4. Keep the global role column until consumers no longer require it.
5. New memberships must explicitly specify a role.

## Tests

- admin A / reader B denies admin and write endpoints in B;
- updating B's membership does not change A's membership or the legacy role;
- last admin cannot be demoted or deleted;
- creator gets admin in a newly created company;
- editing company B from active company A is denied;
- login and \`/api/me\` reflect effective tenant role;
- existing ERP/sales and company CRUD tests remain green.

## Rollout controls

Apply the role migration before deploying the new auth code. Do not reverse
the application deployment onto a schema where the role pivot has been dropped.
Use forward-only rollout with database backup and a rehearsal in staging.

No code is merged to \`main\` or deployed to production by this F06 PR.
