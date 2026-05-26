# ADR-024 — Observability baseline on Hostinger (no Cloudflare)

## Status
Accepted (2026-05-26). Supersedes [ADR-021](./ADR-021-observability-baseline.md). Required by [ADR-022](./ADR-022-pivot-to-hostinger-only.md). Implementation deferred until after the Vercel → Hostinger cutover stabilises.

## Context

[ADR-021](./ADR-021-observability-baseline.md) anchored the analytics layer on **Cloudflare Web Analytics** (free, server-side, no cookie banner). The owner pivot to Hostinger-only ([ADR-022](./ADR-022-pivot-to-hostinger-only.md)) removes Cloudflare from the stack entirely. We need to rebaseline what fills the gap.

Constraints unchanged from ADR-021:

- **Privacy-first** — no cookie banner; aligns with brand positioning and Ley 25.326 (AR) sensibilities.
- **Free or near-free** at this site's scale.
- **Operable by the dev alone** — no infrastructure for the owner.
- **Portable** — doesn't lock observability into the hosting provider's ecosystem (lesson learned from Vercel Analytics disappearing in the previous pivot).

What changes:

- No Cloudflare Web Analytics → need an alternative.
- Hostinger does provide a basic AWStats / Hotlink statistics view in hPanel — useful as ambient signal but not a substitute (no per-page detail, no real-time, scraped from raw logs).

## Decision

**Three-layer observability baseline, all free at this site's scale, all hosting-provider-independent**:

1. **Analytics → [Plausible Cloud](https://plausible.io)** *(USD 0 free trial, then USD 9/mo Starter)* or **self-hosted Plausible**.
   - **Recommendation:** Plausible Cloud for at least the first 3 months. Self-hosted is technically possible (Plausible runs in Docker; Hostinger Business+ supports Docker), but the operational cost (DB backups, security patches) is not worth the USD 9/mo for one dev.
   - **Why Plausible vs alternatives:**
     - **Privacy-first**, no cookies, no cross-site tracking → no consent banner.
     - **Custom events** supported natively (`whatsapp_click`, `testimonial_form_submitted`, …) — this is what Cloudflare Web Analytics lacks.
     - **Lightweight** (~1 KB script).
     - **Ley 25.326 / GDPR / LGPD compliant** out of the box.
   - Script tag added in `src/layouts/Layout.astro` behind `import.meta.env.PUBLIC_PLAUSIBLE_DOMAIN` — when the env var is unset (local dev, preview deploys without secrets), no tag is emitted.

2. **Error tracking → [Sentry browser SDK](https://sentry.io)** *(free tier: 5K errors/month)*.
   - Unchanged from [ADR-021](./ADR-021-observability-baseline.md). Sentry is hosting-agnostic.
   - DSN injected at build via `PUBLIC_SENTRY_DSN`. Inert when unset.
   - `tracesSampleRate: 0` (errors only, no performance traces).

3. **Uptime monitoring → [UptimeRobot](https://uptimerobot.com)** *(free tier: 50 monitors, 5-min interval)*.
   - Unchanged from [ADR-021](./ADR-021-observability-baseline.md). External vantage point catches "Hostinger is up but the site is down" failure modes (e.g., a broken `.htaccess` returns 500).
   - Single HTTP/HTTPS monitor on `https://fenia.com.ar/`. Alert to dev email on 2 consecutive failures.

4. **Web Vitals** — deferred. The `web-vitals` library reporting RUM via Plausible custom events (`web_vital_lcp`, `web_vital_cls`, `web_vital_inp`) is the future path. Out of scope for the initial baseline.

### Environment variables (Cloudflare-era reference replaced)

`.env.example` updated accordingly:

```
PUBLIC_PLAUSIBLE_DOMAIN=fenia.com.ar
PUBLIC_SENTRY_DSN=https://xxx@xxx.ingest.sentry.io/xxx
PUBLIC_TALLY_TESTIMONIAL_FORM_URL=https://tally.so/r/xxxxxx
```

All `PUBLIC_*`-prefixed because they are exposed at build time to client code (Astro convention).

### CSP impact

Adding Plausible + Sentry will require expanding `Content-Security-Policy` in [`public/.htaccess`](../../public/.htaccess) (see [ADR-023](./ADR-023-htaccess-hosting-config.md)):

- `script-src` adds `https://plausible.io` (or self-hosted origin).
- `connect-src` adds `https://plausible.io` *and* `https://*.ingest.sentry.io`.

Both modifications land in the same PR that initialises the SDKs. Until then, the CSP stays at the current baseline.

## Consequences

**Easier:**

- **Visibility into traffic patterns** with custom events — the missing feature in Cloudflare Web Analytics.
- **JS errors trigger an email** instead of silent breakage.
- **Site-down detection in <10 minutes** via external vantage point.
- **Hosting-agnostic stack** — future host changes don't break observability.
- **Operational cost ≤ USD 9/month** (Plausible Cloud) or USD 0 (self-hosted on Hostinger Business+).

**Harder / accepted trade-offs:**

- **Plausible Cloud costs money** (USD 9/mo) — was free under Cloudflare Web Analytics. Justified by custom events and portability.
- **Self-host Plausible alternative** is available but adds an ongoing operational burden incompatible with single-dev simplicity. Not chosen.
- **Adding Plausible + Sentry expands the CSP**, slightly weakening the policy. Acceptable given both are first-party-style integrations from reputable vendors.
- **Three dashboards** (Plausible, Sentry, UptimeRobot) — mitigated by alerts (push to dev email) rather than dashboards (pull).

## Alternatives considered

| Option | Why not |
|---|---|
| Google Analytics 4 | Requires cookie consent under EU/AR norms → cookie banner → degraded brand UX |
| Stick with Hostinger's hPanel AWStats only | No real-time, no custom events, primitive UI, not portable |
| Umami self-host on Hostinger Business+ | Free, but adds DB + patching burden incompatible with single-dev simplicity |
| GoatCounter Cloud | Similar to Plausible, marginally cheaper, but smaller ecosystem and fewer custom-event examples |
| Vercel Analytics | Disappears with the Vercel cutover (and a previous lesson) |
| LogRocket / Hotjar (session replay) | Heavyweight, privacy-fraught, overkill for a 3-page site |
| New Relic / Datadog Browser | Priced for scale we don't have |
| Logflare / Better Stack Logtail | Useful for server logs we don't have (the site is static) |

## Follow-ups

- Implementation PR triggered after the Hostinger cutover is stable (1 week of green UptimeRobot checks on the Hostinger origin).
- Web Vitals integration via `web-vitals` + Plausible custom events is its own future ADR.
- CSP tightening (post-[ADR-004](./ADR-004-styling-strategy.md) closure) is its own future ADR.
