# ATLAS ONE — P06.05: role-specific dashboard pilot

**Status:** draft candidate. **Base:** UI refresh PR #30, branch `feature/atlas-p06-experience-refresh`. **No merge into main, no staging or production deployment.**

## Scope

P06.05 / V4 M04.05 introduces dashboard presentation driven by the **active-company** role already returned from Laravel `GET /api/me`. The frontend is not an authorization system. All server RBAC checks and existing modules remain unchanged.

### Role matrix (all metrics originate from existing APIs)

| Current company role | First dashboard experience | Data source |
|---|---|---|
| `admin` | Incident/SLA summary plus original ERP business KPIs and charts | `GET /api/support/incidents/summary` and `GET /api/dashboard` |
| `support_n2` / `support_n3` | Incidents open, escalated N3, response SLA breaches, resolution SLA breaches | `GET /api/support/incidents/summary` only |
| `stock` | Stock alert count and up to five actual products at or below threshold, links to catalogue and movements | `GET /api/dashboard` |
| `commercial` | Validated orders, revenue HT, outstanding TTC; invoice/quote navigation | `GET /api/dashboard` |
| `reader` | Original read-only business dashboard unchanged | `GET /api/dashboard` |

Role-specific launchpad shortcuts link to existing implemented modules only. Future modules remain disabled. The company selector continues to reload the workspace after an authorized switch; the dashboard re-queries only the new tenant, using its new membership role.

For support-only roles, the dashboard avoids fetching the sales/finance endpoint. The support summary uses an API request only from admin/support roles and does not fabricate zeros on errors; it shows a retry control instead. Its value semantics exactly match the existing backend summary.

### Source files

- `frontend/src/components/dashboard/RoleDashboardPanel.tsx` — specialized support, stock and sales views; no mocked metrics.
- `frontend/src/components/Workspace.tsx` — passes active role and uses support-only view where appropriate, preserving existing finance dashboard for admin/commercial/reader.
- `frontend/src/components/layout/WorkspaceWelcome.tsx` — role-dependent shortcuts.
- `frontend/src/app/role-dashboard.css` and `layout.tsx` — accessible responsive layout, loaded after the P06 theme.
- `frontend/tests/role-dashboard.spec.ts` — Playwright on stock, commercial and reader accounts; the admin case is asserted in the existing `experience.spec.ts` login, preserving the 5/min demo login throttle.

### Acceptance

- [ ] CI successful for the exact head SHA (TypeScript/Next build, PHP, HTTP, all browser specs).
- [ ] Admin receives real support summary and retains existing ERP dashboard.
- [ ] Stock role sees stock alerts and no financial panels, can navigate to products.
- [ ] Commercial role sees sales KPIs and can open invoices.
- [ ] Reader retains original dashboard, does not see privileged support stats.
- [ ] Role-specific launchpad does not advertise unavailable modules.
- [ ] Manual mobile/desktop and accessibility review.
- [ ] Security review: frontend role visibility **must not** be treated as a control; Laravel policy and tenant context must be enforced for every underlying API.

### Limitations

This is **not** the complete P06 experience platform. It does not implement user-configurable widgets, Dashboard Builder, GraphQL, AI, notifications, GMAO screens or new backend modules. It does not expand privileges or backport PostgreSQL F16–F20 security changes from the separate PR stack. User-scoped settings, customized dashboards, accessibility audit, visual review, audit of the underlying shared reporting endpoint and multi-tenant role-switch scenarios remain future tasks.

**NO-GO for release** until CI, regression and human approvals are complete.
