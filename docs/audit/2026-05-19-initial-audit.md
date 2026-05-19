# Initial Audit — 2026-05-19

> Point-in-time snapshot of `fenia-web` at the start of the modernization effort.
> This audit is **frozen** — do not edit it. Create new audits for future states.

## Scope

Pre-modernization state of `fenia-web` (commit `7971754` on `main`), serving as the baseline against which future improvements are measured.

## Method

- Read every component in `src/` directly.
- Inspected `https://fenia.com.ar/` HTTP headers + DOM to confirm live state matches repo.
- Cross-referenced architecture against the user's reference project `CoffeeAppMain`.

## Summary

**Overall score: 4.5 / 10** weighted (informática 3.5 + UX/UI 5.5 + contenido 6.5, weighted by impact on credibility for a consulting brand).

**Top 5 risks (descending severity):**

1. 🔴 **All product cards & testimonials are AI-invented placeholder content.** Severe credibility risk for a brand whose differential is "evidencia". Active on production.
2. 🔴 **Responsive CSS uses brittle descendant positional selectors + `!important`.** Single DOM reorder breaks mobile silently.
3. 🔴 **No infrastructure config versioned.** Vercel deploy is a black box; rebuild from scratch would require reverse-engineering UI settings.
4. 🔴 **CTAs "Comprar ahora →" route to `#contacto`, not checkout.** Legal/reputational risk.
5. 🔴 **IntroLoader blocks for 7 s with no skip option.** High mobile bounce-rate driver.

## Detailed scoring

### Informática — 3.5 / 10

| Eje | Nota | Notas |
|---|---|---|
| Arquitectura | 4 | One-pager Astro razonable, sin separación datos/UI |
| Escalabilidad | 3 | Añadir página o blog implica reescribir 80% |
| Estructura | 4 | Falta `lib/`, `data/`, `content/`, `types/` |
| Seguridad | 5 | Estático sin backend; falta CSP, algunos `target="_blank"` sin `rel` |
| Performance | 4 | 5 pesos de Plus Jakarta (~250 KB), SVG inline gigante, IntroLoader bloquea TTI |
| Mantenibilidad | 2 | Estilos inline en cada `<div>`, sin tema central |
| Calidad de código | 3 | Sin ESLint, sin Prettier, líneas de 400 chars |
| Consistencia | 2 | Tres dialectos CSS coexisten |
| Testing | 0 | Ningún test |
| Despliegue | 1 | Sin config versionada |
| Observabilidad | 0 | Cero |
| Dependencias | 7 | Stack moderno y sano |

### UX/UI — 5.5 / 10

| Eje | Nota |
|---|---|
| Claridad visual | 7 |
| Navegación | 6 |
| Jerarquía | 6 |
| Accesibilidad | 3 (contraste falla WCAG AA, sin aria-labels, sin focus styles) |
| Responsive | 5 (existe pero implementado frágilmente) |
| Mobile UX | 5 (IntroLoader 7s sin skip es demoledor) |
| Consistencia | 4 |
| Feedback visual | 4 |
| Usabilidad real | 6 |
| Fricción | 5 |

### Contenido y producto — 6.5 / 10

| Eje | Nota |
|---|---|
| Claridad del propósito | 8 (tagline excelente: "Pensar mejor. Decidir mejor. Actuar con Inteligencia Aumentada") |
| Comunicación | 7 |
| Textos | 6 |
| Branding | 8 (identidad visual coherente) |
| Propuesta de valor | 7 |
| Flujo de usuario | 6 (single funnel WhatsApp) |
| Comprensión del producto | 5 (Hero dice "12+ productos", se muestran 3 → contradicción) |
| Coherencia general | 5 |

## Detected concrete issues

| # | Issue | File | Severity |
|---|-------|------|----------|
| 1 | All testimonials anonymous & AI-invented | `Confianza.astro:18-33` | Critical |
| 2 | All product cards AI-invented | `Productos.astro:28-61` | Critical |
| 3 | Hero "12+ productos" stat false | `Hero.astro:78-79` | High |
| 4 | "Comprar ahora →" routes to `#contacto`, no checkout | `Productos.astro:35, 47, 59` | High |
| 5 | IntroLoader 7s with no skip | `IntroLoader.jsx:24-27` | High |
| 6 | Body text contrast `rgba(255,255,255,0.3–0.45)` fails WCAG AA on `#0B1A2E` | multiple | High |
| 7 | Footer social links `href="#"` (dead) | `Footer.astro:32-34` | Medium |
| 8 | Footer legal links `href="#"` (dead) | `Footer.astro:41-42` | Medium |
| 9 | Descendant positional selectors + `!important` in responsive | `global.css:17-119` | High |
| 10 | WhatsApp number hardcoded ~6 times | multiple | High |
| 11 | Tailwind installed but not used; 3 CSS dialects coexist | multiple | High |
| 12 | README is the Astro `minimal` placeholder | `README.md` | Medium |
| 13 | No `.env.example`, no ADRs, no AI context | repo root | Medium |
| 14 | No SEO meta (OG, schema.org, sitemap, robots, canonical, favicon propio) | `Layout.astro` | High |
| 15 | No analytics, no error tracking, no Web Vitals | n/a | Medium |
| 16 | `public/images/logo-fenia.png.png` (double extension, unused) | `public/images/` | Low |
| 17 | Plus Jakarta loads 5 weights (~250 KB) | `global.css:2-6` | Medium |
| 18 | Hero column right hidden on mobile (info lost) | `global.css:28` | Medium |

## Hosting state (verified 2026-05-19)

```
URL:                https://fenia.com.ar/
Server header:      Vercel
X-Vercel-Cache:     HIT
X-Vercel-Id:        gru1::zvm9k-1779213743748-a89048f10573
Last-Modified:      Mon, 11 May 2026 18:51:59 GMT
Cache-Control:      public, max-age=0, must-revalidate
Content-Length:     56771 bytes (HTML only)
```

**Conclusion:** Vercel serves the content. Hostinger (mentioned by owner) presumably manages the DNS only — verification pending ([PENDING.md #2](../../PENDING.md)).

## What this audit triggered

- Branch `dev` created from `main`.
- Foundation documentation written (`AGENTS.md`, `README.md`, `PENDING.md`, `.env.example`, 7 ADRs, 5 ai-context files).
- Content layer introduced (`src/data/site.ts`, `src/content.config.ts`, 3 placeholder products under `src/content/products/`, testimonials directory placeholder).
- **No visual changes yet.** Components remain untouched pending owner mockup approval.

## Next audit

Re-run after Fase 1 (Plan Maestro) completes. Expected gains:
- Performance: -100 KB of fonts, +Lighthouse score from intro skip.
- UX: WCAG AA contrast restored, dead links removed, intro skippable.
- Consistency: one source of truth for content; coherent stats.
- DX: `npm run check` available; ADRs guide every future change.
