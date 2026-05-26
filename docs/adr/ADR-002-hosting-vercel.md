# ADR-002 — Hosting on Vercel (status quo + investigation pending)

## Status
**Superseded by [ADR-018](./ADR-018-migrate-to-cloudflare-pages.md) (2026-05-26).** The site is migrating to Cloudflare Pages. This ADR is kept as historical record of the prior decision and its rationale; do not act on it.

Originally: Accepted (2026-05-19) for the production deploy.

## Context

The live site is served at `https://fenia.com.ar/`. HTTP header inspection on 2026-05-19 returned:

```
Server: Vercel
X-Vercel-Cache: HIT
X-Vercel-Id: gru1::zvm9k-1779213743748-a89048f10573
Last-Modified: Mon, 11 May 2026 18:51:59 GMT
Cache-Control: public, max-age=0, must-revalidate
```

This confirms **Vercel is serving the content**. The deployment is auto-triggered from pushes to `main` of the repo `github.com/FabFar12/fenia-web` (the `.gitignore` includes `.vercel/`, consistent with Vercel CLI / GitHub integration).

The owner separately mentioned the site is "also on Hostinger". No Hostinger headers are visible. Most likely Hostinger holds the **domain registration / DNS** for `fenia.com.ar` and the `A`/`CNAME` records point to Vercel. Possibilities:
- (a) Hostinger only manages the domain → keep paying Hostinger only if domain is cheaper there than transferring.
- (b) There is a legacy Hostinger hosting plan still active and unused → cancel to avoid recurring cost.
- (c) There is another sub-site on Hostinger → audit it.

The current Vercel project configuration is not versioned in the repo (no `vercel.json`). The build settings live in the Vercel UI dashboard, which the dev (Matías) does not have access to.

## Decision

1. **Keep Vercel as the production host.** It auto-detects Astro, gives preview deploys per branch/PR, includes a global CDN, and is free for this site's traffic.
2. **Do not introduce a `vercel.json` blind**, because creating one without knowing the current UI settings risks overriding them (regions, output directory, framework preset, ENV vars). Once Vercel access is granted ([PENDING.md #1](../../PENDING.md)), we will:
   - Take screenshots of current Build & Development Settings, Environment Variables, Domains, Functions Region.
   - Mirror them in `vercel.json` and commit. From that point the repo is the source of truth.
3. **Investigate Hostinger** ([PENDING.md #2](../../PENDING.md)) before touching DNS. If Hostinger only manages the domain, document it and leave alone. If there is unused hosting, cancel it.
4. **Use the `dev` branch + Vercel preview deploys** as the staging surface — never test changes by merging to `main`.

## Consequences

**Easier:**
- Zero-config deploys: every `git push origin dev` produces a preview URL we can share with the owner for visual review.
- Vercel Analytics + Speed Insights are one-toggle to enable later.
- HTTPS, HTTP/2, global CDN, atomic deploys: all included.

**Harder / accepted trade-offs:**
- Until Vercel access is granted, the deploy is a black box from the dev's perspective. Any setting changed in the UI will not be reflected in the repo.
- If Vercel free tier limits are hit (currently very far away), we have to revisit hosting strategy.
- Vendor lock-in is light (Astro builds to plain HTML/CSS/JS; moving to Netlify or CloudFlare Pages is a one-day operation if ever needed).
