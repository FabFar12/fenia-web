# ADR-012 — Post-review fixes: scroll lag + interactivity polish + Hero/Footer cleanup

## Status
Accepted (2026-05-19). Triggered by direct visual review of the dev branch by the project lead. Five UX issues + one critical perf bug, all addressed in a single commit.

## Context

After landing Packs 1+2+3+4+v2 the project lead ran `npm run dev` and reported six issues with screenshots:

1. **Hero "pillars bar"** (4 disciplines decorative row at the bottom of the Hero): "no anda" — visually dead, duplicates the Methodology section right below.
2. **Hero right-column service cards** (Consultoría / Capacitación / IA aplicada / Productos digitales): static, no interactivity, no clear affordance.
3. **Methodology numbered pillars 01-04**: not clickable, no hover state, the "02" hardcoded highlight feels arbitrary.
4. **Convergence integration block** (No es consultoría tradicional + diagram + 4 differentiator bullets): static. Only the first differentiator is highlighted.
5. **Footer brand block**: two FENIA logos appear side-by-side — the legacy SVG wordmark + the new text wordmark. Duplicated.
6. **🚨 Scroll performance**: "cuando uso el scroll del mouse, la página responde tarde y se ve muy robótico, pero sobre todo a destiempo y arruina completamente la experiencia."

## Decision

### 1. Footer — remove the legacy SVG logo

`src/components/Footer.astro`: the inline `<svg class="footer-logo-svg">` block is deleted. The text wordmark (`<span class="footer-logo-text">FENIA</span>` + subtitle) is kept and slightly upweighted (font-size 18 → 22, weight 700 → 800, letter-spacing tightened) so it carries the brand alone.

### 2. Hero — delete the pillars bar

The 4-item decorative row (Pensamiento estratégico · Neuro-inteligencia aplicada · Gestión emocional · Impacto real y escalable) is **removed entirely** from `Hero.astro`. It duplicated 3 of the 4 Methodology pillars and added visual noise without functional value.

### 3. Hero — service cards become interactive

Each of the 4 floating cards (now data-driven from `services` in `site.ts`) becomes:
- An `<a href="#servicios">` wrapper with `class="hero-svc-float"` — applies a subtle CSS keyframe bob (`translateY(-5px)` over 5 s) with staggered animation-delays per card.
- An inner `<div class="hero-svc-card tilt-card">` — the `.tilt-card` class hooks into the existing Pack v2 mousemove handler for the rotateX/Y effect.
- A `data-service-slug={slug}` attribute the inline `<script>` reads to dispatch a `fenia:select-service` `CustomEvent`.
- A `:hover` state in CSS that brightens the border (cyan), boosts the shadow, and switches the icon color to cyan.

`Servicios.jsx` adds a `useEffect` that listens for `window.addEventListener('fenia:select-service', …)` and calls `setActive(idx)` to switch tabs. Clicking a Hero card now: (a) smooth-scrolls to `#servicios`, (b) pre-selects the matching tab on arrival.

### 4. Methodology pillars — hover-driven + auto-cycle

`Metodologia.astro` markup is restructured: the inline-style + conditional `pillar.highlight` rendering is replaced with semantic classes (`.meth-pillars` / `.meth-pillar` / `.meth-pillar-num` / `.meth-pillar-dot` / `.meth-pillar-title` / `.meth-pillar-body` / `.meth-pillar-rule`). The `pillar.highlight` field in `site.ts` is no longer consumed (kept in the type for future use).

`global.css` defines:
- Default state (all subdued: gray num badge + small gray dot + invisible rule).
- `.meth-pillar.is-active` / `.meth-pillar:hover`: cyan num badge with soft cyan glow + scale 1.04 + cyan dot + visible cyan rule (scaleX 0 → 1).
- `.meth-pillars:hover .meth-pillar:not(:hover)` dims unhovered pillars to 0.45.

`animations.ts` adds a generic `initAutoCycle(containerSelector, itemSelector, intervalMs)` helper. The container's `mouseenter` / `mouseleave` pauses/resumes the cycle. An `IntersectionObserver` starts/stops the interval based on visibility (avoids burning CPU when the section is off-screen). Methodology pillars cycle every 4.2 s.

### 5. Convergence differentiators — auto-cycle

The 4 bullets (Diagnóstico antes de solución / Evidencia neurocientífica real / IA como potenciador / Métricas de impacto concretas) become `.conv-diff` items inside `.conv-differentiators`. Same `initAutoCycle` helper rotates the highlight at 3.2 s — the active bullet's dot scales 1.4× with a cyan glow, its text brightens to 0.95.

### 6. 🚨 Scroll lag — three coordinated fixes

The reported "robotic scroll" was caused by three compounding factors. All three are addressed:

**Fix 6a — `#scroll-progress` no longer triggers layout reflow.**
Previously the bar used `bar.style.width = '${pct}%'`, which forces layout recalculation on every scroll tick. Now it uses `transform: scaleX(fraction)` with `transform-origin: 0 50%` — pure compositor-layer work, zero layout. CSS also gains `will-change: transform`.

**Fix 6b — Mousemove handlers share a single rAF pump.**
Pack 1 (`.cta-glow`) and Pack v2 (`.cta-magnetic`) each had their own `window.addEventListener('mousemove', …)` handler iterating their elements and calling `getBoundingClientRect()` on each. With both active and the user scrolling + moving the mouse simultaneously, the main thread was being hit twice per frame with layout-forcing reads. Merged into a single `initCtaInteractions()` function that updates `pendingX` / `pendingY` on mousemove and queues a single `requestAnimationFrame(apply)` if not already queued.

**Fix 6c — 3D `useFrame` suspends when Hero is offscreen.**
`NeuralNetwork3D.jsx` now wraps the `Canvas` in a parent `<div ref={wrapperRef}>` watched by an `IntersectionObserver` (rootMargin `20% 0px 20% 0px` for early start/late stop). When the wrapper leaves the viewport, `Canvas`'s `frameloop` prop flips from `'always'` to `'never'`, suspending the WebGL render loop entirely. This frees the GPU + main thread for the rest of the page. When the user scrolls back to the Hero, the loop resumes seamlessly.

`scroll-behavior: smooth` on `html` is **kept** — it powers the anchor-click smooth scrolling that the Nav and audience-card CTAs depend on, and modern browsers do not apply it to wheel/touchpad input. The lag was elsewhere.

## Consequences

**Easier:**
- Scroll feels native again. The 3D + Bloom pipeline no longer competes for the main thread on every wheel event past the Hero.
- The Hero communicates "click these to learn more" via clear hover affordances + click-to-open-tab.
- Methodology and the convergence block feel "alive" without requiring user interaction (auto-cycle), while still rewarding interaction (hover overrides cycle).
- Footer reads coherently with one wordmark.

**Harder / accepted trade-offs:**
- Two `IntersectionObserver`s added (one for the Canvas wrapper, one per auto-cycle container). Negligible CPU cost.
- The data-driven Hero card layout assumes 4 services. If `services` ever grows past 4 entries, the position table needs an entry too (or we move to a generated layout). Documented in the file.
- The `pillar.highlight` field in `methodologyPillars` is now unused at render time. We keep it in the type to avoid a breaking change and as a hint for future static-default selection if we ever revert the auto-cycle.

## Reverting

- **Pillars bar**: restore the `<!-- Pillars bar -->` block in `Hero.astro` (see git history `db163df…7fb7d80`).
- **Methodology interactive**: replace the new template with the prior `pillar.highlight ? …` inline-style version and remove the `initAutoCycle('.meth-pillars', …)` call.
- **Scroll progress fix**: revert `bar.style.transform` back to `bar.style.width = pct + '%'` and the matching CSS — only do this if a future browser or environment behaves better with width animation.
- **3D pause**: remove the `wrapperRef` / `inView` state + `frameloop` prop in `NeuralNetwork3D.jsx`.
- **Footer SVG**: paste the original `<svg class="footer-logo-svg">` back.

Each is independently revertible.

## Reference paths

- `src/components/Footer.astro` — SVG logo removed.
- `src/components/Hero.astro` — pillars bar removed; service cards data-driven + interactive + dispatch event.
- `src/components/Metodologia.astro` — pillars + differentiators restructured for auto-cycle.
- `src/components/Servicios.jsx` — listens for `fenia:select-service`.
- `src/components/NeuralNetwork3D.jsx` — frameloop toggle.
- `src/scripts/animations.ts` — `initScrollProgress` (transform), `initCtaInteractions` (merged), `initAutoCycle` (new).
- `src/styles/global.css` — `#scroll-progress` transform, `.hero-svc-*`, `.meth-pillar*`, `.conv-diff*` styles.
