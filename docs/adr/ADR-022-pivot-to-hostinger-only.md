# ADR-022 — Pivot: Hostinger-only hosting (owner override)

## Status
Accepted (2026-05-26). Supersedes [ADR-018](./ADR-018-migrate-to-cloudflare-pages.md). **Owner override** — the technical recommendation in ADR-018 was Cloudflare Pages; the owner (Fab) explicitly directed otherwise.

## Context

Hours after [ADR-018](./ADR-018-migrate-to-cloudflare-pages.md) was accepted and the repo had been prepared for a Cloudflare Pages migration, the owner reversed course: **all hosting (web, DNS, email, domain) must run on Hostinger only.** The owner's reasoning is operational consolidation — a single provider, a single invoice, an already-prepaid 4-year plan (ARS 200,000) that should be used for what it was bought for.

The technical analysis is unchanged: Cloudflare Pages is still the objectively stronger fit for an Astro SSG site (free, edge-first, native git auto-deploy, per-PR previews, atomic rollback). The owner is choosing a sub-optimal platform deliberately, having been briefed on the trade-offs. **This is a legitimate decision** — operational simplicity for a non-technical owner has real value, and the dev's job is to make the chosen platform work, not to relitigate the choice.

The 4-year Hostinger plan tier is not yet confirmed (see [PENDING.md](../../PENDING.md)). ARS 200K/4yr ≈ USD 40–50/year ≈ a Premium or Business tier promo. We assume the plan supports at minimum **FTPS upload** (universal on Hostinger shared hosting since 2020). If it turns out to also support **SSH/SFTP** (Business tier and up), the deploy method tightens to SFTP without other changes.

## Decision

**Hostinger is the single provider for everything web-facing.** Concretely:

1. **Web hosting:** Hostinger shared hosting (LiteSpeed assumed; Apache directives in `.htaccess` are compatible with both).
2. **Domain registrar:** Hostinger (unchanged).
3. **DNS:** Hostinger (unchanged — we will NOT route DNS through Cloudflare).
4. **Email:** Hostinger (unchanged).
5. **Deploy method:** [GitHub Actions](../../.github/workflows/ci.yml) builds `dist/` and uploads it via **FTPS** to the Hostinger document root on every push to `main`. Credentials live in GitHub Secrets. See [ADR-020](./ADR-020-ci-minimal-github-actions.md) (updated to include deploy job in this pivot).
6. **Hosting configuration source-of-truth:** the repo, via [`public/.htaccess`](../../public/.htaccess). See [ADR-023](./ADR-023-htaccess-hosting-config.md) (replaces the Cloudflare Pages `_headers`/`_redirects` approach from the superseded [ADR-019](./ADR-019-version-hosting-config.md)).
7. **Observability:** rebaselined for a non-Cloudflare world. See [ADR-024](./ADR-024-observability-on-hostinger.md) (replaces the Cloudflare-Web-Analytics-anchored plan in superseded [ADR-021](./ADR-021-observability-baseline.md)).
8. **Vercel:** dropped after Hostinger cutover stabilizes.

### Files removed from the previously prepared plan

- `public/_headers` — deleted. Cloudflare-Pages-specific format, irrelevant on Hostinger.
- `public/_redirects` — deleted. Same reason.

These were committed nowhere; the deletion is mechanical cleanup of the unmerged migration prep.

### What is NOT changing

- The Astro codebase. `dist/` is the same artifact regardless of host.
- Single-command DX ([ADR-006](./ADR-006-dx-single-command-dev.md)). `npm run dev` is unchanged.
- The `dev` → `main` branch protocol ([AGENTS.md](../../AGENTS.md)).
- The CSP technical-debt scope (still requires `'unsafe-inline'` until [ADR-004](./ADR-004-styling-strategy.md) closes).

## Consequences

**Easier:**

- **One provider, one invoice, one support contact** for the owner.
- **The 4-year prepay actually earns its keep** — Hostinger now hosts the web, the email, the domain, all from the same plan.
- **Migration off Vercel can complete this week** once FTPS credentials are available, instead of waiting on Cloudflare account creation + DNS migration.

**Harder / accepted trade-offs (significant — the dev must accept these or escalate):**

- **No per-PR preview deploys.** Cloudflare Pages and Vercel give a unique URL per branch/PR for free. Hostinger doesn't. Workarounds:
  - Use a subdomain on the same plan (`preview.fenia.com.ar`) and deploy `dev` branch there via a parallel GitHub Actions job. This is doable but adds a moving part.
  - Or live without it and rely on `npm run preview` locally + screenshots for owner approval ([AGENTS.md § PROPOSE-THEN-EXECUTE](../../AGENTS.md)).
  - **Decision:** defer preview-subdomain setup; revisit if regressions reach production.
- **No atomic rollback.** "Promote previous deploy" doesn't exist in shared hosting. To roll back: re-run a prior GitHub Actions run (with `workflow_dispatch`) and let it overwrite. Slower (~2-3 minutes), but workable. Documented in [`docs/ai-context/deployment.md`](../ai-context/deployment.md).
- **Edge inferior in Latin America.** Hostinger has a single datacenter per region (likely São Paulo for this account). Compared to Cloudflare's hundreds of PoPs, real LCP in Lima/Bogotá/Mexico City will be measurably worse. **Mitigation:** acceptable given the audience is predominantly Argentina (latency to São Paulo is OK).
- **Larger blast radius on credential leak.** FTPS credentials in GitHub Secrets give full write access to the document root. Mitigation: separate FTP account scoped to `/public_html/` if Hostinger supports it; rotate credentials if a leak is suspected.
- **Performance ceiling lower.** Shared hosting bandwidth/CPU is capped by neighbor noise. Acceptable at the site's traffic; revisit if a campaign drives a spike.
- **CSP technical debt remains.** Moving from `_headers` to `.htaccess` is a sideways move on this front — `'unsafe-inline'` is still required for the same reasons.
- **No native HTTP/3.** LiteSpeed supports HTTP/3 in newer versions; depends on Hostinger's deployment. Not catastrophic at this scale.

**Migration path away from Hostinger (if ever needed):**

- Still trivial. The Astro `dist/` output is portable. The `.htaccess` translates back to Cloudflare `_headers` / Vercel `vercel.json` in ~30 minutes of mechanical rewriting. Vendor lock-in: **moderate** (higher than CF Pages because of `.htaccess` + FTP creds vs git, but still low compared to anything stateful).

## Alternatives considered (re-examined under the override)

| Option | Why not (given the owner override) |
|---|---|
| Stay on Vercel | Owner pushed back specifically; not a viable answer to "remove Vercel" |
| Cloudflare Pages (the recommendation from [ADR-018](./ADR-018-migrate-to-cloudflare-pages.md)) | Owner override — operational consolidation valued over technical optimality |
| Hostinger + Cloudflare DNS in front | Reduces some Hostinger weaknesses (edge, DDoS) but reintroduces a second provider — exactly what the owner is removing. Available later if performance becomes a real problem |
| Move *also* the email off Hostinger | Pointless during a prepaid plan; revisit at plan renewal |

## Follow-ups

- [ADR-023](./ADR-023-htaccess-hosting-config.md) — `.htaccess` for headers / cache / 404.
- [ADR-024](./ADR-024-observability-on-hostinger.md) — observability baseline rebaselined for Hostinger.
- [ADR-020](./ADR-020-ci-minimal-github-actions.md) — extended with FTPS deploy job (same-day update; see "Updated 2026-05-26" section).
- [PENDING.md](../../PENDING.md) — items blocked on owner (plan tier confirmation, FTPS credentials).
- [docs/audit/2026-05-26-hosting-pivot-to-hostinger.md](../audit/2026-05-26-hosting-pivot-to-hostinger.md) — narrative record of the pivot.
