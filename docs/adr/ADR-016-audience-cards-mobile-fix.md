# ADR-016 — Hero audience cards: fix broken mobile layout (positional selectors → classes)

## Status
Accepted (2026-05-19).

## Context

On mobile, the Hero "audience cards" block (Profesionales / Emprendedores / Empresas) rendered as 3 cramped columns instead of stacking — text wrapped one word per line and the cards clipped.

Root cause: the legacy `global.css` responsive block targeted that grid with **positional selectors**:

```css
@media (max-width: 768px) {
  #inicio > div:nth-child(3) > div:last-child { grid-template-columns: 1fr !important; }
}
```

When ADR-012 removed the Hero "pillars bar", the audience block shifted from being the section's 3rd child to its 2nd. The `nth-child(3)` selector then matched nothing → the audience grid kept its desktop `grid-template-columns: repeat(3, 1fr)` inline style on mobile.

This is exactly the brittle-selector failure mode flagged in [ADR-004](./ADR-004-styling-strategy.md): positional descendant selectors break silently when DOM structure changes.

## Decision

Replace the positional selectors with explicit classes:

- `Hero.astro`: the audience container gets `class="hero-audience"`; its grid gets `class="hero-audience-grid"` (alongside the existing `reveal-stagger`).
- `global.css`: the dead `#inicio > div:nth-child(2)` block (it had styled the now-deleted pillars bar) and the stale `#inicio > div:nth-child(3)` block are removed. New class-based rules take their place:

```css
@media (max-width: 768px) {
  .hero-audience       { padding: 40px 20px 56px !important; }
  .hero-audience h2    { font-size: 24px !important; }
  .hero-audience-grid  { grid-template-columns: 1fr !important; }
}
```

`!important` is still required because the column count lives in an inline `style` attribute on the grid — until the broader Tailwind migration ([ADR-004](./ADR-004-styling-strategy.md)) removes inline styles, `!important` is the pragmatic override.

## Consequences

**Easier:**
- The audience cards stack into a single readable column on mobile.
- The selector no longer depends on sibling order — future Hero changes won't silently break it again.
- Two dead rule blocks removed (the `nth-child(2)` block styled a component deleted in ADR-012).

**Harder / accepted trade-offs:**
- Still using `!important` to beat inline styles. Tracked under ADR-004; not worsened by this change.
- Other sections (`#metodologia`, `#productos`) still carry legacy positional selectors in the same `@media` block. They were not touched here — they currently render acceptably and changing them now would widen the blast radius of a one-line bug fix. If any of them breaks after a future refactor, the fix is the same pattern applied here.

## Reference paths

- `src/components/Hero.astro` — `.hero-audience` / `.hero-audience-grid` classes.
- `src/styles/global.css` — `@media (max-width: 768px)` Hero block.
