# ADR-011 — Pack v2 premium polish: Bloom · idle drift · gradient lines · magnetic CTAs · tilt cards · sticky label · custom View Transitions

## Status
Accepted (2026-05-19). Single commit. All seven effects landed together.

## Context

After shipping Pack 1+2+3+4 in fase-3 the site felt "alive" but not "premium". Owner explicitly asked for "salto visual v2" and picked the full Bloque A+B+C bundle from the proposed roadmap (see chat). Bundle budget cleared (+30 KB for Bloom accepted).

## Decision

Seven effects, organized by purpose:

### 🌌 3D scene polish — NeuralNetwork3D

**v2.1 — Bloom post-processing** via `@react-three/postprocessing` + `postprocessing`:
- `<EffectComposer enableNormalPass={false}><Bloom intensity={1.1} luminanceThreshold={0.35} mipmapBlur radius={0.7} /></EffectComposer>`.
- Node cores switched from `meshBasicMaterial color="#00B4D8"` → `color="#1FE3FF" toneMapped={false}` so the cyan is bright enough to trip Bloom's luminance threshold.
- Result: cyan nodes have a soft glow that bleeds into the surrounding navy. Cinematic.
- Bundle delta: +27 KB gzipped.

**v2.2 — Idle camera drift**:
- `useWindowPointer` now tracks `lastMove: performance.now()`.
- `useFrame` checks `now - lastMove > 5000ms`; once idle, target rotation Y accumulates with a ramped intensity (0→full over 1 s after threshold).
- Wakes back to parallax instantly on the next pointer movement.
- Bundle delta: 0 (vanilla logic).

**v2.3 — Per-vertex gradient lines**:
- `<bufferAttribute attach="attributes-color">` added with two colors per line: bright cyan `(0.12, 0.85, 1.0)` at the start, deep teal `(0, 0.45, 0.65)` at the end.
- `lineBasicMaterial` gets `vertexColors` + `toneMapped={false}`.
- Result: lines no longer look flat; energy reads as flowing from one node to another.
- Bundle delta: ~+0 KB (geometry data only).

### 🎯 Interactivity polish — `src/scripts/animations.ts`

**v2.4 — Magnetic CTAs** (`.cta-magnetic`):
- On mousemove globally, for each `.cta-magnetic` element, if cursor is within `RADIUS=110px`, set CSS custom props `--mx` / `--my` proportional to the cursor offset (max `MAX_OFFSET=7px`).
- CSS: `transform: translate(var(--mx), var(--my))` with `transition: transform 240ms`.
- Applied to: primary "Solicitar un turno" buttons (Nav + Hero + Contacto), "Hablemos →" (Productos), "Consultar →" (Servicios), "Escribinos por WhatsApp" (404), "Enviar testimonio por WhatsApp" (dejanos-tu-testimonio).
- A11y: disabled by `prefers-reduced-motion: reduce`.

**v2.5 — Custom View Transitions** (CSS only):
- `::view-transition-old(root)` and `::view-transition-new(root)` get a slide animation (24 px horizontal offset, 280–320 ms) instead of the default crossfade.
- Trips on every Astro `<ClientRouter />`-driven navigation, currently `/` ↔ `/dejanos-tu-testimonio` ↔ `/404`.
- A11y: disabled by `prefers-reduced-motion: reduce` (zero animation).

**v2.6 — Section sticky label** (`#section-label`):
- Fixed bottom-right chip rendered by `Layout.astro` as part of `<body>`.
- `animations.ts` scroll-spy refactored: in addition to toggling `.nav-link-active`, it now updates `#section-label`'s text to `"NN — SectionName"` and toggles `.is-visible`.
- Hidden by default and **never shown on `#inicio`** (the Hero) — the chip kicks in once user scrolls past the Hero, providing ambient context awareness.
- Style: navy/85 + backdrop-blur + cyan-soft border, smooth fade+slide entrance.

### 🃏 Card polish

**v2.7 — Productos cards 3D tilt** (`.tilt-card`):
- On mousemove inside a card, JS sets `--tilt-x` / `--tilt-y` (degrees, max ±5°).
- CSS: `transform: perspective(900px) rotateX(var(--tilt-x)) rotateY(var(--tilt-y))`.
- On mouseleave, custom props reset to `0deg` with the same `transition: transform 240ms` for a smooth return.
- Applied to the 3 product cards in `Productos.astro` (replaces `.hover-lift` on those specific cards).
- A11y: disabled by `prefers-reduced-motion: reduce`.

## Consequences

**Easier:**
- The 3D scene now reads as cinematic (Bloom) rather than functional. The brand-defining "neuro-inteligencia" metaphor lands harder.
- Idle drift means the scene never feels frozen; visitors reading copy still see motion in their peripheral vision.
- Magnetic CTAs feel "tactile" — Stripe / Linear hallmark of polish.
- Section sticky label gives ambient nav feedback without taking nav space.
- Page transitions feel SPA-grade.

**Harder / accepted trade-offs:**
- Bundle +27 KB gzipped (Bloom). Acceptable; owner explicitly cleared budget.
- Bloom uses GPU; on very low-end mobile devices Bloom may degrade frame rate. We mitigate via `mipmapBlur` (cheaper) and `radius: 0.7` (small kernel). If a user reports lag, fallback path is to wrap `<EffectComposer>` in a `userAgent`-based feature gate.
- The tilt-card effect competes with the `.hover-lift` baseline elsewhere. We use tilt-card exclusively on the Productos cards; audience cards and other cards keep the simpler `.hover-lift`.
- View Transitions API is Chromium-first; Firefox falls back gracefully to instant navigation (no animation), no functional impact.
- Section label text is bilingual-by-coincidence (uses canonical Spanish names from the Nav). If we ever ship i18n, the label needs to consume the same translation source as the Nav.

## Reverting

- **Disable Bloom**: remove `<EffectComposer>` from `NeuralNetwork3D.jsx` `Canvas`. Saves ~27 KB.
- **Disable idle drift**: remove the `idleMs > IDLE_THRESHOLD` block from the `useFrame` in `Scene`.
- **Disable magnetic CTAs**: remove the `.cta-magnetic` class from elements OR delete `initMagneticCtas()` call from `initAll()` in `animations.ts`.
- **Disable view transitions**: delete the `::view-transition-*` block from `global.css`. Returns to default crossfade.
- **Disable sticky label**: remove `<div id="section-label">` from `Layout.astro`. The animations.ts code becomes a no-op.
- **Disable tilt cards**: swap `.tilt-card` ↔ `.hover-lift` on Productos cards.

Each effect is independently revertible — no inter-pack coupling.

## Reference paths

- 3D scene: [`src/components/NeuralNetwork3D.jsx`](../../src/components/NeuralNetwork3D.jsx) — Bloom, idle drift, gradient lines.
- Runtime: [`src/scripts/animations.ts`](../../src/scripts/animations.ts) — `initMagneticCtas`, `initTiltCards`, updated `initScrollSpy` with section label.
- Styles: [`src/styles/global.css`](../../src/styles/global.css) — Pack v2 block.
- Layout: [`src/layouts/Layout.astro`](../../src/layouts/Layout.astro) — `#section-label` element.
- Touched components: `Nav.astro`, `Hero.astro`, `Productos.astro`, `Contacto.astro`, `Servicios.jsx`, `404.astro`, `dejanos-tu-testimonio.astro`.
