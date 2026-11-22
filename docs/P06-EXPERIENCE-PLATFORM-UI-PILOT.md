# ATLAS ONE — P06 Experience Platform / UI pilot

**Status:** visual candidate on its own branch. **Base:** RC1 PR #24 (`release/atlas-one-rc1-integration`). **Do not merge to `main` or deploy.**

## Why a separate UI branch?

PostgreSQL F16–F20 hardening runs in stacked security PRs #25–#29. This change intentionally starts from RC1, does not edit any backend service, migration, production Compose file or deployment script, and can be reviewed independently. It is not the completion of all P06 requirements.

## Visible delivery

- ATLAS ONE identity in the login, sidebar and application footer.
- A dark, high-contrast enterprise navigation rail; consistent accents, card treatments, focus outlines and responsive layout in a separate CSS theme file.
- The existing nested menu and server-side role filtering continue to work. Planned/not-yet-ready modules are hidden by default, discoverable via an explicit toggle or search, but **always disabled** until their real APIs and permissions exist. No fake active features.
- Dashboard landing panel displays a server-provided active company name, describes its scope and offers one-click access to live existing modules: clients, orders, products and (when entitled) incidents.
- Existing real KPI tiles, revenue chart, lists, sales, stock and support workflows remain untouched: no synthetic analytics and no new API.

## Files

- `frontend/src/components/layout/WorkspaceSidebar.tsx` — ATLAS ONE identity and decluttered navigation.
- `frontend/src/components/layout/WorkspaceWelcome.tsx` — tenant-aware launchpad using original `View` and `onNavigate` navigation.
- `frontend/src/components/Workspace.tsx` — dashboard insertion only, retaining current routes and business views.
- `frontend/src/app/atlas-theme.css` + `layout.tsx` — isolated design tokens and style overrides, no rewrite of the 1,700-line legacy stylesheet.
- `frontend/src/components/auth/Login.tsx` — coherent product label.
- `frontend/tests/experience.spec.ts` — browser regression for landing, disabled future modules, quick routes.

## Acceptance

- [ ] Workflow CI complete on this PR's **exact SHA**.
- [ ] Next.js typecheck + build succeed.
- [ ] Existing PHP, HTTP and Playwright regressions still pass.
- [ ] New browser test passes: active-company selector, quick navigation, planned modules disabled.
- [ ] Manual review at desktop and mobile sizes; verify keyboard focus, contrast and no horizontal overflow.
- [ ] Security review verifies UI visibility is NOT authorization: Laravel API is authoritative.
- [ ] Screenshot signoff for visual changes and regression evidence attached.

**Scope limitations:** P06.01 and P06.02 initial visual delivery only. No Oracle JET, Angular client portal, mobile app, AI assistant, dashboard builder or backend permission expansion. The final enterprise design system, accessible shared component library, deep links and role-specific widgets remain separate backlog items.

**No deployment authorization** from a green CI alone.
