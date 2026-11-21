# ATLAS ONE — P06.04: tables and forms UI pilot

**Status:** draft / CI candidate. **Base:** PR #32, `feature/atlas-p06-shared-ui-components`. Stacked on P06 UI PRs #30–#32. Nothing is merged or deployed.

## Goal

Continue the P06 Experience Platform work by harmonizing **existing, real** ERP tables and form fields without rewriting them, fabricating functionality, adding backend endpoints, or changing authorization.

## Delivered components

| Component | File | Contract |
|---|---|---|
| `DataTableFrame` | `frontend/src/components/ui/DataTableFrame.tsx` | Named, keyboard-focusable horizontal-scroll region around an existing semantic `table`. Exposes `aria-busy` and preserves the caller's filters, columns, actions and pagination. |
| `FormField` | `frontend/src/components/ui/FormField.tsx` | Native `label` wrapping an existing `input` or `textarea`; optional hint. Browser validation and event handlers remain on the original control. |
| Theme | `frontend/src/app/data-forms.css` | Table headings, row focus, consistent control borders, focus states, responsive widths and reduced-motion behavior. |

### Integrated surfaces

- Dashboard: **recent orders** table.
- ERP workspace: **clients, suppliers, products, quotes, orders, invoices, users, stock movements, audit logs** through the existing `ListView` table.
- Document detail: line-item table.
- ITSM: incident list table, title and description on incident creation.
- Login: email and password fields with the original labels and HTML validation.
- ERP editors: client name, stock adjustment quantity and reason.

All underlying data requests, tenant filters, roles, mutations, search, pagination, invoice PDFs, incident escalation/diagnostics and existing dialog state remain unchanged.

## Test plan

- [ ] CI succeeds for this PR's **exact commit SHA**, including Next.js type/build, PostgreSQL, PHP, HTTP and full Playwright regression suite.
- [ ] Browser sales test confirms the clients' table is exposed as a keyboard-focusable named region.
- [ ] Browser support test confirms its table region is discoverable by accessible name.
- [ ] Existing tests still verify correct native labels on login, client creation and incident creation.
- [ ] Manual desktop/mobile inspection: long tables scroll horizontally without overflowing the viewport and keyboard users can focus the scroll region.
- [ ] Manual audit: required fields, numeric ranges, focus order, field descriptions, disabled controls and dialog accessibility.
- [ ] Confirm no new API access, no elevated role and no backend migrations.

## Out of scope / next work

No client-side sorting that diverges from server-side pagination; no artificial filtering on endpoints that do not support it. True reusable data-grid column definitions, bulk operations, CSV exports, composable form validation, server-rendered deep links and personalized dashboards remain independent tasks. CSS does not replace a professional automated accessibility audit.

**Release status:** NO-GO pending exact-SHA CI and human interface review. Frontend-only, no deployment or production changes.
