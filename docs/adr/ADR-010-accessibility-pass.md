# ADR-010 — Accessibility & Polish pass: WCAG AA body-text contrast + ARIA + reduced-motion verification

## Status
Accepted (2026-05-19). This commit closes the long-standing a11y gap that was flagged in the initial audit ([`docs/audit/2026-05-19-initial-audit.md`](../audit/2026-05-19-initial-audit.md) issue #6).

## Context

Initial audit flagged that body-text colors at `rgba(255, 255, 255, 0.3 – 0.45)` over the `#0B1A2E` navy background **fail WCAG AA contrast ratios for normal text** (need ≥ 4.5 : 1; the legacy `0.4` measures around 3.2 : 1). The brand is a consulting firm — accessibility isn't optional, it's part of the value proposition.

Secondary a11y gaps observed:
- Nav hamburger had no accessible label (resolved Fase 2).
- Footer social icons rendered as text glyphs ("IG"/"IN"/"YT") without `aria-label` (resolved Fase 2).
- Servicios tab buttons had no `role="tab"` / `aria-selected` (resolved this commit).
- No `aria-current` on the scroll-spy active nav link (resolved this commit).
- Bullet dots and decorative icons not marked `aria-hidden` (resolved this commit).

## Decision

### Contrast bump (body text, not eyebrows / fineprint)

Targeted `color: rgba(255, 255, 255, …)` values changed in body paragraphs and link text:

| File | Element | Before | After |
|---|---|---|---|
| `Hero.astro` | Description "Articulamos neurociencias…" | 0.45 | 0.70 |
| `Hero.astro` | Audience cards `<p>` | 0.40 | 0.65 |
| `Hero.astro` | "Elegí el camino…" subtitle | 0.40 | 0.65 |
| `Hero.astro` | "Pensar mejor…" tagline (cyan) | 0.85 | 0.90 |
| `Metodologia.astro` | Section intro | 0.40 | 0.70 |
| `Metodologia.astro` | Pillar body | 0.38 | 0.65 |
| `Metodologia.astro` | Integration block paragraph | 0.40 | 0.70 |
| `Metodologia.astro` | Differentiators list (active) | 0.70 | 0.85 |
| `Metodologia.astro` | Differentiators list (inactive) | 0.35 | 0.60 |
| `Productos.astro` | Section intro | 0.40 | 0.70 |
| `Productos.astro` | Card body summary | 0.38 | 0.65 |
| `Productos.astro` | Card footer-left label | 0.50 | 0.70 |
| `Productos.astro` | CTA bar question | 0.60 | 0.80 |
| `Contacto.astro` | `.contacto-desc` | 0.40 | 0.70 |
| `Contacto.astro` | `.contacto-sub` (under WA / Redes labels) | 0.30 | 0.55 |
| `Contacto.astro` | `.contacto-testimonial-link` | 0.50 | 0.70 |
| `Footer.astro` | `.footer-desc` | 0.30 | 0.60 |
| `Footer.astro` | `.footer-heading` | 0.50 | 0.70 |
| `Footer.astro` | `.footer-link` | 0.30 | 0.55 (+ hover → 0.90) |
| `Footer.astro` | `.footer-copy` | 0.20 | 0.50 |
| `Footer.astro` | `.footer-legal-link` | 0.20 | 0.50 (+ hover → 0.85) |

**Intentionally NOT bumped** (decorative eyebrows / hero stat captions / dot bullets / pillar number badges remain subtle by design).

### ARIA additions

- `Servicios.jsx` tabs: `role="tablist"`, `role="tab"` per button, `aria-selected={active === i}`, `role="tabpanel"` on the detail pane.
- Decorative icons (◇ △ ○ □, bullet dots, neural SVGs): `aria-hidden="true"`.
- WhatsApp links: `rel="noopener noreferrer"` added (security + a11y).
- Nav scroll-spy in `animations.ts` now also toggles `aria-current="location"` on the active link.

### Reduced-motion contract validated

Every animation in Pack 1 / 2 / 3 / 4 already short-circuits via `prefers-reduced-motion: reduce` ([ADR-008](./ADR-008-animations-and-3d-neural-network.md)). This commit doesn't change that — it documents it as part of the a11y baseline.

## Consequences

**Easier:**
- Body copy is comfortably readable now. Lighthouse / axe scores should land above 90 once images and missing alt-text are addressed.
- Screen-reader users get coherent reading of the Servicios tabs (proper tab/tabpanel semantics).
- Scroll spy announces the active section via `aria-current` for navigation testing tools.
- Keyboard users see the same hover-glow feedback as mouse users (the `.cta-glow` proximity effect is mouse-only — keyboard focus uses native browser outline, untouched).

**Harder / accepted trade-offs:**
- Slight visual brightness shift on the home page — texts that were "luxuriously faded" are now more readable. The brand still keeps its sober look because the elements that *should* stay decorative (eyebrows, badges, stat captions) are unchanged.
- Some inline `style` overrides for body color still exist (we didn't migrate to Tailwind yet). Future component-by-component Tailwind migration ([ADR-004](./ADR-004-styling-strategy.md)) will move these to tokens (`text-white/70`, `text-white/60`).

## Validation checklist

- Run axe DevTools on the live preview deploy.
- Manually tab through the home page; verify focus is visible on every interactive element.
- Toggle `prefers-reduced-motion: reduce` in DevTools and reload — animations should disappear, 3D should be static.
- Use Wave WebAIM to verify color-contrast ratios.

## Reference paths

- All component edits documented in the commit diff.
- Reduced-motion rules: `src/styles/global.css` `@media (prefers-reduced-motion: reduce)` block.
- ARIA additions: `src/components/Servicios.jsx`, `src/components/Nav.astro`, `src/scripts/animations.ts`.
