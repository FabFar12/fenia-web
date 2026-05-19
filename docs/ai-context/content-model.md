# Content Model — Where every piece of content lives

> Last updated: 2026-05-19
> Authoritative reference for: WhatsApp number, social handles, hero stats, audiences, methodology pillars, services, products, testimonials, navigation, footer links.

## Why this matters

Before this model was put in place, every WhatsApp link, every product card, every testimonial was **hardcoded inside its component**. Result: the WhatsApp number was duplicated 6 times and changing it required editing 6 files. The "12+ productos" stat in the Hero was a fixed string that drifted out of sync with the actual product count. Testimonials were anonymous AI inventions inlined as HTML.

**This model fixes that.** Content is content, components are presentation.

## Two storage layers

### Layer 1 — Singletons → `src/data/site.ts`

A single typed TypeScript module exporting structured constants. **All components import from here.**

What lives in `site.ts`:

| Export | Shape | Example use |
|---|---|---|
| `siteMeta` | `{ name, tagline, description, locale, prodUrl }` | `<Layout>` head, OG tags |
| `whatsapp` | `{ phone, displayPhone, defaultMessage, url(message?) }` | All CTA buttons that route to WhatsApp |
| `socials` | `{ instagram, linkedin, youtube }` (each: `{ handle, url }`) | Footer social column, Contacto info |
| `navLinks` | `Array<{ label, href, kind: 'anchor' \| 'cta' }>` | `Nav.astro` |
| `heroStats` | `Array<{ label, value }>` | Hero stats row |
| `audiences` | `Array<{ key, title, summary, accent: 'cyan' \| 'coral' }>` | Hero audience grid + Footer "Soluciones" column |
| `methodologyPillars` | `Array<{ num, title, body, highlight?: boolean }>` | `Metodologia.astro` |
| `services` | `Array<{ slug, icon, title, subtitle, body, bullets[], audiences[] }>` | `Servicios.jsx` |
| `legal` | `{ privacyUrl, termsUrl, copyright }` | Footer legal row |

### Layer 2 — Collections → Astro Content Collections

For variable-length lists where each item is curated independently.

**Products** → `src/content/products/<slug>.md`
**Testimonials** → `src/content/testimonials/<slug>.md`

Schema and validation: [`src/content.config.ts`](../../src/content.config.ts).

## How to do common edits

### "Cambiar el número de WhatsApp"

1. Open [`src/data/site.ts`](../../src/data/site.ts).
2. Update the `whatsapp.phone` and `whatsapp.displayPhone` fields.
3. Done — all 6+ places that link to WhatsApp update automatically.

### "Cambiar una red social o agregar TikTok"

1. Open `src/data/site.ts` → `socials` object.
2. Update the URL or add a new entry.
3. If adding a new platform, also add a corresponding icon block in `Footer.astro` (small visual change — propose via mockup first per [AGENTS.md](../../AGENTS.md)).

### "Agregar un producto nuevo"

1. Create `src/content/products/<slug>.md`. The slug becomes the URL fragment if/when product detail pages exist.
2. Fill the frontmatter according to the schema (Zod will reject malformed entries at build):

```yaml
---
title: "Curso de Neuroliderazgo — Edición 2026"
type: "Curso"                   # 'Guía' | 'Toolkit' | 'Método' | 'Curso' | 'Workshop' | 'Otro'
status: "coming-soon"           # 'draft' | 'coming-soon' | 'live' | 'archived'
accent: "cyan"                  # 'cyan' | 'coral'
summary: "Programa intensivo de 8 semanas para directivos."
price: null                     # number (ARS) or null for 'consultar'
cta:
  label: "Avisame cuando esté listo"
  href: "https://wa.me/5493513559947?text=Me%20interesa%20el%20Curso%20de%20Neuroliderazgo"
audiences: ["empresas", "profesionales"]
publishedAt: 2026-06-01
---

Breve descripción adicional en Markdown (opcional). Este cuerpo se puede mostrar en una página de detalle futura o ignorar si solo se usa la card en la home.
```

3. Save. Run `npm run dev` — the new card appears (if its `status` is not `draft`).

**Status reference:**

| Status | Where it shows | CTA behavior |
|---|---|---|
| `draft` | Nowhere (hidden, even from preview deploys) | n/a |
| `coming-soon` | Shows in the grid with a "Próximamente" badge | CTA "Avisame cuando esté listo" → WhatsApp |
| `live` | Shows normally | CTA `cta.label` → `cta.href` (WhatsApp or checkout URL when checkout exists) |
| `archived` | Hidden from public, kept for history | n/a |

### "Agregar un testimonio aprobado"

1. The owner has reviewed the Tally submission ([ADR-005](../adr/ADR-005-testimonial-collection.md)).
2. The owner has obtained explicit written consent to publish.
3. Create `src/content/testimonials/<slug>.md`:

```yaml
---
name: "María Pérez"
role: "Directora de Operaciones"
org: "Acme Tech"
location: "Córdoba"             # optional
linkedIn: "https://www.linkedin.com/in/mariaperez"  # optional
photo: "/testimonials/maria-perez.jpg"  # optional, put the file in public/testimonials/
rating: 5                       # 1–5
consentGivenAt: 2026-05-20
publishedAt: 2026-05-20
status: "approved"              # 'draft' | 'approved' | 'archived'
---

El programa transformó cómo tomamos decisiones a nivel directivo. Concreto, aplicable, y medible.
```

4. Save. The site shows it automatically in the Confianza section (max N visible, oldest archived first if you exceed the layout's capacity).

### "Cambiar las estadísticas del Hero (12+ productos, 6 áreas, 100% enfoque)"

1. Open `src/data/site.ts` → `heroStats`.
2. Edit values.
3. **Note:** ideally these numbers should be **derived** instead of hand-edited:
   - `"12+ productos digitales"` → derive from `getCollection('products').filter(p => p.status === 'live').length`.
   - This derivation is queued in Plan Maestro Fase 1.8.

### "Cambiar el copy de un servicio"

1. Open `src/data/site.ts` → `services` array.
2. Find the service by `slug` and edit `body`, `bullets`, or `audiences`.

### "Cambiar el copy de un pilar de la metodología"

1. Open `src/data/site.ts` → `methodologyPillars`.
2. Edit. The visual highlight (the cyan accent on pilar 02) is controlled by the `highlight: true` flag.

## What does NOT live in `site.ts`

Static UI content like:

- The H1 of the Hero ("Estrategia e inteligencia aumentada") — tightly coupled to the visual layout.
- Section section titles like "Una metodología que integra ciencia, estrategia y tecnología" — these are headline copy, but currently inside the components. **Future:** extract to `site.ts` if the owner wants to A/B test or translate them.
- Decorative SVGs (neural network in Hero, dot grids).
- Microcopy on buttons that is structurally bound (e.g., the "Ver soluciones →" inside an audience card — the arrow is presentation, not content).

## Anti-patterns to avoid

1. ❌ **Hardcoding the WhatsApp number in a new component.** Always `import { whatsapp } from '~/data/site';`.
2. ❌ **Adding a 4th hero stat by editing JSX.** Add it to `heroStats` array.
3. ❌ **Pasting a testimonial directly into `Confianza.astro`.** It must go through the Tally → consent → markdown flow.
4. ❌ **Inventing a product to "fill the grid".** If there are 2 products, show 2. The visual layout must be designed to accommodate variable counts (1, 2, 3, 6).
5. ❌ **Storing a phone number, email or DNI of a real customer in the repo.** That violates Argentine data-protection law (Ley 25.326). PII lives in private CRM/Notion/wherever — never git.
