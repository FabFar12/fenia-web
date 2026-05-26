# Audit — 2026-05-26 — Hosting migration decision (Vercel → Cloudflare Pages)

> Point-in-time audit produced when re-evaluating the hosting strategy for `fenia-web` six months after the initial deploy decision.
> Frozen — do not edit. Triggered ADRs 018 / 019 / 020 / 021.

## Trigger

Owner pushed back on Vercel: "queremos sacar Vercel". Reasons surfaced during the discussion:

- The Vercel Hobby plan prohibits commercial use; `fenia.com.ar` is a paid-services consulting site, so the current free deployment is in an uncertain ToS position.
- Moving to Vercel Pro to be compliant costs ~USD 20 / user / month — not justified for a 3-page static site with a single non-technical owner.
- A Hostinger plan was prepaid for 4 years and is mostly idle. The owner wanted to know whether to consolidate everything on Hostinger to "make the prepay earn its keep".

## Method

- Re-read the repo state as of `baf3282` on `dev` (clean tree).
- Re-read all 17 existing ADRs and ai-context docs.
- Inspected the production build (`dist/`) to size and characterize assets — 3.8 MB total, dominated by `intro-fenia.mp4` (2.4 MB) and `vendor-three.js` (969 KB minified).
- Inspected `src/` for any backend hooks, fetch calls, or runtime APIs — none beyond a single build-time `PUBLIC_TALLY_TESTIMONIAL_FORM_URL` import.
- Catalogued the inline-script and inline-style surface area that constrains a future CSP.
- Compared 12 hosting options (Vercel Hobby/Pro, Netlify, Cloudflare Pages, GitHub Pages, Hostinger shared, VPS, Docker on VPS, Railway, AWS S3+CloudFront, AWS Amplify, Azure SWA, Fly.io) against the actual workload requirements.

The full comparison and reasoning sit in the conversation that produced this audit. This document records only the decision and its justification.

## Findings

### Workload characterization

- **Pure SSG**, no backend, no auth, no DB, no WebSockets, no background jobs.
- **3 routes**: `/`, `/dejanos-tu-testimonio`, `/404`.
- **One env var** at build time (`PUBLIC_TALLY_TESTIMONIAL_FORM_URL`); the rest of the build is hermetic.
- **No fetch in runtime code**.
- **Conversion is 100% via WhatsApp** (no email form, no payment, no booking).

### Constraints

- **Single non-technical owner.** Anything that requires the owner to operate a terminal, a CLI, FTP, or a server is disqualified. This kills VPS, Docker, AWS S3+CloudFront DIY, and self-hosted everything.
- **Single dev.** Anything that requires ongoing IaC discipline (Terraform, Pulumi) or fleet management is over-engineered.
- **Single-command DX must hold** ([ADR-006](../adr/ADR-006-dx-single-command-dev.md)).
- **Brand requires no cookie banner** — privacy-by-design analytics only. Disqualifies GA4 without consent infrastructure.
- **Hostinger 4-year prepay is a sunk cost.** Optimization variable, not a constraint — we should use it for what it does well (domain + email), not contort the architecture to "consume the credit".

### Comparison summary

The two finalists were:

- **Vercel Pro** — best-in-class DX, lock-in, USD 20/user/month for commercial use.
- **Cloudflare Pages** — equivalent DX for this workload, free, no commercial restriction, larger edge in Latin America.

Other options ranked behind these for one of three reasons: regression in DX (Hostinger shared, GitHub Pages), over-engineering (VPS, Docker, K8s, AWS DIY), or no advantage at higher cost (Netlify, Amplify, Azure SWA).

## Decision

Recorded in [ADR-018](../adr/ADR-018-migrate-to-cloudflare-pages.md):

- **Web hosting** → Cloudflare Pages.
- **DNS** → Cloudflare.
- **Domain registrar + email** → Hostinger (already paid; do not move).
- **Vercel** → drop after a 1-week observation window post-cutover.

Three companion ADRs anchor the work:

- [ADR-019](../adr/ADR-019-version-hosting-config.md) — `public/_headers` and `public/_redirects` versioned in repo, including a baseline CSP with deliberate `'unsafe-inline'` debt linked to [ADR-004](../adr/ADR-004-styling-strategy.md).
- [ADR-020](../adr/ADR-020-ci-minimal-github-actions.md) — minimal CI quality gate (`astro check` + `npm run build`) on every PR.
- [ADR-021](../adr/ADR-021-observability-baseline.md) — Cloudflare Web Analytics + Sentry + UptimeRobot baseline, deferred until after cutover stabilises.

## Open risks (carried into ADRs and PENDING.md)

| ID | Risk | Mitigation | Owner |
|---|---|---|---|
| R1 | Cloudflare Pages's build runner produces a different artifact than local | PoC subdomain `cf-preview.fenia.com.ar` for 48–72 h before DNS cutover ([deployment.md](../ai-context/deployment.md)) | dev |
| R2 | CSP starts loose (`'unsafe-inline'`) and is forgotten | Linked to [ADR-004](../adr/ADR-004-styling-strategy.md) phased Tailwind migration; revisit when ADR-004 closes | dev |
| R3 | DNS cutover window catches a real user mid-action | TTL lowered to 300 s 48 h before cutover; `MX` records untouched so email continuity is preserved | dev |
| R4 | Vercel plan tier still unknown | [PENDING.md #3](../../PENDING.md) — owner provides access or screenshots | owner |
| R5 | Hostinger plan content still unconfirmed | [PENDING.md #2](../../PENDING.md) — owner asks the friend | owner |
| R6 | Cloudflare Web Analytics lacks custom events | Accepted for MVP; upgrade path to Plausible Cloud (~USD 9/mo) documented in [ADR-021](../adr/ADR-021-observability-baseline.md) | dev |

## Cost delta

| Item | Before (Vercel Hobby, in ToS limbo) | After (Cloudflare Pages free + Hostinger sunk cost) |
|---|---|---|
| Web hosting | USD 0 (risky) or USD 20+ (legal) | USD 0 |
| Analytics | USD 0 (none) | USD 0 (Cloudflare Web Analytics) |
| Error tracking | USD 0 (none) | USD 0 (Sentry free tier) |
| Uptime monitoring | USD 0 (none) | USD 0 (UptimeRobot free) |
| Domain + email | unaccounted | already in 4-year prepay |
| **Marginal** | **0–20+** | **0** |

## Why this audit exists

To make the rationale legible in 6 months when someone wonders "why did we change hosts?" and to give the owner a one-stop document if they ever need to justify the change to anyone else (accountant, partner, future maintainer). Subsequent audits should reference this one as the baseline for the post-migration state.
