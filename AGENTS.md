# AGENTS.md — fenia-web

<rules>
  <rule mandatory="true">
    <strong>DOCUMENTATION ROUTING MATRIX</strong>
    Before writing ANY code in this repository, you MUST fetch and read the specified files related to your task:
    <ul>
      <li><strong>Big picture / what the site is</strong> 👉 <code>docs/ai-context/system-overview.md</code> &amp; <code>README.md</code></li>
      <li><strong>Anything that touches site copy, WhatsApp number, social links, hero stats, products, testimonials</strong> 👉 <code>docs/ai-context/content-model.md</code> &amp; <code>docs/adr/ADR-003-content-layer.md</code> — <strong>NEVER hardcode these in components; they live in <code>src/data/site.ts</code> or <code>src/content/</code></strong></li>
      <li><strong>Styles, colors, typography, responsive, animations</strong> 👉 <code>docs/ai-context/style-guide.md</code> &amp; <code>docs/adr/ADR-004-styling-strategy.md</code></li>
      <li><strong>Deployment, hosting, env vars, domain, DNS</strong> 👉 <code>docs/ai-context/deployment.md</code> &amp; <code>docs/adr/ADR-002-hosting-vercel.md</code> &amp; <code>PENDING.md</code></li>
      <li><strong>Stack choices, framework versions, why Astro/React/Tailwind</strong> 👉 <code>docs/adr/ADR-001-tech-stack.md</code></li>
      <li><strong>Testimonial collection / publication flow</strong> 👉 <code>docs/adr/ADR-005-testimonial-collection.md</code></li>
      <li><strong>Local dev experience / scripts / why <code>npm run dev</code> must stay single-command</strong> 👉 <code>docs/adr/ADR-006-dx-single-command-dev.md</code></li>
      <li><strong>The video logo (HeroVideo component, history of the intro video)</strong> 👉 <code>docs/adr/ADR-007-intro-video-as-logo-banner.md</code></li>
      <li><strong>3D neural network, animations runtime, Pack 1/2/3/4 architecture</strong> 👉 <code>docs/adr/ADR-008-animations-and-3d-neural-network.md</code> &amp; <code>src/scripts/animations.ts</code> &amp; <code>src/components/NeuralNetwork3D.jsx</code></li>
      <li><strong>SEO meta, Schema.org JSON-LD, canonical, sitemap, 404</strong> 👉 <code>docs/adr/ADR-009-seo-structured-data.md</code> &amp; <code>src/layouts/Layout.astro</code></li>
      <li><strong>Accessibility, WCAG AA, ARIA, prefers-reduced-motion contracts</strong> 👉 <code>docs/adr/ADR-010-accessibility-pass.md</code></li>
      <li><strong>Pack v2 polish — Bloom, magnetic CTAs, tilt cards, sticky section label, custom View Transitions</strong> 👉 <code>docs/adr/ADR-011-pack-v2-premium-polish.md</code></li>
    </ul>
  </rule>

  <rule mandatory="true">
    <strong>BRANCH PROTOCOL — non-negotiable</strong>
    <ul>
      <li>Default working branch: <code>dev</code>. <strong>Never commit directly to <code>main</code>.</strong></li>
      <li><code>main</code> is the production branch — every push to it auto-deploys to Vercel (<code>fenia.com.ar</code>).</li>
      <li>Only merge <code>dev → main</code> via PR <em>and</em> explicit human approval. No silent merges.</li>
      <li>If you find yourself on <code>main</code>, switch back with <code>git checkout dev</code> before touching any file.</li>
    </ul>
  </rule>

  <rule mandatory="true">
    <strong>SINGLE-COMMAND DX — sacred</strong>
    The site owner is non-technical. <code>npm run dev</code> MUST remain the only command needed to run the site locally.
    <ul>
      <li>Never introduce a "start backend, then frontend, then ngrok" workflow.</li>
      <li>If you add a service, orchestrate it inside <code>npm run dev</code> via <code>npm-run-all</code> / <code>concurrently</code>.</li>
      <li><code>astro check</code>, <code>astro sync</code>, lint, format → separate scripts, never chained into <code>dev</code>.</li>
      <li>See <code>docs/adr/ADR-006-dx-single-command-dev.md</code> for the reasoning.</li>
    </ul>
  </rule>

  <rule mandatory="true">
    <strong>PROPOSE-THEN-EXECUTE for any visual change</strong>
    Before changing colors, layouts, animations, components or rewriting any visible section, output a written or ASCII proposal and wait for human approval. Data-only changes (copy in <code>site.ts</code>, new product in <code>src/content/products/</code>) can proceed without approval but must be reported back.
  </rule>

  <rule mandatory="true">
    <strong>NO PLACEHOLDER LIES on a live production site</strong>
    <code>fenia.com.ar</code> is the public face of a real consulting business. Never publish AI-invented testimonials, fake KPIs, or product names that don't exist. If real content is missing, use a "Próximamente" state or hide the section. See <code>docs/adr/ADR-005-testimonial-collection.md</code> for the legitimate review-collection flow.
  </rule>

  <rule mandatory="true">
    <strong>PRESERVE OPERATIONAL CONTINUITY</strong>
    The site is live and the owner (a non-technical friend of the project maintainer) depends on it. Any change that risks visual regression must be validated against the live site via Playwright screenshots before/after, or via a Vercel preview deploy.
  </rule>
</rules>

> Guidance for AI coding assistants and human contributors working in this repository.

## Project Summary

`fenia-web` is the public marketing site for **FENIA — Formación Estratégica de Neuro-Inteligencia Aumentada**, an Argentine consulting/training brand that articulates neuroscience, strategy and applied AI for three audiences: profesionales, emprendedores, empresas. It is a **static Astro one-page** deployed on Vercel at `https://fenia.com.ar/`. There is no backend yet; all conversions route to WhatsApp.

## Workspace Structure

```
fenia-web/
├── docs/
│   ├── adr/                # Architecture Decision Records (ADR-001 → ADR-00n)
│   ├── ai-context/         # AI-readable context (system overview, content model, deployment, style guide)
│   └── audit/              # Point-in-time audits and reports
├── public/                 # Static assets (favicons, images, intro video)
├── src/
│   ├── components/         # Astro + React components
│   ├── content/            # Astro Content Collections (products, testimonials)
│   ├── data/               # Hand-edited typed data (site.ts: WhatsApp, social, stats)
│   ├── layouts/            # Astro layouts
│   ├── pages/              # Routes (currently only index.astro)
│   └── styles/             # global.css (being phased toward Tailwind utilities)
├── AGENTS.md               # ← you are here
├── PENDING.md              # Tasks blocked on the owner (Vercel access, real content, …)
├── README.md
├── astro.config.mjs
├── package.json
├── tailwind.config.mjs
└── tsconfig.json
```

## Key Commands

```sh
npm install        # Install dependencies (Node >=22.12.0)
npm run dev        # ✨ The only command the owner needs. Astro dev server at http://localhost:4321
npm run build      # Production build (used by Vercel)
npm run preview    # Preview the production build locally
npm run check      # astro check (TypeScript + Astro diagnostics) — optional, recommended pre-PR
npm run sync       # astro sync (regenerate content collection types) — optional, run if TS complains
```

## Tech Stack

- **Framework**: Astro `^6.1.3` (static output)
- **UI islands**: React `^19.2.4` (only `IntroLoader` and `Servicios`)
- **Styling**: Tailwind `^4.2.2` via `@tailwindcss/vite` (migration from inline styles in progress — see [ADR-004](./docs/adr/ADR-004-styling-strategy.md))
- **TypeScript**: strict (Astro preset)
- **Node**: `>=22.12.0`
- **Font**: `@fontsource/plus-jakarta-sans`

## Deployment

- **Production**: Vercel auto-deploys on every push to `main` → `https://fenia.com.ar/`
- **Preview**: every PR / branch push to GitHub triggers a Vercel preview deploy (URL posted in PR)
- **DNS**: managed externally (possibly Hostinger — see [PENDING.md](./PENDING.md) item #1)
- See [docs/adr/ADR-002-hosting-vercel.md](./docs/adr/ADR-002-hosting-vercel.md) and [docs/ai-context/deployment.md](./docs/ai-context/deployment.md)

## For non-technical readers

If you are the site owner (not a developer): you only need two things.

```sh
npm install   # once, to download libraries
npm run dev   # every time you want to preview locally — opens http://localhost:4321
```

Everything else is for collaborators. If something feels too complicated, it is a bug in our process — open an issue.
