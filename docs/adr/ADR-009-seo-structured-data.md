# ADR-009 — SEO: Schema.org JSON-LD + canonical/OG meta + sitemap + robots + 404

## Status
Accepted (2026-05-19). Phased: this commit adds structured-data + branded 404. The remaining gap (Open Graph image, Plausible/Sentry wiring, Google Search Console verification) is owner-blocked — see [PENDING.md](../../PENDING.md).

## Context

Pre-modernization SEO baseline (2026-05-19):
- Only a basic `<meta name="description">` tag.
- No canonical URL.
- No Open Graph or Twitter card meta.
- No structured data.
- No sitemap or robots.txt.
- No 404 page (Astro served its default).
- No favicon beyond the Astro starter icon.

For a consulting brand whose discovery channel is largely organic (referrals + LinkedIn + Google), this is a significant missed opportunity. Specifically:
- WhatsApp / LinkedIn link previews render as blank rectangles.
- Google has no structured data to build a Knowledge Panel.
- Lost-URL visitors hit a generic 404 with no path back.

## Decision

Three-layer SEO baseline, all implemented in `Layout.astro` + Astro integrations:

### Layer 1 — Page-level meta (already landed Fase 1)
- `<meta name="description">` from `siteMeta.description` (overridable per page).
- `<link rel="canonical">` derived from `Astro.url.pathname` + `siteMeta.prodUrl`.
- Open Graph: `og:type`, `og:title`, `og:description`, `og:url`, `og:locale`, `og:site_name`.
- Twitter Cards: `twitter:card`, `twitter:title`, `twitter:description`.
- `<meta name="theme-color">` for mobile browser chrome.
- `<link rel="icon">` for favicon (placeholder — see [PENDING.md #9](../../PENDING.md)).

### Layer 2 — Site-level discovery (already landed Fase 2)
- `@astrojs/sitemap` integration emits `/sitemap-index.xml` and `/sitemap-0.xml`.
- `public/robots.txt` allows all crawlers and points to the sitemap.
- `astro.config.mjs` declares `site: 'https://fenia.com.ar'` as the canonical base.

### Layer 3 — Structured data (this commit)
- `<script type="application/ld+json">` in `Layout.astro` `<head>` with a `ProfessionalService` Schema.org type:
  - `@id`, `name`, `alternateName`, `description`, `url`, `telephone`, `inLanguage`.
  - `areaServed: Country Argentina`.
  - `knowsAbout`: 6 disciplines covered by FENIA (neurociencias, pensamiento estratégico, IA, gestión emocional, liderazgo, bienestar organizacional).
  - `sameAs`: only includes social URLs that are real (filters out `null` placeholders from `socials`).
- Rendered with Astro's `is:inline` + `set:html={JSON.stringify(...)}` so the JSON isn't escaped or bundled.

### Layer 4 — Branded 404
- `src/pages/404.astro`: respects the same layout as the home (LogoBanner + Nav + Footer + Layout meta).
- Headline: "Esta página **no existe**" with the `.gradient-anim` treatment for visual continuity.
- Two CTAs: "← Volver al inicio" + WhatsApp with pre-filled message indicating a broken link.
- Sets its own `description` so OG previews of bad URLs don't leak the home description.

## Consequences

**Easier:**
- Google now has enough structured data to render a Knowledge Panel for "FENIA" (subject to crawl + acceptance).
- LinkedIn / WhatsApp / Twitter previews render with title + description + locale. (Image still pending — see PENDING.md #10).
- Lost-URL visitors land somewhere branded that funnels back to home or WhatsApp.
- `sitemap-index.xml` is auto-regenerated on every build — no manual maintenance.
- Robots-tester ready: `https://fenia.com.ar/robots.txt` exposes the sitemap location.

**Harder / accepted trade-offs:**
- Owner needs to add Open Graph image (1200×630 px) at `public/og-image.jpg` and wire it via `siteMeta.ogImage` for richer previews. Not blocking, but worth completing.
- Structured-data values are duplicated between Schema.org JSON-LD and OG meta. We accept that duplication as Schema.org is canonical for Google, OG for social.
- The `knowsAbout` list is hand-curated — if FENIA expands services materially, this list must be updated.

## Verification

- After deploy, validate with:
  - https://validator.schema.org/ for the JSON-LD
  - https://developers.facebook.com/tools/debug/ for OG previews
  - https://search.google.com/test/rich-results for Google's interpretation
  - https://fenia.com.ar/sitemap-index.xml accessible
  - https://fenia.com.ar/robots.txt accessible
  - https://fenia.com.ar/non-existent-url → branded 404

## Reference paths

- [`src/layouts/Layout.astro`](../../src/layouts/Layout.astro) — meta + JSON-LD
- [`src/pages/404.astro`](../../src/pages/404.astro) — branded 404
- [`astro.config.mjs`](../../astro.config.mjs) — sitemap integration + site URL
- [`public/robots.txt`](../../public/robots.txt)
