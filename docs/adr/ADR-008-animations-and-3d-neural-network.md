# ADR-008 — Visual leap: Pack 1+2+3 animations + Pack 4 three.js neural network

## Status
Accepted (2026-05-19). MVP shipped as v1; iterations expected.

## Context

Owner requested a "salto visual" — a step change in dynamism — aligned with a sophisticated/scientific aesthetic ("Linear / Stripe / Vercel" style). After a structured proposal of 4 animation packs (see commit thread), the owner explicitly approved **all four packs in a single commit**.

The four packs proposed:

- **Pack 1 — Ambient foundations**: scroll-reveal, stat counter, gradient hue-shift on H1, hover-lift on cards, scroll spy in Nav.
- **Pack 2 — Brand neural network animations** (the existing static SVG comes alive).
- **Pack 3 — Premium polish**: Astro View Transitions between pages, CTA proximity glow, scroll progress bar.
- **Pack 4 — Immersive 3D / WebGL**: full three.js scene replacing the Hero SVG.

## Decision

Ship all four packs in a single commit (`feat(fase-3)`), with one explicit compromise: **Pack 4 supersedes Pack 2's Hero SVG animation**. The 3D scene IS the new animated neural network — animating the legacy SVG and then replacing it would be wasted work. Pack 2 therefore retains only:

- Methodology pillars cascade reveal (`reveal-stagger`).
- Convergence diagram interactivity in Metodología (hover-aware).

### Technology choices

- **Pack 1 + 3 runtime**: vanilla TypeScript module (`src/scripts/animations.ts`) bundled by Astro. ~3 KB after gzip. Pure `IntersectionObserver` + `requestAnimationFrame`. No animation libraries.
- **Pack 4 runtime**: `three` + `@react-three/fiber`. Loaded as an Astro island via `client:visible` so the WebGL context only spins up when the Hero is on-screen. Bundle delta ≈ 170 KB gzipped (acknowledged cost — see Consequences).
- **Pack 3 cross-page transitions**: Astro's `<ClientRouter />` (`astro:transitions`). Native View Transitions API where supported, polyfill fallback elsewhere.
- **Reduced motion**: every animation guards on `prefers-reduced-motion: reduce`. The 3D scene renders static (no `useFrame` mutations). CSS animations short-circuit via media query.

### CSS contract (added to `global.css`)

| Class | Purpose |
|---|---|
| `.reveal` | Element starts hidden (opacity 0, translateY 8px); JS adds `.is-visible` when in viewport. 600ms ease-out. |
| `.reveal-stagger` | Container — when it becomes visible, direct `.reveal` children animate in with 80 ms stagger. |
| `.hover-lift` | `transform: translateY(-2px)` + cyan shadow on hover. Pure CSS, no JS. |
| `.cta-glow` | Receives a `--glow` custom property (0..1) driven by JS based on cursor proximity. Used by primary CTAs. |
| `.gradient-anim` | The "aumentada" H1 word — animated linear gradient that shifts hue across 6 s loop. |
| `[data-counter]` | Picked up by `animations.ts`; counts from 0 to `data-counter` value over 1.2 s when scrolled into view. |
| `#scroll-progress` | Fixed 2 px cyan bar at top of viewport; width tracks scroll position. |

## Consequences

**Easier:**
- The site now communicates "alive, science-driven, premium" instead of "static brochure".
- Brand-defining visual (the neural network) is now in 3D and reacts to the cursor — a literal embodiment of "neuro-inteligencia aumentada".
- A11y-respectful: reduced-motion users get a calm static experience identical to before, no exclusion.
- Vanilla Pack 1+3 runtime is forever-debuggable (no library upgrade cycle).
- All animations are scoped via opt-in classes; opting a section out is as simple as removing the class.

**Harder / accepted trade-offs:**
- **Bundle weight**: three.js + R3F adds ~170 KB gzipped to the home page. This is an explicit owner decision (proposed Pack 4-lite CSS alternative was declined in favor of full three.js). Mobile 4G TTI penalty ≈ +1–1.5 s on first load. Subsequent navigations are cached.
- **WebGL maintenance**: any future change to the neural network look (node count, line topology, colors, animation) must edit `NeuralNetwork3D.jsx`. No more "tweak a path in the SVG".
- **3D scene is v1**: minimal feature set on purpose — nodes pulse, lines exist, group eases toward cursor. Particles traveling along lines, bloom post-processing, audio-reactive variants are all *deliberately out of scope* for this commit. They can be added in follow-ups without architectural change.
- **Pack 2 partial scope**: the Hero SVG animation work was cut to avoid colliding with Pack 4. If the 3D scene is ever reverted, we'd need to re-implement the SVG animations (low cost — ~30 lines of CSS keyframes).
- **`<ClientRouter />`** changes the navigation model from full reloads to JS-driven SPA-like transitions. State that lives outside React islands persists across navigations. We re-init animations on `astro:page-load`. Tested across the only two routes today (`/` and `/dejanos-tu-testimonio`); adding a route later requires no extra wiring.

## Reverting

- **Disable Pack 1+3**: remove the `<script>import '../scripts/animations'</script>` from `Layout.astro`. CSS classes become inert (elements stay in their initial `.reveal` state — visually a slight opacity flicker until they're given `.is-visible` manually). To fully revert, also strip the `.reveal*` and `.cta-glow` classes from components.
- **Disable Pack 4**: revert the Hero SVG ↔ `<NeuralNetwork3D>` swap in `Hero.astro`. The component file can stay in the repo for later use.
- **Disable Pack 3 transitions**: remove `<ClientRouter />` from `Layout.astro`. Pages revert to full reloads.

## Reference paths

- Runtime: [`src/scripts/animations.ts`](../../src/scripts/animations.ts)
- 3D scene: [`src/components/NeuralNetwork3D.jsx`](../../src/components/NeuralNetwork3D.jsx)
- Styles: [`src/styles/global.css`](../../src/styles/global.css) (`/* ── Animations ── */` block)
- Layout integration: [`src/layouts/Layout.astro`](../../src/layouts/Layout.astro)
- Touched components: `Hero.astro`, `Metodologia.astro`, `Servicios.jsx`, `Productos.astro`, `Contacto.astro`, `Footer.astro`, `Nav.astro`.
