# ADR-001 — Tech Stack: Astro + React islands + Tailwind v4

## Status
Accepted (2026-05-19) — confirms and documents the stack already in place.

## Context
`fenia-web` is a static marketing one-pager for a consultancy. The constraints are:

- Single non-technical owner who runs the site locally with `npm run dev`.
- No backend today; conversions route to WhatsApp.
- Must deploy to Vercel without server-side rendering complexity.
- Future flexibility: may add interactive flows (testimonial form, product detail pages, eventual checkout).
- Must remain SEO-friendly and fast on mobile 3G/4G (Latin American audience).

The stack already chosen (presumably by a prior AI-assisted iteration) is Astro 6 + React 19 + Tailwind 4 + TypeScript strict, on Node ≥22.12.

## Decision
**Keep the existing stack.** It is the right tool for this job:

- **Astro `^6.1.3`**: ships zero JavaScript by default, perfect for a static marketing site; the islands architecture lets us add interactivity only where needed (currently `IntroLoader` and `Servicios`).
- **React `^19.2.4`** as the islands runtime: most non-technical contributors searching for help on the internet find React snippets; the friction cost of choosing Preact/Solid for this small project is not worth the bundle savings.
- **Tailwind `^4.2.2`** via `@tailwindcss/vite`: utility-first CSS that scales without per-component stylesheets. Currently underused (inline styles dominate) — this is technical debt addressed in [ADR-004](./ADR-004-styling-strategy.md), not a stack problem.
- **TypeScript strict** (Astro preset): catches errors that would otherwise slip to production on a site nobody tests manually.
- **Plus Jakarta Sans** via `@fontsource`: self-hosted, no third-party request, GDPR-friendly.
- **Node ≥22.12**: matches what the owner has installed and what Vercel uses.

## Consequences

**Easier:**
- Vercel autodetects Astro and configures build correctly with zero config.
- Adding a new page is `touch src/pages/about.astro` — no routing config needed.
- Content collections give us a typed, file-based CMS without a database (see [ADR-003](./ADR-003-content-layer.md)).
- React islands keep familiarity for AI-assisted iterations.

**Harder / accepted trade-offs:**
- Two component formats coexist (`.astro` and `.jsx/.tsx`). We will standardize: prefer `.astro` for static UI, React only when stateful client logic is required (forms, interactive tabs, etc.).
- Tailwind 4 is recent — some examples on the internet still use v3 syntax. Refactors must use v4 (`@import "tailwindcss"`, CSS-native config).
- Plus Jakarta Sans with 5 weights weighs ~250 KB — reduce to 3 weights (400/600/800) in Fase 1.

**Migration cost away from this stack (if we ever change our mind):** moderate. The codebase is small (~1,000 LOC), the content is already extracted to data files (`src/data/site.ts`) and content collections (`src/content/*`), so a swap to Next, SvelteKit or plain HTML+CSS would be a 1-week refactor, not a rewrite.
