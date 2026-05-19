# Deployment — Vercel + DNS

> Last updated: 2026-05-19. Some sections marked **PENDING** are blocked on owner access — see [PENDING.md](../../PENDING.md).

## Production

- **URL**: `https://fenia.com.ar/`
- **Hosting**: Vercel
- **Trigger**: every push to `main` branch of `github.com/FabFar12/fenia-web` produces an automatic production deploy.
- **Framework preset (Vercel UI)**: Astro (auto-detected). **PENDING — confirm from dashboard.**
- **Build command** (Vercel UI default): `npm run build` (i.e., `astro build`).
- **Output directory**: `dist/` (Astro default).
- **Region**: **PENDING — confirm from dashboard.** Likely `iad1` or `gru1` (Vercel defaults; the `X-Vercel-Id: gru1::...` header on responses suggests São Paulo).
- **Environment variables**: **none currently required** (the site is fully static). The `.env.example` documents future placeholders.

## Preview deploys

Any branch other than `main` (including `dev`) auto-deploys to a unique Vercel preview URL on every push. PRs to `main` also get a preview URL posted in the PR by the Vercel GitHub bot.

**Use previews to validate visual changes before merging.** Send the preview URL to the owner; do not merge until visual approval is given (see [AGENTS.md](../../AGENTS.md) § PROPOSE-THEN-EXECUTE).

## DNS

- The apex domain `fenia.com.ar` and `www.fenia.com.ar` resolve to Vercel's edge network.
- Registrar / DNS provider: **PENDING confirmation** — likely Hostinger (per owner's mention). See [PENDING.md #2](../../PENDING.md).
- If Vercel manages the certificate, it auto-renews via Let's Encrypt.

## What is NOT versioned (yet)

These live in the Vercel dashboard and are not in the repo:

- Build command override (if any beyond `npm run build`).
- Output directory override (if any beyond `dist`).
- Environment variables.
- Domain configuration.
- Function regions.
- Analytics toggles (Vercel Analytics + Speed Insights).

**Action item ([PENDING.md #1](../../PENDING.md)):** when the dev gets access to the Vercel project, mirror all of the above into `vercel.json` so the repo becomes the single source of truth.

## Local development

```sh
npm install
npm run dev
```

Single command, by design ([ADR-006](../adr/ADR-006-dx-single-command-dev.md)). Available at `http://localhost:4321`.

No services to start, no `.env` to populate, no Docker. If a future feature breaks this property, it must explicitly supersede ADR-006 with owner approval.

## Production build verification

Before merging to `main`:

```sh
npm run build      # produces dist/ — same command Vercel runs
npm run preview    # serves dist/ locally to verify
npm run check      # astro check — TypeScript + Astro diagnostics
```

A green `npm run build` + visual approval of the Vercel preview = safe to merge.

## Rollback strategy

Vercel keeps every previous production deploy reachable. If `main` breaks:

1. Go to Vercel dashboard → Deployments → find the last known-good deploy.
2. Click "Promote to Production".
3. Rollback is instant.

Then fix forward in a new commit on `dev` and merge again.

## Monitoring

- **None today.** No analytics, no error tracking, no uptime check, no Web Vitals.
- **Queued** (Fase 3 of the Plan Maestro):
  - Plausible Analytics (privacy-first) — `PUBLIC_PLAUSIBLE_DOMAIN` in `.env.example` is the placeholder.
  - Sentry browser SDK for client-side JS errors — `PUBLIC_SENTRY_DSN`.
  - Vercel Speed Insights — one-toggle, free.
  - Lighthouse CI on PRs.
