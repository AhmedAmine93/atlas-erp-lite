# ATLAS ONE — P06.08 Dashboard Builder (pilot v1)

**Status:** draft; stacked on frontend PR #33 and its P06 predecessors #30–#32. **Scope:** frontend-only user preferences. **No merge or deployment.**

## Business behavior and delivered scope

The existing Laravel ERP dashboard contains four live KPI cards plus four live panels (revenue chart, best-selling products, recent orders and low-stock alerts). This P06.08 pilot introduces a **working** editor of those eight widgets for the `admin`, `commercial` and `reader` overview screens without new backend APIs or altered business numbers.

- Open the editor with **Personnaliser le tableau de bord**.
- Show/hide any of the **four KPI cards** and **four business panels** using native checkboxes.
- Move a widget up or down **within its KPI/panel group** using buttons with accessible names and disabled boundary states.
- Rendering order follows the selected order **in the DOM** (not just CSS), so keyboard reading order and visual order agree.
- Reload the browser: preferences persist for the **same user, active company and role**, on that one browser/device.
- **Réinitialiser** returns to all eight original widgets in the original order.
- No widget changes its API data, dates, filters, business meaning or RBAC. The original separate support N2/N3 and stock-first dashboards remain **fixed and unchanged** for this pilot.

## Technical design

| File | Responsibility |
|---|---|
| `frontend/src/components/dashboard/useDashboardLayout.ts` | Allowlisted widget IDs, validation and normalization of local data, per-user/per-company/per-role key, visibility and group-safe move operations |
| `frontend/src/components/dashboard/DashboardBuilder.tsx` | Native checkbox settings, keyboard-operable up/down controls and reset |
| `frontend/src/components/Workspace.tsx` | Hooks up builder to live KPI cards and conditionally rendered panels; **actual DOM panel order changes** |
| `frontend/src/app/dashboard-builder.css` + `layout.tsx` | Responsive styling and visible keyboard focus |
| `frontend/tests/experience.spec.ts` | Existing authenticated test extended to cover hide, reorder, reload, saved settings and reset. **No extra admin login** (protects current login throttling). |

### Data / security boundary

The browser's `localStorage` contains **only widget IDs, order and enabled flags** — no metrics, personal data, access tokens or company names. Keys are partitioned by user ID, active company ID and membership role. Incoming JSON is checked against a fixed set of eight IDs and boolean flags; malformed/unknown/duplicate data falls back to safe defaults or is ignored. Stored data cannot authorize an API call: Laravel remains the access control authority. If browser storage is unavailable, changes work in memory for the current page and an honest status message appears.

This is **device-local** personalization; not synced between devices/browsers, not a shared company dashboard and not a cloud-stored preference. The user can clear browser data at any time. Role and tenant switching select separate preference sets and never permit widgets or access beyond the server role.

### Verification gates

- [ ] CI success for the exact PR SHA: Next.js typecheck/build, existing PHP/HTTP/Playwright and new dashboard customization assertions.
- [ ] Hide/show a chart and KPI, then reload: both states persist for the current browser/user/tenant/role.
- [ ] Move a live panel up: its **DOM order** changes, persists across reload and resets correctly.
- [ ] The original dashboard loads with all widgets enabled on a fresh profile.
- [ ] Reader, commercial, stock and support role regressions remain green, with no newly enabled modules.
- [ ] Manual visual checks for desktop/mobile, screen-reader checkbox names, keyboard focus and no layout overflow.
- [ ] Review local preference scoping, storage-disabled fallback, malformed JSON and no sensitive data in storage.

### Limitations / future work

This is **not** the final Dashboard Builder from the V4 program. It does **not** yet provide multi-column drag-and-drop, widget resizing, custom reporting queries, user-authored dashboards, cross-device/cloud sync, scheduled reports, PDF/CSV exports, AI-generated views, or role-specific support/stock layout editing. Those need a separate, explicitly secured backend preference service, additional permissions, mobile UX and data governance.

This PR is isolated from the F16–F20 PostgreSQL security chain. Production and `main` stay unchanged. No release from a green CI without manual design/accessibility and security review.
