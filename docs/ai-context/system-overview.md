# System Overview — fenia-web

> Last updated: 2026-05-19

## What it is

`fenia-web` is a single-page marketing site (one-pager) for **FENIA — Formación Estratégica de Neuro-Inteligencia Aumentada**, an Argentine consulting / training brand. The site exists to:

1. Communicate the brand's value proposition (neurociencia + estrategia + IA aplicada).
2. Funnel prospects toward a WhatsApp conversation with the owner.
3. (Future) host and sell digital products (guides, toolkits, methods).

## Live deployment

- Production URL: `https://fenia.com.ar/`
- Hosting: Vercel (auto-deploy from `main` branch).
- DNS: managed externally — likely Hostinger (under investigation, see [PENDING.md #2](../../PENDING.md)).

## Page composition

The home page (`src/pages/index.astro`) is a single route composed of these components, in order:

| # | Component | File | Purpose |
|---|-----------|------|---------|
| 1 | `LogoBanner` | [`src/components/LogoBanner.astro`](../../src/components/LogoBanner.astro) | Top header band with the FENIA video logo. Plays once, freezes on final frame, then sits as a static logo. Replaces the legacy blocking IntroLoader — see [ADR-007](../adr/ADR-007-intro-video-as-logo-banner.md) |
| 2 | `Nav` | [`src/components/Nav.astro`](../../src/components/Nav.astro) | Sticky top nav with anchors + WhatsApp CTA |
| 3 | `Hero` | [`src/components/Hero.astro`](../../src/components/Hero.astro) | Title + value prop + service cards + audience grid |
| 4 | `Metodologia` | [`src/components/Metodologia.astro`](../../src/components/Metodologia.astro) | 4-pillar methodology breakdown |
| 5 | `Servicios` | [`src/components/Servicios.jsx`](../../src/components/Servicios.jsx) | Interactive tab view of the 4 service lines (React island) |
| 6 | `Productos` | [`src/components/Productos.astro`](../../src/components/Productos.astro) | Digital products grid (currently 3 AI-invented placeholders) |
| 7 | `Confianza` | [`src/components/Confianza.astro`](../../src/components/Confianza.astro) | Social proof: KPIs + 3 testimonials (currently AI placeholders) |
| 8 | `Contacto` | [`src/components/Contacto.astro`](../../src/components/Contacto.astro) | Final CTA block with WhatsApp + phone + social |
| 9 | `Footer` | [`src/components/Footer.astro`](../../src/components/Footer.astro) | Logo, link columns, copyright |

## Audience targeting

Three audiences appear in the Hero:

| Audience | Description |
|---|---|
| **Profesionales** | Independent professionals (consultants, psychologists, coaches) wanting to optimize their practice. |
| **Emprendedores** | Solo founders / small teams wanting to scale and integrate AI strategically. |
| **Empresas** | Established companies needing org-level interventions (culture, leadership, talent, innovation). |

## Service lines

Four service lines (shown in `Servicios.jsx` as tabs):

1. **Consultoría estratégica** — Diagnostics, design, implementation of org solutions.
2. **Capacitación y formación** — Leadership, emotional regulation, strategic thinking programs.
3. **IA aplicada** — Mapping AI opportunities, training, gradual adoption.
4. **Productos digitales** — Downloadable resources (guides, toolkits, methods).

## Conversion funnel

```
Visitor lands
   │
   ▼
Hero CTA "Solicitar un turno" ─────┐
   │                               │
   │ scroll                        │
   ▼                               │
Metodología / Servicios / Productos / Confianza
   │                               │
   ▼                               │
Contacto CTA "Solicitar un turno" ─┤
   │                               │
   │ click                         │
   ▼                               ▼
WhatsApp chat ←────────────────────┘
+54 351 355-9947
```

There is **no email form, no payment, no booking system**. Every "Comprar ahora →" button currently links to `#contacto`, not to a checkout — see [PENDING.md #6](../../PENDING.md) for the strategic decision to make.

## Tech stack — one-liner

Astro 6 + React 19 islands + Tailwind v4 + TypeScript strict, deployed static on Vercel. See [ADR-001](../adr/ADR-001-tech-stack.md).

## Content sources

- **Singletons** (WhatsApp, social, hero stats, audiences, methodology, services, navigation): `src/data/site.ts`.
- **Collections** (products, testimonials): `src/content/products/*.md`, `src/content/testimonials/*.md`, validated by `src/content.config.ts`.

See [`content-model.md`](./content-model.md) for the operational guide.

## Known issues being addressed (as of 2026-05-19)

| # | Issue | Owner | Status |
|---|-------|-------|--------|
| 1 | ~~IntroLoader has no skip~~ | dev | **resolved 2026-05-19** — replaced by `LogoBanner` ([ADR-007](../adr/ADR-007-intro-video-as-logo-banner.md)) |
| 2 | All testimonials and products are AI placeholders | owner | unblocking via Tally + curation ([ADR-005](../adr/ADR-005-testimonial-collection.md)) |
| 3 | 3 CSS dialects coexist; Tailwind installed but unused | dev | phased migration ([ADR-004](../adr/ADR-004-styling-strategy.md)) |
| 4 | ~~No SEO meta (OG, schema.org, sitemap, robots)~~ | dev | **partly resolved 2026-05-19** — OG, Twitter, canonical, sitemap.xml and robots.txt landed. Schema.org `Organization` JSON-LD still pending |
| 5 | ~~Footer social/legal links are `href="#"` (dead)~~ | dev | **resolved 2026-05-19** — dead links removed; visible ones come from `site.ts` with conditional rendering |
| 6 | Body text contrast fails WCAG AA in many places | dev | queued |
| 7 | No analytics, no error tracking, no Web Vitals | dev | queued — Fase 3 |
| 8 | No `vercel.json` versioned (Vercel access pending) | owner | blocked on [PENDING.md #1](../../PENDING.md) |
| 9 | "Animated/dynamic visual leap" — scroll-reveal, animated neural net, card hover, scroll spy | dev | proposed (Pack 1/2/3 plan) — awaits owner pick |
