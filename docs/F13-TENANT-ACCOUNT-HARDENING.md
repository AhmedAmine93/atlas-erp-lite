# F13 — Tenant account hardening, integration review blocker

## Finding

Before F13, an administrator in Company A could update the **global**
\`users.name\`, \`users.email\` and \`users.password\` of a user who also
belongs to Company B, simply because that user was listed in A. An admin
could also detach a user's final membership and attempt to delete their
global user record, potentially breaking references to historic audit,
orders, stock movements and support incidents.

The admin users list returned the legacy \`users.role_id\` rather than
the effective \`company_user.role_id\` to the edit form.

These are *cross-tenant administration and lifecycle* issues, even
though the request authorization Gate correctly checks the active company.

## Fix

- Existing user \`PUT /api/users/{id}\` accepts \`role_id\` (and, for legacy
  clients, **unchanged** \`name\`/\`email\` only). Password changes are
  prohibited on this endpoint. Global identity edits are a separate
  account self-service workflow, **not** delegated to tenant admins.
- No global email uniqueness checks run for role-only updates, so the
  update endpoint cannot probe unrelated emails via error variants.
- The user list and \`/api/me\`/login present the **active membership** role
  consistently as both \`role_id\` and \`role\`.
- UI displays global user identity read-only on an edit and posts only
  the company's membership role. New user creation remains available
  to admins (name, email and initial password).
- Removing an affiliation never automatically hard-deletes the global
  user identity; it preserves historical foreign key references.
  Access is denied when a user has no company membership.
- Existing last-admin protection and session invalidation remain intact.

## Test matrix

- A admin can change *only* user's A membership role.
- B membership and legacy global role remain unchanged.
- A admin cannot change shared name, email or password.
- Users list displays A membership role, not global role.
- Removing A affiliation leaves B membership and user record intact.
- Removing user's final membership prevents login but does not destroy
  referential history.
- Existing tenant and ERP tests remain green.

## Important remaining review gates — not solved by F13

1. Identity provisioning/invitation across existing tenants still needs
   a verified invitation flow (not an arbitrary email-based affiliation).
2. Deletion/retention of an orphan global user needs a privacy-compliant
   policy, potentially anonymization after legal retention periods.
3. Eloquent global scopes alone do not enforce tenant relationships at
   raw SQL/DB level. Composite FK and explicit tenant constraints should
   be considered for sensitive joins and incident event ownership.
4. Production rollout requires schema-migration rehearsal, backup,
   restore, an authorization review and independent security assessment.

## Release order

Draft PR #21 is stacked on PR #20 → #19 → ... → #12.
No branch is merged to \`main\`, no production deployment.
