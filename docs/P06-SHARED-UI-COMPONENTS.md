# ATLAS ONE — P06.03 Shared UI Components

**Status:** CI candidate; stacked on P06 role dashboard PR #31, which is stacked on the P06 shell PR #30. No merge, no production deployment.

## Why
The role dashboards duplicated their metric tiles, action-oriented section headings and API loading/error markup. P06.03 begins a small, typed, reusable component library for ATLAS ONE without rewriting the rest of the ERP or touching PostgreSQL, Laravel, role authorization or API contracts.

## Components
| Component | Path | Responsibility |
|---|---|---|
| `MetricCard` | `frontend/src/components/ui/MetricCard.tsx` | Stateless KPI surface with label, real value, icon and optional hint; renders an `article` when informational and a native `button type=button` only when an action is configured |
| `SectionHeading` | `frontend/src/components/ui/SectionHeading.tsx` | Shared section eyebrow, semantic heading, description and optional action slot |
| `FeedbackState` | `frontend/src/components/ui/FeedbackState.tsx` | Semantic loading, error and empty states; preserves API failure messaging and a real retry handler, never substitutes zero for unavailable metrics |

`frontend/src/app/ui-components.css` contains design tokens, focus visibility, error/empty styling, responsive behavior and reduced-motion support. It is imported into the root Next.js layout **after** prior P06 theme styles.

## Adoption
`frontend/src/components/dashboard/RoleDashboardPanel.tsx` uses the three shared components for support N2/N3, administrator support, stock and commercial dashboards. It retains the existing `support/incidents/summary` and `dashboard` API data, company membership role decisions, original navigation callbacks and loading/retry behavior.

No new modules are enabled, no metrics are hard-coded, no UI change grants API rights, and the company switch continues to rebuild the workspace. The ordinary ERP screens remain fully operational, awaiting gradual adoption of shared components after review.

## CI and accessibility checks
- [ ] Exact-head CI green for TypeScript/Next build, PHP migration rehearsal, HTTP smoke and Playwright.
- [ ] Admin dashboard contains four informational metric `article` elements; visible labels remain unchanged.
- [ ] Commercial shortcut is a keyboard-focusable native button activated with Enter.
- [ ] Stock role retains the inventory-first experience and support roles retain incident/SLA data.
- [ ] The existing eight browser scenarios (tenant switching, invoicing/PDF, mobile, N3 support) remain green.
- [ ] Manual design/accessibility review for keyboard order, colors, screen readers, narrow screens and reduced motion.

## Non-goals and remaining work
This is the **first reusable UI slice**, not the full P06 design system or dashboard builder. Components do not yet cover form controls, table sorting/filtering, paginated grids, dialog primitives, notifications, dark-mode toggle, rich dashboard personalization or design-token governance. They will require dedicated tickets and tests.

Keep PR in draft until reviewed. This PR is frontend-only and deliberately isolated from the stacked PostgreSQL security work.
