# ADR-014 — Convergence diagram as an atom (supersedes ADR-013 §1)

## Status
Accepted (2026-05-19). Same-day amendment that **supersedes the drift-and-pulse approach from ADR-013 §1** with an atom-style orbital model.

## Context

ADR-013 §1 made the convergence diagram "alive" by giving each peripheral node a small wandering translate (±2–3 px) inside its own `<g class="conv-drift">`. The lines connecting nodes to the nucleus stayed in their hardcoded positions and breathed opacity.

After visual review the project lead rejected it: *"este logo está paupérrimo... el efecto de movimiento está muy mal logrado. las líneas están también estáticas... buscá algo más como que se mueva como si fuese un átomo. busca ese tipo de comportamiento visual."*

Two problems with the v1:
- The drift on the nodes was too subtle to read as motion — it looked like jitter.
- The static lines pointing at fixed positions while the endpoints (the nodes) moved created a perceptual mismatch — "broken" rather than "alive".

## Decision

Throw out the drift-and-static-lines model. Rebuild the diagram as an **atom**:

- A pulsing nucleus at center.
- Four electrons, each on its own circular orbit, sweeping around the nucleus continuously.
- Two faint dashed orbital guide rings.
- No more static connection lines (they served no purpose once the metaphor became orbital).
- The original conceptual labels (`Neuro`, `IA`, `Estrategia`) stay pinned at the diagram's corners as identity markers — the electrons don't *visit* them, they represent the same disciplines abstractly.

### How the orbits work technically

Each electron lives inside `<g class="orbit-N">`. The `<g>` carries:
```css
transform-box: view-box;
transform-origin: 70px 70px;
animation: atom-spin <period> linear infinite <delay> [reverse];
```

`transform-box: view-box` makes the transform-origin coordinates resolve in user units of the SVG `viewBox` (here `0 0 140 140`). So `transform-origin: 70px 70px` is the geometric center of the SVG.

Each electron's `<circle cx="…" cy="70">` is offset along the X-axis from center; the rotation sweeps it around. Different radii produce nested orbits.

| Orbit | Radius | Period | Direction | Starting offset |
|-------|--------|--------|-----------|-----------------|
| 1 (Neuro)   | 22 | 6.5 s  | normal  | 0 (3 o'clock)  |
| 2 (IA)      | 36 | 9.5 s  | reverse | -2.6 s (~98°) |
| 3 (Estrategia) | 50 | 11.5 s | normal  | -6 s (~188°)  |
| 4 (G. emocional, coral) | 58 | 8 s   | reverse | -2 s (~90°)   |

Mixed normal/reverse direction + non-rational period ratios = no two electrons ever align with the same beat. The atom never feels mechanical.

### Hover pause

`.conv-diagram:has(.electron-core:hover) .orbit { animation-play-state: paused; }` — reaching for an electron halts every orbit so the user has a steady target for the (existing) hover-scale + drop-shadow boost. `:has()` is supported across Chrome 105+, Firefox 121+, Safari 15.4+.

### Nucleus pulse

The nucleus is two stacked circles (halo + core), both with `transform-box: view-box; transform-origin: 70px 70px`. Halo pulses scale 1 → 1.5 over 3.2 s with opacity 1 → 0.35. Core pulses scale 1 → 1.22 over 2.6 s with opacity 0.95 → 0.7. The two slightly different periods give the nucleus a breathing rhythm.

The core color is bumped to `#1FE3FF` and gets a `drop-shadow(0 0 8px rgba(0,200,255,0.7))` filter so it reads as a hot center even without WebGL Bloom.

### Reduced motion

`@media (prefers-reduced-motion: reduce)` switches off every `.orbit`, `.atom-ring`, `.atom-nucleus-*` animation. The diagram becomes a static atom snapshot with the electrons frozen at their starting angular positions and the nucleus at scale 1 — still readable as the same concept.

## Consequences

**Easier:**
- The diagram is now visibly the brand metaphor: 4 disciplines (electrons) orbiting around the FENIA methodology (nucleus). Matches what the surrounding copy already says ("modelo integrador donde neurociencia, estrategia e IA convergen").
- The motion is unmissable — no more "is it animated or not?".
- No static lines / moving endpoint mismatch.
- All-CSS implementation. Zero JS for this section.

**Harder / accepted trade-offs:**
- The previous Pack 2 `.conv-node` hover-dim-others-on-hover behavior is gone. The new hover behavior is "pause all orbits, scale the hovered electron, glow it". Different but defensible — and visually clearer with constant motion in the baseline.
- ADR-013 §1 is documented as superseded but kept in the audit history (the doc still has valid §2 + §3).
- The `transform-box: view-box` property is well-supported in modern browsers but not in very old ones. Fallback: animation runs from the element's own bbox origin, which means the orbit visually shifts off-center. Acceptable degradation — those browsers are <0.5% of traffic per caniuse.

## Reverting

To get back to ADR-013 §1's drift behavior: restore the `<line>` + `<g class="conv-drift">` markup in `Metodologia.astro` and the `.conv-drift*` / `.conv-line*` rules in `global.css` (see commit `3a3d77a` for the full prior content). The two are mutually exclusive — only one set of orbit / drift classes should be present at a time.

## Reference paths

- `src/components/Metodologia.astro` — the new atom-style SVG.
- `src/styles/global.css` — `.atom-ring`, `.orbit`, `.electron-halo`, `.electron-core`, `.atom-nucleus-halo`, `.atom-nucleus-core` block (replaces the old `.conv-drift*` + `.conv-line*` block).
