# ADR-007 — Intro video repurposed as top logo banner (replaces blocking IntroLoader)

## Status
Accepted (2026-05-19). Supersedes the prior implementation of `src/components/IntroLoader.jsx`.

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

**Recycle the video as a permanent header-banner element (`src/components/LogoBanner.astro`) above the main `Nav`.**

Behavior:

- Banner is `~80 px` tall on desktop, `~70 px` on mobile.
- Centered video element (`max-height: 56 px` desktop / `42 px` mobile, `max-width: 240 px` / `180 px`).
- Plays once on first paint: `autoplay`, `muted`, `playsinline`, **no `loop`**.
- When the video ends, the browser holds the last frame on screen — the static "FENIA logo" final frame becomes the visible logo.
- Subtle radial cyan halo behind + two thin "horizon" lines fading out left/right for sophistication ([Linear/Stripe-style visual idiom](https://linear.app)).
- Banner is **not sticky** — it scrolls away naturally. The existing `Nav` remains the sticky chrome.
- `prefers-reduced-motion: reduce` is honored: video is hidden and a typographic "FENIA" wordmark appears as fallback.
- Banner appears at the top of every page (currently `/` and `/dejanos-tu-testimonio`).

The old `src/components/IntroLoader.jsx` is **deleted**. The React island it occupied is freed (one less hydration on first load).

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

- Component: [`src/components/LogoBanner.astro`](../../src/components/LogoBanner.astro)
- Asset: [`public/images/intro-fenia.mp4`](../../public/images/intro-fenia.mp4)
- Mounted by: [`src/pages/index.astro`](../../src/pages/index.astro), [`src/pages/dejanos-tu-testimonio.astro`](../../src/pages/dejanos-tu-testimonio.astro)
