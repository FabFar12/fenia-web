# Audit — 2026-05-26 — Hosting pivot to Hostinger-only (owner override)

> Point-in-time audit recording the **same-day reversal** of the Vercel → Cloudflare Pages migration decision (recorded in [2026-05-26-hosting-migration-decision.md](./2026-05-26-hosting-migration-decision.md), frozen).
> Frozen — do not edit. Triggered ADRs 022 / 023 / 024 and updated ADR-020.

## Trigger

After the Vercel → Cloudflare Pages plan was prepared in the repo (ADRs 018–021, `public/_headers`, `public/_redirects`, `.github/workflows/ci.yml` quality gate, all docs), the **owner (Fab) pushed back and insisted on consolidating everything on Hostinger**: a single provider, a single invoice, leveraging the already-prepaid 4-year plan in full.

Exact quote from Matías (the dev), reporting Fab's position: *"mi cliente es un porfiado e insiste en que solo este en hostinger asiq cambiame todo, borra lo que nosirva y actualiza toda la documentacion para que solo funcione con hostinger."*

The technical recommendation was **not** Hostinger. The technical recommendation was Cloudflare Pages (free, edge-first, native git auto-deploy, per-PR previews, atomic rollback). Hostinger is operationally inferior for a static Astro site in every measurable dimension *except* "single provider".

This audit records the decision-process from the dev's side, the trade-offs the owner accepts, and the implementation pivot. **The decision is the owner's to make** — operational consolidation has real value, especially for a non-technical principal who would prefer one bill over four.

## Method

- Re-read the just-completed Cloudflare Pages prep (ADRs 018–021, `_headers`, `_redirects`, workflow CI quality gate).
- Identified what is **deletable** (Cloudflare-specific artifacts), what is **convertible** (the workflow extends with a deploy job; the docs rewrite; the `_headers` content informs `.htaccess`), and what carries over unchanged (Sentry + UptimeRobot from ADR-021, the CI quality-gate step from ADR-020).
- Asked Matías four blocking questions before mutating the repo:
  1. Plan tier — *"pago 200.000 pesos argentinos por 4 años..no se a que promo hara referencia. es lo unico que se"*. Assumption: Premium tier; FTPS available, SSH not guaranteed. Deploy method defaults to FTPS for portability.
  2. Deploy method — *"GitHub Actions vía SFTP/SSH (Recomendado)"* (the user picked the recommended option, but we implement FTPS rather than SFTP because the plan tier hasn't been confirmed to include SSH; if confirmed Business+, switch is a 5-line change).
  3. Web server — *"No sé / preguntar a Fab"*. Assumption: LiteSpeed (Hostinger's default since ~2020). `.htaccess` written in pure Apache directives, which LiteSpeed parses natively, covering both cases.
  4. Timing — *"Refactor ahora + cutover inmediato apenas tengamos credenciales"*. So: prep the repo now, complete cutover when Fab delivers FTPS creds.

## Findings

### What gets deleted

- `public/_headers` — Cloudflare-Pages-specific format. Removed in this pivot.
- `public/_redirects` — same.

### What gets supersededment-by-superseded

| Original (2026-05-26 morning) | Successor (2026-05-26 afternoon) | Why |
|---|---|---|
| [ADR-018 — Migrate to Cloudflare Pages](../adr/ADR-018-migrate-to-cloudflare-pages.md) | [ADR-022 — Pivot to Hostinger-only](../adr/ADR-022-pivot-to-hostinger-only.md) | Owner override |
| [ADR-019 — Version hosting config (Cloudflare format)](../adr/ADR-019-version-hosting-config.md) | [ADR-023 — `.htaccess` for hosting config](../adr/ADR-023-htaccess-hosting-config.md) | Format change; principle preserved |
| [ADR-021 — Observability baseline (Cloudflare-anchored)](../adr/ADR-021-observability-baseline.md) | [ADR-024 — Observability on Hostinger](../adr/ADR-024-observability-on-hostinger.md) | Cloudflare Web Analytics out → Plausible in |

### What gets extended (not superseded)

- [ADR-020 — CI on GitHub Actions](../adr/ADR-020-ci-minimal-github-actions.md): same-day extension from quality-gate-only to quality-gate + FTPS deploy. The original mandate is *narrowed* (PR builds still don't deploy), not contradicted.

### What carries over unchanged

- All of [ADR-001 through ADR-017](../adr/) (stack, content layer, styling, animations, SEO, accessibility, …).
- Sentry + UptimeRobot from the observability stack ([ADR-024](../adr/ADR-024-observability-on-hostinger.md) inherits these from [ADR-021](../adr/ADR-021-observability-baseline.md)).
- The `npm run dev` single-command DX contract ([ADR-006](../adr/ADR-006-dx-single-command-dev.md)).
- The `dev` → `main` branch protocol.
- The CSP technical-debt situation (still requires `'unsafe-inline'` until [ADR-004](../adr/ADR-004-styling-strategy.md) closes).

## Owner override — risks the owner is accepting

These are the trade-offs that the owner is *informedly choosing* by insisting on Hostinger-only. The dev's job is to make them work; the owner's job is to have understood them. **This list is the dev's record that the trade-offs were communicated.**

| Risk | Materialised as | What we lose vs Cloudflare Pages |
|---|---|---|
| No per-PR previews | Every visual change is tested locally (`npm run preview`) or on a manually-uploaded subdomain | The frictionless review loop that AGENTS.md § PROPOSE-THEN-EXECUTE depends on |
| No atomic rollback | Rollbacks are "re-run the prior workflow", ETA 2–3 min instead of seconds | Confidence to ship more aggressively |
| Single-region edge | LCP in Mexico City, Bogotá, Lima will be worse | Latam-wide performance parity |
| Shared hosting resource caps | A traffic spike (campaign, viral share) can degrade response times | Auto-scale-to-infinity that CDNs provide |
| FTPS credentials in CI Secrets | Larger blast radius if leaked | The "no creds, just git" simplicity |
| `.htaccess` per-request parse cost | Trivial at this scale; not trivial if traffic grows | Server config parsed once at startup |
| Hostinger as single point of failure | If Hostinger has a regional outage, the whole stack (web + DNS + email) is down | Provider diversification by design |

## Decision

Recorded in [ADR-022](../adr/ADR-022-pivot-to-hostinger-only.md). Implementation summary:

1. Web hosting on Hostinger shared (LiteSpeed assumed).
2. Hosting config in [`public/.htaccess`](../../public/.htaccess) (security headers, cache, 404, HTTPS enforcement).
3. Deploy via GitHub Actions FTPS using `SamKirkland/FTP-Deploy-Action@v4.3.5`, gated on the existing quality-gate job.
4. Observability rebaselined: Plausible (analytics, USD 9/mo) + Sentry (errors, free) + UptimeRobot (uptime, free).
5. Vercel cancelled after a 1-week observation window post-cutover.

## Open risks (carried into ADRs and PENDING.md)

| ID | Risk | Mitigation | Owner |
|---|---|---|---|
| R1 | Plan tier might not support FTPS (very unlikely on Hostinger 2026) | Fall back to plain FTP with `protocol: ftp`; document in deployment.md | dev |
| R2 | `.htaccess` directive (e.g. `Options -Indexes`) triggers 500 on a restrictive plan | Comment offending line, redeploy; documented in [deployment.md](../ai-context/deployment.md) and [ADR-023](../adr/ADR-023-htaccess-hosting-config.md) | dev |
| R3 | Fab takes >1 week to deliver FTPS creds | Repo stays in current state; Vercel keeps serving production; no urgency-driven hacks | owner |
| R4 | Visual regression slips through without per-PR previews | Discipline: `npm run preview` + screenshots before merging anything visual; consider setting up `staging.fenia.com.ar` if regressions surface | dev |
| R5 | FTPS credentials leak via misconfigured GitHub repo (public vs private) | Confirm repo is private; use dedicated FTP account scoped to `/public_html/`; rotate on any suspicion | dev |
| R6 | Plausible Cloud cost (USD 9/mo) is a real recurring cost | Self-host Plausible if ever painful; documented in [ADR-024](../adr/ADR-024-observability-on-hostinger.md) | future |

## Cost delta (revised)

| Item | Vercel (legacy) | Cloudflare Pages (interim plan) | Hostinger (current decision) |
|---|---|---|---|
| Web hosting | USD 0 risky / USD 20+ legal | USD 0 | USD 0 marginal (uses 4-year prepay) |
| DNS | external | Cloudflare free | already in plan |
| Email | external | Hostinger (already prepaid) | already in plan |
| Analytics | USD 0 (none) | USD 0 (CF Web Analytics) | USD 9/mo (Plausible Cloud) |
| Error tracking | USD 0 | USD 0 (Sentry free) | USD 0 (Sentry free) |
| Uptime | USD 0 | USD 0 (UptimeRobot free) | USD 0 (UptimeRobot free) |
| **Marginal monthly** | **USD 0–20+** | **USD 0** | **~USD 9** (Plausible) |

The pivot costs USD 9/mo more than the interim Cloudflare plan, gained operational consolidation. The owner judges that worthwhile.

## Why this audit exists

To make explicit that the dev *did* push back and articulate the trade-offs, that the owner *did* override the technical recommendation knowingly, and that the repo state from this point onwards is the consequence of that decision — not the dev's first preference. Documentation discipline preserves the rationale chain across future personnel changes, future migrations, and future "why did we end up here?" moments.
