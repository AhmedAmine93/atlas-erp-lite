# ATLAS ONE Design System — P065.01–.02 (foundation)

The existing Next.js `atlas-theme.css` remains the current visual baseline.
This additive, versioned contract defines shared semantic tokens for Next.js,
future Oracle JET/Angular/mobile adapters and their eventual dark theme.

- Canonical values: `design-system/tokens.v1.json`.
- Web CSS adapter: `frontend/src/app/design-tokens.css`.
- Contract test: `scripts/verify-design-tokens.mjs`.
- Scope: palette, typography, spacing, radius and two semantic themes.
- Consumers should reference semantic tokens rather than raw color literals.
- **Not complete**: P065.03 component library, P065.04 language switching,
  P065.05 WCAG audits and P065.06 catalogue/visual regressions still need work.
- Theme remains opt-in; no visual changes to existing business pages in this PR.

The contract test checks that every semantic value maps to the corresponding
CSS custom property in both themes. Avoid overriding system focus defaults
or silently enabling dark mode before the accessibility review.
