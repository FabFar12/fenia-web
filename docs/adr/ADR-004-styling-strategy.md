# ADR-004 — Styling Strategy: Phased migration from inline styles to Tailwind v4

## Status
Accepted (2026-05-19). Migration in progress — phased by component (see Plan Maestro Fase 2).

## Context

Tailwind v4 (`^4.2.2`) is installed and configured (`tailwind.config.mjs` defines brand tokens: `navy`, `cyan`, `coral`, font family). However, **not a single Tailwind class is used in the codebase as of 2026-05-19.**

Three CSS dialects coexist:

| Dialect | Where it lives | Files |
|---|---|---|
| Inline `style="..."` (Astro/HTML) | Inside elements | `Hero.astro`, `Metodologia.astro`, `Productos.astro` |
| `<style>` block (component-scoped CSS, hand-written classes) | At the bottom of `.astro` files | `Nav.astro`, `Confianza.astro`, `Contacto.astro`, `Footer.astro` |
| JSX `style={{...}}` (object literals) | Inside React components | `Servicios.jsx`, `IntroLoader.jsx` |

Plus a `src/styles/global.css` with **brittle responsive overrides** like:

```css
#inicio > div:first-child > div:last-child { display: none !important; }
```

These descendant positional selectors break the moment any component's DOM is reordered. There are ~40 such rules.

The brand hex values (`#00B4D8`, `#E8573D`, `#0B1A2E`) are repeated **dozens of times** across components. No central theming.

## Decision

**Target state: Tailwind v4 utility classes everywhere, leveraging the tokens already defined in `tailwind.config.mjs`. Inline styles only allowed for runtime-computed CSS variables (rare).**

Migration strategy — **incremental and visually verified**:

1. **Refactor one component per PR.** Smallest first (Nav → Footer → Contacto → Confianza → Productos → Metodologia → Hero → Servicios).
2. **For each refactored component:**
   - Take a Playwright screenshot of the production state (full-page + the component in isolation, desktop 1440px and mobile 375px).
   - Rewrite using Tailwind utilities + token names (`bg-navy`, `text-cyan`, `font-jakarta`).
   - Take screenshots of the new state.
   - **Compare pixel-by-pixel.** Any unintended visual change blocks the PR.
3. **Delete the corresponding override block in `global.css`** as each component is migrated to mobile-first Tailwind utilities (`md:` / `lg:` breakpoints replace the descendant `@media` hacks).
4. **Keep `<style>` blocks only for**:
   - SVG-specific styles that don't compose well as utilities.
   - Animation keyframes (`@keyframes`) until Tailwind v4's animation utilities cover them.
5. **Token enforcement**: any hex value not coming from `tailwind.config.mjs` is a code smell. Add new tokens to the config; never inline new hexes.

### What we are NOT doing

- We are NOT renaming or restructuring HTML for SEO / a11y at the same time as the style migration. Those are separate PRs to keep visual diffs minimal.
- We are NOT introducing CSS Modules, styled-components or any other CSS-in-JS solution. Tailwind covers the use case.

## Consequences

**Easier:**
- One source of truth for colors (the Tailwind config).
- Responsive becomes mobile-first and composable instead of fragile-override.
- Theme variation (dark/light, A/B tests, seasonal palettes) becomes feasible.
- Class names communicate intent (`text-white/65 hover:text-white`) better than positional CSS.
- LLMs working on this codebase will produce consistent code (Tailwind classes are the most common idiom in their training data).

**Harder / accepted trade-offs:**
- Component-by-component migration takes time (estimated ~2–3 weeks of part-time work).
- Some inline-heavy components (Hero, with 400-char `style` strings) are tedious to convert. Playwright capture/compare is non-negotiable for them.
- Until the migration completes, the codebase has **mixed dialects**. New code must be Tailwind; old code is grandfathered but flagged for migration in subsequent PRs.

## Style guide entry-point

[`docs/ai-context/style-guide.md`](../ai-context/style-guide.md) — concrete patterns, do's and don'ts, naming conventions.
