# ADR-026 — Static logo PNG on a light plate replaces the intro video

## Status
Accepted (2026-05-26). Supersedes [ADR-007](./ADR-007-intro-video-as-logo-banner.md). Owner-initiated: Fab provided a new `main-logo.png` and asked that it replace the animated intro at the top of the home page.

## Context

[ADR-007](./ADR-007-intro-video-as-logo-banner.md) settled on a centered hero video (`public/images/intro-fenia.mp4`, ~2.4 MB) that played once on load and froze on its final frame. Two events on 2026-05-26 forced a rethink:

1. **Owner provided a static brand asset** (`main-logo.png`, ~1.18 MB) and requested it as the new hero centerpiece. The PNG is a clean wordmark — circuit-styled "F" in cyan, "ENIA" in dark navy, a coral dot accent — designed for **light backgrounds**.
2. **Contrast problem**: the FENIA page uses `#0B1A2E` as the hero background ([ADR-001](./ADR-001-tech-stack.md), styling baseline). The "ENIA" portion of the wordmark is roughly the same dark navy → it disappears against the page on dark mode.

The cyan "F" and coral dot remain legible because they contrast against `#0B1A2E`. The "ENIA" letters do not. Three remedies were on the table:

- **A. Light plate behind the logo** — wrap the `<img>` in a white-to-cream gradient container with rounded corners and an outer cyan glow. The logo renders on its designed background. Contrast guaranteed.
- **B. CSS filters / drop-shadow only** — keep the dark page, apply layered `filter: drop-shadow()` to outline the logo silhouette. Looks interesting but leaves "ENIA" itself low-contrast internally (only its outer edges get the halo).
- **C. Spotlight radial gradient on the section** — background of the section becomes a soft radial light blob under the logo, fading to dark elsewhere. Subtle but harder to tune without looking like a smudge.

## Decision

**Adopt Option A — light plate with cyan halo.**

Concretely:

1. `src/components/HeroVideo.astro` keeps its name and structure (section + glow + horizon rules + frame). The `<video>` element is replaced by an `<img>` pointing to `/images/main-logo.png`.
2. The `.hero-video-frame` becomes the **plate**:
   - Background: `linear-gradient(180deg, #ffffff 0%, #f4faff 100%)` — subtle cool tint, avoids dead-flat white.
   - `border-radius: 22px` desktop / `18px` tablet / `16px` mobile.
   - `padding: 28px 48px` desktop, scaled down on smaller breakpoints.
   - Layered `box-shadow`:
     - `0 0 0 1px rgba(0, 180, 216, 0.22)` — 1px cyan ring ties the plate to the brand.
     - `0 24px 64px -16px rgba(0, 180, 216, 0.45)` — large outward cyan glow ("object showcase" feel).
     - `0 10px 28px -8px rgba(0, 0, 0, 0.45)` — depth shadow grounding it on the dark page.
   - A subtle `::after` radial overlay inside the plate adds inner texture so it doesn't read as a printer sticker.
3. The existing ambient cyan halo (`hero-video-glow`) and dot-grid texture are kept; the halo radius was widened to `inset: -20% 5%` so the plate floats inside it instead of being framed by it.
4. `prefers-reduced-motion` simply skips the entrance animation (no video to swap out anymore).
5. `public/images/intro-fenia.mp4` is **kept on disk but no longer referenced** by any code. Removal is a separate cleanup PR (so this commit stays focused).

### What was NOT changed

- Aria-label `"FENIA — logo"` on the section is unchanged.
- The horizon-rule lines flanking the centerpiece are unchanged.
- The page-level animation orchestration (Pack v2 magnetic CTAs, scroll progress, etc.) is unaffected.
- The home page composition (Nav → HeroVideo → Hero → …) is unchanged.

## Consequences

**Easier:**

- The logo renders **as designed** — full contrast, no compromise on the brand identity.
- **Lighter** runtime: a single `<img>` decoded once vs. a 2.4 MB MP4 that autoplayed on every load. Even though `main-logo.png` is 1.18 MB (unoptimised), the *network* and *decode* costs are simpler — no video pipeline.
- **Accessibility is cleaner**: there is no longer animated content to opt out of; the `prefers-reduced-motion` block reduces to "skip the entry fade".
- **Brand consistency** if the same `main-logo.png` is used elsewhere (favicons, social cards, future email signatures).

**Harder / accepted trade-offs:**

- The "ceremonial first moment" of the animated logo is gone. Mitigation: the plate's cyan halo + horizon rules retain a premium look; the page now feels like a brochure cover rather than an unfolding intro.
- A **light block** now occupies the first fold. The page is no longer 100% dark above the hero copy. This is a brand decision: if Fab ever produces a *light* version of the wordmark (white "ENIA" on dark), we migrate to Option C (background remains dark, logo light, plate removed) without code drama.
- `main-logo.png` is **1.18 MB unoptimised**. A future PR should run it through optimisation (target ~200 KB WebP + PNG fallback). Tracked in [PENDING.md](../../PENDING.md).
- The dead `intro-fenia.mp4` (2.4 MB) lingers in `public/images/` until a cleanup PR removes it. Not urgent because the page no longer downloads it.

**Migration path away from the plate (when a dark-friendly logo exists):**

1. Replace `main-logo.png` with a transparent-bg version where text/wordmark is light (white or cyan).
2. Strip the plate styles from `.hero-video-frame` (remove background, padding, shadows).
3. Keep the ambient halo + horizon rules; the logo now sits directly on dark.
4. Total work: ~15 minutes. Reversible.

## Alternatives considered

| Option | Why not |
|---|---|
| Keep the intro video (status quo) | Owner explicitly replaced it with the PNG; reverting would override the owner's call without cause |
| CSS `mix-blend-mode: screen` on the `<img>` | Math doesn't help here — the dark wordmark and the dark page have similar luminance, so screen-blend produces a barely-lighter result. Looks broken |
| `filter: invert(1)` on the `<img>` | Inverts the cyan "F" to red and the coral dot to blue — destroys the brand colours |
| Custom SVG of the wordmark with theme-aware fills | Requires the designer to provide an SVG; PNG is what we have today. Track as a future improvement if Fab can extract the SVG |
| Drop the wordmark, use the "F" icon only | The full "FENIA" wordmark is what Fab provided; trimming it loses the brand decision behind the asset |

## Follow-ups

- **Optimise `main-logo.png`**: target ~200 KB WebP with PNG fallback. Lighthouse will yell if we don't. Tracked in [PENDING.md](../../PENDING.md).
- **Delete `intro-fenia.mp4`** in a follow-up commit once the new hero is verified visually in production. ~2.4 MB freed from the repo.
- **Light-variant logo (`main-logo-light.png`)**: if Fab can produce one, the plate disappears (see "Migration path" above).
- **Favicon / OG image alignment**: `main-logo.png` should inform the favicon set ([PENDING.md](../../PENDING.md) #13) and the OG image ([PENDING.md](../../PENDING.md) #14). One asset, one identity.
