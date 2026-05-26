# ADR-018 — Migrate production hosting from Vercel to Cloudflare Pages

## Status
**Superseded by [ADR-022](./ADR-022-pivot-to-hostinger-only.md) (2026-05-26, same day).** The owner reversed course within hours and directed Hostinger-only hosting instead of Cloudflare Pages. The technical analysis in this ADR remains accurate; the *decision* is no longer in effect. Kept as historical record.

Originally: Accepted (2026-05-26). Superseded [ADR-002](./ADR-002-hosting-vercel.md).

## Context

The site has been served from Vercel since the initial deploy ([ADR-002](./ADR-002-hosting-vercel.md)). Two pressures triggered a re-evaluation in 2026-05-26:

1. **Vercel Hobby ToS prohibit commercial use.** `fenia.com.ar` is the public face of a paid consulting / training business; conversions route to a paid-services WhatsApp. The current Vercel plan is not verified ([PENDING.md #1](../../PENDING.md)), but if it is Hobby, the deployment is operating outside the platform's terms. Moving to Vercel Pro to be compliant costs ~USD 20 / user / month.

2. **Hostinger is already paid for 4 years.** The owner has a multi-year Hostinger plan that covers domain + email (and possibly shared hosting that is not being used to serve this site — see [PENDING.md #2](../../PENDING.md)). Using Hostinger as web host is technically possible but operationally worse: no native git-based auto-deploy, no per-PR previews, no atomic rollback, single-region edge. Using Hostinger only for what it does well (domain registrar + email) and pairing it with a free, modern static host is strictly better.

A full audit was performed before this decision — see [docs/audit/2026-05-26-hosting-migration-decision.md](../audit/2026-05-26-hosting-migration-decision.md). Cloudflare Pages came out on top against Vercel, Netlify, Hostinger shared, VPS, and AWS S3+CloudFront for this specific workload (Astro SSG, no backend, Latin-American audience, single non-technical owner).

## Decision

**Migrate production hosting to [Cloudflare Pages](https://pages.cloudflare.com/), keep Hostinger for what it already provides (domain + email), drop Vercel.**

Concretely:

1. **Web hosting:** Cloudflare Pages, connected to the GitHub repo (`github.com/FabFar12/fenia-web`). Build command `npm run build`, output directory `dist/`, framework preset *Astro*. Node ≥22.12 via `NODE_VERSION` environment variable.
2. **DNS:** move to Cloudflare DNS (free, faster, integrated with Pages). The `fenia.com.ar` apex + `www.fenia.com.ar` CNAME / flatten to the Pages project.
3. **Domain registrar:** stays at Hostinger (it is already paid for 4 years; moving it before that runs out is throwing money away).
4. **Email:** stays on Hostinger. The current `MX` / `SPF` / `DKIM` records are preserved during the Cloudflare DNS migration.
5. **Vercel:** project paused for one week after Cloudflare cutover (instant rollback if needed), then deleted.
6. **Hosting configuration becomes source-of-truth in the repo** via `_headers` and `_redirects` files at the repo root — see [ADR-019](./ADR-019-version-hosting-config.md).
7. **Single-command DX is preserved** ([ADR-006](./ADR-006-dx-single-command-dev.md)). Cloudflare Pages does not require any local CLI for the owner; `npm run dev` is unchanged.

### What is NOT changing

- The codebase. Astro 6 produces plain HTML/CSS/JS; nothing in `src/` needs to change for the migration itself.
- `npm run dev`, `npm run build`, `npm run preview` — identical on both platforms.
- The `dev` branch as the working surface, `main` as production-trigger ([AGENTS.md § BRANCH PROTOCOL](../../AGENTS.md)).
- Preview-deploys-per-PR semantics. Cloudflare Pages provides them natively, same as Vercel.

## Consequences

**Easier:**

- **Cost goes to ~USD 0 / month** for the web hosting layer (Cloudflare Pages free tier covers this site comfortably: unlimited static bandwidth, 500 builds/month, custom domains, automatic HTTPS via Let's Encrypt).
- **No more commercial-use ToS uncertainty.** Cloudflare Pages free tier has no commercial-use restriction.
- **Bandwidth ceiling is effectively unlimited** for static assets — meaningful as the intro video alone is 2.4 MB per first-time visit.
- **Configuration becomes versioned** (`_headers`, `_redirects`) — the long-standing problem of "the Vercel UI is the source of truth" goes away.
- **Headers and security posture become reviewable** in PRs (CSP, HSTS, Permissions-Policy live in the repo, not in a UI).
- **Latin-American edge** is larger on Cloudflare than on Vercel's free tier (more PoPs in Buenos Aires / São Paulo / Lima / Bogotá).
- **Hostinger continues to earn its keep** (email + domain), so the 4-year prepay is not wasted.

**Harder / accepted trade-offs:**

- **Build pipeline change.** Cloudflare Pages builds inside its own runner. We must verify that `npm run build` produces the same artifacts there as locally. Mitigation: a temporary preview subdomain (`cf-preview.fenia.com.ar`) runs in parallel to Vercel for 48–72 h before the DNS cutover.
- **Less polished `Speed Insights`** out of the box than Vercel. Mitigation: Cloudflare Web Analytics is free and privacy-friendly; tighter performance measurement can be layered with `web-vitals` library reporting RUM to Plausible custom events ([ADR-021](./ADR-021-observability-baseline.md)).
- **Cloudflare account becomes a new operational dependency.** Mitigation: invite the owner as a member from day one, document credentials in [`docs/ai-context/deployment.md`](../ai-context/deployment.md).
- **One-time DNS propagation window** (TTL 300 s set 48 h prior to cutover keeps the window short).
- **Slightly less mature Astro integration** than Vercel's. Astro 6 is officially supported, but historically some edge-case adapters (image optimization, middleware) have lagged. For *this* project, which is pure SSG, there is no edge case to worry about.

**Migration path away from Cloudflare Pages (if ever needed):**

- Trivial. Astro builds to static HTML/CSS/JS in `dist/`. Any static host — Netlify, Vercel, GitHub Pages, S3+CloudFront, even Hostinger via FTP — can serve the same artifacts. The `_headers` / `_redirects` files are Cloudflare-flavored but translate to `vercel.json` or Netlify's `_headers` with mechanical edits. Vendor lock-in: **low**.

## Alternatives considered

| Option | Why not |
|---|---|
| Stay on Vercel Free (Hobby) | Likely violates Vercel's ToS for commercial sites; risk of sudden upgrade demand or suspension |
| Upgrade to Vercel Pro | ~USD 20 / user / month for *the same product* Cloudflare Pages gives for free. The DX edge over Cloudflare is real but small and not worth recurring cost for a personal-scale consulting site |
| Move web to Hostinger shared hosting | Hostinger is paid, but its hosting product lacks git auto-deploy, per-PR previews, atomic rollback, and a comparable CDN. Using it for the web is a regression in operability and performance |
| Netlify | Equivalent product to Vercel with similar free-tier limitations; no advantage over Cloudflare for this workload |
| GitHub Pages | No native per-PR preview deploys, no custom HTTP headers (no CSP/HSTS via repo) — disqualifies it for any site with security headers |
| VPS / Docker | Wildly over-engineered for a static site; introduces patching, certs, deploy scripts the single non-technical owner cannot operate |
| AWS S3 + CloudFront + ACM | Technically valid; the IaC and operational overhead is not justified by one dev and a static site |

## Follow-ups (linked work)

- [ADR-019](./ADR-019-version-hosting-config.md) — Version hosting config (`_headers`, `_redirects`).
- [ADR-020](./ADR-020-ci-minimal-github-actions.md) — Minimal CI workflow as a quality gate (decoupled from hosting but enabled by this migration).
- [ADR-021](./ADR-021-observability-baseline.md) — Observability baseline (analytics + error tracking + uptime) using Cloudflare Web Analytics + Sentry + UptimeRobot.
- [PENDING.md](../../PENDING.md) — operational tasks blocked on the owner (Cloudflare account creation, Hostinger plan confirmation, DNS cutover window).
