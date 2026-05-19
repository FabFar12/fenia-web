# ADR-013 — Convergence diagram animation · Servicios bullets cycle · Servicios mobile-responsive fix

## Status
Accepted (2026-05-19). Triggered by a second round of project-lead visual review against the dev branch.

## Context

After ADR-012 fixes shipped, the project lead reviewed again and surfaced three more issues:

1. **Convergence diagram** (the small SVG in the integration block under Methodology with `Neuro · IA · Estrategia` labels) is still static. The user explicitly asked: *"el dibujito del medio, ese circulo, quiero que sea animado, que se muevan las cositas del dibujo."*
2. **Servicios detail panel bullets** (Diagnóstico organizacional / Diseño de intervenciones / Acompañamiento / Optimización de toma de decisiones) repeat the same defect we fixed in Methodology and the differentiators list: only the first bullet is highlighted (cyan dot, hardcoded `i === 0`), the rest stay grey. No interactivity.
3. **Servicios layout breaks on mobile**: the grid is `gridTemplateColumns: 280px 1fr` regardless of viewport. On <400 px the 280-px tab column plus the section's 48-px horizontal padding leaves negative space for the detail panel — text and CTAs spill ("se iba al choto").

## Decision

### 1. Convergence diagram — make it alive (CSS-only)

In `Metodologia.astro` the diagram is restructured so each peripheral `<circle class="conv-node">` is wrapped in a `<g class="conv-drift conv-drift-N">`. The drift goes on the wrapper; the existing hover scale (Pack 2) stays on the inner `<circle>`. Both transforms compose without fighting.

`global.css` defines:
- Four unique drift keyframes (`conv-drift-1` … `conv-drift-4`) — each moves ±2–3 px along non-aligned paths over 6.4 s, 7.2 s, 8.5 s, 9.1 s, with staggered negative `animation-delay`s for an organic, non-synchronized feel.
- `conv-halo-pulse` (3.2 s) and `conv-core-pulse` (2.6 s) for the center halo (scale 1 → 1.45 + opacity dip) and core (scale 1 → 1.18 + opacity bump).
- `conv-line-pulse` (4 s) + `conv-line-pulse-coral` for the 4 connecting lines — opacity breathes 0.22 → 0.42 (and 0.18 → 0.34 for the coral one), with each line offset by `animation-delay` so the breathing feels distributed.
- `.conv-diagram:has(.conv-node:hover) .conv-drift { animation-play-state: paused; }` — pauses all drifts as soon as the user hovers any node, giving them a steady target. `:has()` is widely supported in modern browsers; degradation is graceful (animation just keeps running).

The peripheral node baseline opacity is also bumped from `0.3 – 0.5` to `0.5 – 0.6` so they read better against navy without depending on the pulse.

### 2. Servicios bullets — auto-cycle highlight + hover override

`Servicios.jsx` is rewritten to use semantic class names instead of long inline-style chains, and the bullets list becomes:

- `useState(bulletActive)` tracks the currently highlighted bullet (0 by default).
- `useEffect` resets `bulletActive` to 0 whenever `active` (selected service tab) changes, then starts a `setInterval` that increments it modulo `a.bullets.length` every 2.8 s.
- A `bulletPausedRef` (ref) gates the increment: when the user hovers any bullet, ref → true and the interval no-ops; mouseleave on the bullets container resets ref → false.
- Each bullet's `onMouseEnter` immediately jumps the active to that index (so hovering feels responsive, not just a pause).
- `prefers-reduced-motion: reduce` short-circuits — the interval doesn't start; `bulletActive` stays at 0.

CSS state classes mirror the methodology pillars + differentiators: `.svc-bullet.is-active` and `.svc-bullet:hover` apply the cyan dot (scale 1.4 + cyan + drop-shadow glow) and brighten the text to 0.95.

### 3. Servicios mobile-responsive layout

The component was using inline `gridTemplateColumns: "280px 1fr"`. Inline styles can't carry media queries. The refactor adds semantic classes (`.servicios-grid`, `.servicios-tabs`, `.servicios-tab`, `.servicios-detail`, `.servicios-detail-head`, `.servicios-detail-foot`, …) and moves the layout entirely to `global.css`. Mobile rules:

```css
@media (max-width: 768px) {
  .servicios-grid       { grid-template-columns: 1fr; }
  .servicios-tabs       { flex-direction: row;
                          overflow-x: auto;
                          scroll-snap-type: x mandatory; }
  .servicios-tab        { flex-shrink: 0;
                          min-width: 200px;
                          scroll-snap-align: start; }
  .servicios-tab-rule   { /* repositioned to bottom-edge underline */ }
  .servicios-detail     { padding: 32px 22px; }
  .servicios-detail-foot{ flex-direction: column; }
  .servicios-detail-cta { text-align: center; }
}
```

Below 480 px the tabs shrink to 180 px each. The result: on phones, you see a horizontally-scrollable strip of tabs (each tab carries icon + title + subtitle), then the full-width detail panel beneath with proper padding. CTAs and audience chips wrap.

### Aesthetic consistency check

We now have four places using the **same** "highlight cycler" idiom in the Methodology section + Servicios section + Convergence block:

| Component | Cycle (s) | Pause on hover | Reduced motion |
|---|---|---|---|
| Methodology pillars 01–04 | 4.2 | yes | static at idx 0 |
| Convergence differentiators | 3.2 | yes | static at idx 0 |
| Servicios bullets | 2.8 | yes | static at idx 0 |
| Convergence node drift | continuous CSS | yes (`:has`) | animation off |

The slightly different intervals (4.2 / 3.2 / 2.8) are deliberate — running all three at the same rhythm would feel mechanical. Staggered rhythms read as organic / alive.

## Consequences

**Easier:**
- The convergence diagram now sells what it's supposed to sell — convergence — through motion that reinforces the metaphor.
- Servicios detail bullets feel as alive as Methodology and Convergence, restoring tonal coherence across the page.
- Mobile Servicios is functional. Horizontal-scroll tab strips are an idiomatic mobile pattern (Twitter, Instagram, Material You).

**Harder / accepted trade-offs:**
- `Servicios.jsx` grew from one big JSX blob with inline styles to a slightly leaner JSX + dedicated CSS section. Net LOC roughly even.
- Scroll-snap on the mobile tab strip means tabs gently snap to the left edge when the user releases their swipe. Some users prefer free scroll — change is one line if we ever want to drop it.
- `:has()` selector for pausing drift on hover is unsupported in older Firefox <121 (released Dec 2023). Graceful: drift continues during hover. Trivial.

## Reverting

- **Drop convergence animations**: delete the `.conv-drift*`, `.conv-center-halo`, `.conv-center-core`, `.conv-line-pulse*` rules; the diagram becomes static again.
- **Drop bullet cycler**: remove the `useEffect` interval in `Servicios.jsx`, hardcode `bulletActive=0`. Or remove the `.is-active` class application entirely.
- **Drop mobile responsive**: remove the `@media (max-width: 768px)` block. Layout reverts to 280-px tab column on all viewports (broken on mobile but unblocking if the responsive ever conflicts).

Each is independently revertible.

## Reference paths

- `src/components/Metodologia.astro` — convergence SVG restructured with drift wrappers.
- `src/components/Servicios.jsx` — bullet cycler + semantic classes.
- `src/styles/global.css` — `.conv-drift*`, `.conv-center-halo`, `.conv-line*`, `.servicios-*`, `.svc-bullet*`, and the `@media (max-width: 768px)` block for Servicios.
