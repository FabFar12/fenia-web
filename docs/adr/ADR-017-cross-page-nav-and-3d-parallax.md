# ADR-017 — Cross-page section navigation + Hero 3D scroll parallax

## Status
Accepted (2026-05-19).

## Context

Two issues from a project-lead review:

1. **Broken nav from sub-pages.** The Nav and Footer section links were bare
   anchors (`#inicio`, `#servicios`, …). Those sections only exist on the
   home page. From `/dejanos-tu-testimonio` (or `/404`), clicking a nav link
   produced `/dejanos-tu-testimonio#inicio` — the hash was appended to the
   current URL, which has no such section, so nothing happened.

2. **Static Hero 3D.** The lead asked for the moving neural-network graphic
   at the top to gain a parallax effect — "como dentro de una ventana"
   (depth, as if seen through a window).

## Decision

### 1. `sectionHref()` — page-aware anchor links

A helper in `src/data/site.ts`:

```ts
export function sectionHref(anchor: string, pathname: string): string {
  return pathname === '/' ? anchor : `/${anchor}`;
}
```

- On the **home page** it returns the bare `#anchor`. Same-page scroll,
  and the Nav scroll-spy selector `.nav-link[href^="#"]` keeps matching.
- On **any other page** it returns `/#anchor`, so the browser navigates to
  the home page first and then scrolls to the section.

Applied in `Nav.astro` and `Footer.astro` (the two components that render on
sub-pages). Each computes `Astro.url.pathname` once and passes it to
`sectionHref()`. The Nav brand link points to `#inicio` on home and `/` on
sub-pages.

`Contacto.astro` and `Hero.astro` keep bare `#anchor` links — those
components only ever render on the home page, so the cross-page case can't
occur there.

**Why not always use `/#anchor`?** Because on the home page a bare `#anchor`
keeps the scroll-spy and smooth-scroll behavior untouched, and avoids any
chance of the View Transitions router treating `/#anchor` as a navigation.
Page-aware is the lowest-risk option.

### 2. Hero 3D scroll parallax

`src/components/Hero.astro`: the 3D layer wrapper gets `class="hero-3d-layer"`.

`src/styles/global.css`:

```css
@keyframes hero-3d-parallax {
  from { transform: translateY(0); }
  to   { transform: translateY(64px); }
}
.hero-3d-layer {
  animation: hero-3d-parallax linear both;
  animation-timeline: scroll(root block);
  animation-range: 0 80vh;
  will-change: transform;
}
```

This is a **CSS scroll-driven animation**: `animation-timeline: scroll()`
ties the animation's progress to the document's scroll position rather than
to time. As the page scrolls from 0 to 80 vh, the 3D layer translates down
by up to 64 px — i.e. it *lags* the natural upward scroll, so it reads as a
deeper plane behind the foreground copy. The mouse-driven parallax already
in `NeuralNetwork3D` (group rotates toward the cursor) now stacks with this
scroll parallax for a layered depth effect.

Crucially this runs **entirely on the compositor** — no JS scroll handler —
so it doesn't reintroduce the scroll jank fixed in ADR-012.

## Consequences

**Easier:**
- Nav + Footer links work from every page. From a sub-page they bounce you
  home and land on the right section.
- The Hero gains real depth on scroll without any JS or perf cost.

**Harder / accepted trade-offs:**
- `animation-timeline: scroll()` is supported in Chromium 115+ and is still
  partial in Firefox/Safari as of early 2026. On unsupported engines the
  animation simply doesn't run → the 3D layer is static → no parallax, but
  nothing breaks. Graceful degradation, acceptable.
- `prefers-reduced-motion: reduce` disables the parallax (added to the
  existing reduced-motion block).
- The 64 px translate is deliberately modest so the network never reveals an
  empty edge of its (larger-than-visible) container.

## Reference paths

- `src/data/site.ts` — `sectionHref()` helper.
- `src/components/Nav.astro`, `src/components/Footer.astro` — page-aware links.
- `src/components/Hero.astro` — `.hero-3d-layer` class.
- `src/styles/global.css` — `hero-3d-parallax` keyframes + scroll timeline.
