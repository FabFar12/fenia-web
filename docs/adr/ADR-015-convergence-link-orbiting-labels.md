# ADR-015 — Convergence link: 4 differentiators ⇄ 4 atom electrons, with orbiting labels

## Status
Accepted (2026-05-19). Builds on ADR-014 (atom diagram). Adjusts the atom layout (viewBox 140→160, center 70,70→80,80) and adds orbiting labels + a shared active index.

## Context

Project lead's review of the ADR-014 atom:

1. The differentiator list ("Diagnóstico antes de solución" / "Evidencia neurocientífica real" / "IA como potenciador, no reemplazo" / "Métricas de impacto concretas") still highlighted only its first item — no hover interactivity, same defect already fixed elsewhere.
2. The atom had 4 electrons but they were anonymous. The 3 leftover static text labels (`Neuro` / `IA` / `Estrategia`) floated arbitrarily and didn't correspond to anything.
3. Explicit request: link each differentiator 1:1 with an electron, and have the electron carry the differentiator's name as a label that **moves with the particle**.

## Decision

### 1. One shared active index across diagram + list

The 4 `.conv-diff` items and the 4 `.orbit` electrons now share a single active index (0–3), managed by a new `initConvergenceLink()` in `animations.ts`:

- Auto-cycles the index every 3.2 s.
- `setActive(idx)` toggles `.is-active` on **both** the `idx`-th `.conv-diff` and the `idx`-th `.orbit`.
- Hovering a differentiator → pins the index to it (pause cycle, set active).
- Hovering an electron (`.electron-core` carries `data-orbit-idx`) → pins the index to it.
- `mouseleave` on either resumes the cycle.
- Visibility-gated by an `IntersectionObserver` so the timer doesn't run off-screen.
- `prefers-reduced-motion`: index frozen at 0, no cycle.

Result: at any moment one differentiator and its matching electron are highlighted together. Hovering either side reveals the pairing.

### 2. Orbiting labels via SVG counter-rotation

The 3 stray static labels are removed. Each electron now carries its own label that **orbits with it while staying upright**.

Technique — classic SVG nested counter-rotation:

```
<g class="orbit-N">                ← rotates around the nucleus (80,80)
  <g class="orbit-counter-N">      ← counter-rotates, same speed, opposite dir,
    <circle .electron-halo/>          pivoted on the electron's own centre
    <circle .electron-core/>
    <text   .electron-label/>
  </g>
</g>
```

- The outer `<g>` rotates around `(80,80)` (`transform-box: view-box; transform-origin: 80px 80px`).
- The inner `<g>` counter-rotates at the *same period, opposite direction*, pivoted at the electron's initial centre (`transform-origin: <ex>px 80px`).
- Net effect (proven by composing the two rotations): the label **translates around the orbit with the electron but its orientation never changes** — always upright, always a fixed offset above the electron.

Labels are short forms of the differentiators: `Diagnóstico`, `Evidencia`, `IA aplicada`, `Métricas`. They sit subtle by default (`fill rgba(255,255,255,0.5)`, 6.5 px) and brighten + grow when their orbit is active or hovered (`0.95`, 7.5 px).

### 3. Layout adjustment

To give the orbiting labels room without colliding with the nucleus:
- `viewBox` 140→160, center 70,70→80,80, render size 160→180 px.
- Orbit radii: 30 / 42 / 54 / 64 (was 22 / 36 / 50 / 58). The wider inner orbit keeps the inner label clear of the nucleus halo at every rotation.
- Orbit periods slowed to 9 / 12 / 15 / 11 s — the labels need to be readable as they pass, so the electrons move more slowly than the label-less ADR-014 version.

### 4. Active orbit freezes

`.orbit.is-active` (and its `.orbit-counter`) get `animation-play-state: paused`. So the currently-highlighted electron **stops** wherever it is, holding its label steady for reading, while the other three keep orbiting. The cycle advancing every 3.2 s makes the "frozen" electron hand off to the next — the atom reads as "thinking through each principle in turn". Hovering an electron also freezes all orbits (`:has(.electron-core:hover)`).

## Consequences

**Easier:**
- The diagram is no longer decorative — it's a legend. Each electron *is* a named differentiator; the right-hand list and the atom are two views of the same four ideas.
- Hover from either side teaches the pairing.
- The static stray labels are gone.

**Harder / accepted trade-offs:**
- The counter-rotation doubles the number of animated `<g>`s (4 → 8). Still trivial — all CSS transforms on the compositor, no JS per frame.
- Orbit periods had to slow down so labels stay readable; the atom feels calmer than the ADR-014 fast version. Judged a worthwhile trade for legibility.
- Label text is a *short form* of each differentiator, not the full sentence — full text remains in the right-hand list and in each electron's `<title>` tooltip.
- `transform-box: view-box` for the counter-rotation pivot is well-supported (Chrome/Edge/Safari/Firefox current). Older engines fall back to fill-box origin → labels would wobble slightly; acceptable for <0.5 % of traffic.

## Reverting

- Remove `initConvergenceLink()` call → falls back to no auto-cycle (diffs + orbits stay at index 0).
- Restore the `<g class="orbit-N">` markup without the nested `<g class="orbit-counter-N">` and drop the `.electron-label` `<text>` nodes to go back to the ADR-014 label-less atom.

## Reference paths

- `src/components/Metodologia.astro` — atom SVG with counter-rotation wrappers + labels; `.conv-diff` items get `data-diff-idx`.
- `src/scripts/animations.ts` — `initConvergenceLink()` (replaces the `initAutoCycle('.conv-differentiators', …)` call).
- `src/styles/global.css` — `.orbit*`, `.orbit-counter*`, `.electron-label`, `.atom-*` block (center 80,80).
