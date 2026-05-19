# ADR-003 — Content Layer: `src/data/site.ts` + Astro Content Collections

## Status
Accepted (2026-05-19)

## Context

The pre-existing codebase had **all business content hardcoded inside components**:

- WhatsApp number (`+54 351 355-9947`) duplicated **6 times** across `Nav.astro`, `Hero.astro`, `Productos.astro`, `Servicios.jsx`, `Contacto.astro`, `Footer.astro`.
- 3 product cards (Guía, Toolkit, Método) literally inlined as HTML inside `Productos.astro`.
- 3 testimonials with anonymous attributions inlined in `Confianza.astro`.
- Hero stats ("6 áreas", "12+ productos", "100% enfoque") inlined in `Hero.astro`.
- Social handles, footer text, navigation labels: all inline.

Consequences observed:
- Changing the WhatsApp number requires editing 6 files. **Divergence is guaranteed**.
- A non-technical owner cannot add a product without touching JSX.
- "12+ productos" lies because only 3 are shown — no single source of truth means inconsistencies stay invisible.
- All testimonials and products were AI-invented (see [ADR-005](./ADR-005-testimonial-collection.md) and [PENDING.md #3](../../PENDING.md)); replacing them safely requires removing them from N components.

## Decision

Separate content into **two layers** based on shape and update frequency:

### Layer 1 — Singletons & constants → `src/data/site.ts`

A single typed TypeScript module exporting structured constants used across the site. Examples:

- `whatsapp` (URL + display number + default message)
- `socials` (Instagram, LinkedIn, YouTube handles + URLs)
- `audiences` (the 3-card grid: profesionales / emprendedores / empresas)
- `methodology` (the 4 pillars)
- `heroStats` (the 3 stat cards in the Hero)
- `navLinks`
- `legal` (privacy URL, terms URL when they exist)

All components import from `~/data/site.ts`. Mutations are a single-file edit.

### Layer 2 — Collections of items → Astro Content Collections (`src/content.config.ts` + `src/content/`)

For things that come in a variable list whose count and order matters: **products** and **testimonials**.

- `src/content/products/<slug>.md` — Markdown with frontmatter validated by a Zod schema. `status: 'coming-soon' | 'live' | 'archived'` controls visibility and badge.
- `src/content/testimonials/<slug>.md` — Markdown with frontmatter (name, role, org, linkedIn?, rating, photo?, publishedAt). Only `status: 'approved'` testimonials are rendered.

Both collections are typed end-to-end via Astro's `getCollection()` API. Adding a product = creating one `.md` file. No JSX edit required.

### What stays inside components

- Layout, structure, spacing, animation, decorative SVGs. These are presentation, not content.
- Section headlines and short body paragraphs that are inseparable from the surrounding visual structure — **for now**. Future ADRs may extract these into `site.ts` too if the owner needs to edit them.

## Consequences

**Easier:**
- "Change the WhatsApp number" = 1-line edit in `site.ts`.
- "Add a new product" = create `src/content/products/curso-x.md`. No JSX touched.
- "Hide an existing product" = flip `status` in its frontmatter.
- The "12+ productos" hero stat is auto-derived from `getCollection('products').filter(p => p.status === 'live').length` — coherence by construction.
- TypeScript catches typos in product types or missing required fields at build time.

**Harder / accepted trade-offs:**
- A bit more ceremony than inline content (schema definition, frontmatter syntax).
- The migration is incremental: components must be refactored one at a time to read from the new layer. This is queued as part of Fase 1–2 of the modernization roadmap.
- Markdown bodies (the main paragraph of a product) are MD-rendered, so any HTML/styling must go through MD-friendly syntax or component slots.

## Reference

- [Astro Content Collections docs](https://docs.astro.build/en/guides/content-collections/) (v6 uses the Content Layer API).
- [`docs/ai-context/content-model.md`](../ai-context/content-model.md) — operational guide for editing content.
