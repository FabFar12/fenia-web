# ADR-007 — Intro video as page hero element (supersedes IntroLoader; supersedes own top-banner v1)

## Status
Accepted (2026-05-19), **amended same-day** to move the video from a slim top banner to a centered hero feature in the body of the home page. The thin top-band version (`LogoBanner.astro`) was deleted; the current implementation lives in `src/components/HeroVideo.astro`. The amendment is reflected in the *Decision* section below.

## Context

The pre-existing site mounted an `IntroLoader` React island as the **first thing the visitor sees**:

- Full-screen modal (z-index 9999) on top of the entire page.
- Plays `/images/intro-fenia.mp4` once, then fades out.
- Has a hard 7-second timeout even if the video errors out.
- **No skip button.**
- No persistence: every page reload re-plays the intro.

Problems with that pattern:

1. **Bounce-rate risk on mobile / slow connections**: visitor lands and stares at a video while real content is invisible underneath. Worst Lighthouse signals (LCP, TBT).
2. **Accessibility**: keyboard users and screen-reader users have no way to skip; reduced-motion preference is ignored.
3. **The asset itself is brand-good**: the intro video is a custom-animated FENIA logo. Throwing it away would be a loss.

## Decision

### v1 (deprecated, 2026-05-19)

The video was first placed as a slim top "logo banner" (`src/components/LogoBanner.astro`) above the `Nav`. Banner height ~80 px desktop / ~70 px mobile, video ~56 px / ~42 px. While this technically replaced the blocking intro, the video read as small and decorative — it didn't feel like a brand showcase.

### v2 (current, 2026-05-19)

**Reposition the video as a centered hero feature inside the body of the home page**, in a dedicated section between the `Nav` and the existing `Hero` (`src/components/HeroVideo.astro`). The slim top banner was deleted.

Behavior:

- Section ~72/88 px vertical padding desktop, ~44/60 px mobile.
- Centered video (`height: 220 px` desktop / `180 px` tablet / `140 px` mobile, `max-width` adaptive).
- Plays once on first paint: `autoplay`, `muted`, `playsinline`, **no `loop`**. Browser holds the last frame as a static "FENIA logo" once playback ends.
- Ambient radial cyan halo behind + matching dot-grid texture + two thin "horizon" rules flanking the video (idiom shared with the legacy banner, scaled up).
- Section is **not sticky** — it scrolls away naturally. The `Nav` remains the sticky chrome.
- `prefers-reduced-motion: reduce` is honored: video is hidden and a typographic "FENIA" wordmark replaces it at 64 px.
- **Only mounted on the home page (`/`)** — `404.astro` and `dejanos-tu-testimonio.astro` are secondary pages, the `Nav` brand wordmark is enough identity there.

The old `src/components/IntroLoader.jsx` was deleted in v1. The slim `src/components/LogoBanner.astro` is deleted in v2. The React island freed in v1 stays freed in v2.

## Consequences

**Easier:**
- Time-to-content drops from ~1 s of full-screen intro to ~0 ms — content is below the fold from the very first paint.
- The brand asset (animated logo) is preserved and **always visible** instead of seen-once-and-dismissed.
- Lighthouse LCP improves; TBT improves (one less React island to hydrate).
- A11y improves: no blocking modal, reduced-motion honored, no focus trap.

**Harder / accepted trade-offs:**
- The animated logo loses its "ceremonial" feel — it's no longer the dramatic first moment, it's an ambient brand element. We mitigate this by giving it a soft cyan halo + horizon rules so it doesn't look like a leftover.
- The video plays on every full page load (cached after first request). If we ever want "play once per session" behavior, we add a small inline `<script>` that toggles a CSS class based on `sessionStorage` — out of scope for v1.
- The `.mp4` is still ~MBs (TBD — needs measurement). A future PR may convert it to a smaller webm/AV1 or to a Lottie/SVG animation. Tracked in [PENDING.md](../../PENDING.md).

## Reference paths

- Component (v2): [`src/components/HeroVideo.astro`](../../src/components/HeroVideo.astro)
- Asset: [`public/images/intro-fenia.mp4`](../../public/images/intro-fenia.mp4)
- Mounted by: [`src/pages/index.astro`](../../src/pages/index.astro) (only — secondary pages just show `Nav`).
- Deleted in v2: `src/components/LogoBanner.astro` (was the v1 slim top banner).
