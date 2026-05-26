# ADR-021 — Observability baseline: analytics + error tracking + uptime (Cloudflare-Web-Analytics-anchored)

## Status
**Superseded by [ADR-024](./ADR-024-observability-on-hostinger.md) (2026-05-26, same day).** The analytics layer anchored on Cloudflare Web Analytics is irrelevant under the [ADR-022](./ADR-022-pivot-to-hostinger-only.md) Hostinger pivot. The Sentry + UptimeRobot components carry over verbatim; the analytics layer is rebaselined onto Plausible in ADR-024.

Originally: Accepted (2026-05-26). Implementation deferred until [ADR-018](./ADR-018-migrate-to-cloudflare-pages.md) cutover is complete.

## Context

Observability today is **zero**. Quoted from [docs/audit/2026-05-19-initial-audit.md](../audit/2026-05-19-initial-audit.md):

> Observabilidad: 0 / 10.
> No analytics, no error tracking, no Web Vitals.

That means:

- We do not know how many people visit the site, where they come from, or what they do.
- We do not know if `fenia.com.ar` is up right now from our perspective, let alone from an outside vantage point.
- A JS error in production is invisible. The 3D neural-network island, the View Transitions hook, the magnetic CTAs — any of them could be silently throwing in Safari iOS 17 and we'd never know.

The hosting migration ([ADR-018](./ADR-018-migrate-to-cloudflare-pages.md)) removes Vercel Analytics / Speed Insights as a fall-back ("we'll just turn it on someday"). We need a deliberate, portable baseline that doesn't depend on any single host.

Constraints:

- **Privacy-first.** [ADR-001](./ADR-001-tech-stack.md) and the brand's positioning preclude GA4 unless we want to ship a cookie banner — not the user experience the brand wants.
- **Free or near-free.** This is a personal-scale consulting site; budget is in the low double digits / month at most.
- **Operable by the dev alone.** The owner does not maintain dashboards.
- **Compliant with Ley 25.326** (Argentina) — Plausible/Cloudflare Web Analytics meet this trivially; GA4 requires consent infrastructure.

## Decision

**Adopt a three-layer observability baseline**, all free at our volume:

1. **Analytics** — **Cloudflare Web Analytics** (free, privacy-friendly, no cookie banner, server-side hit counting).
   - Activated from the Cloudflare dashboard once the domain is on Cloudflare Pages.
   - Captures: page views, referrers, top pages, country, browser. No personal data, no cross-site tracking.
   - **Fallback option**: if we later need conversion events (`whatsapp_click`, `testimonial_form_submitted`), switch the analytics layer to Plausible Cloud (~USD 9/mo) or self-hosted Plausible. Cloudflare Web Analytics does not support custom events at the time of writing.

2. **Error tracking** — **Sentry browser SDK** (free tier: 5K errors/month, sufficient for this traffic).
   - DSN injected at build via `PUBLIC_SENTRY_DSN` environment variable (already documented in `.env.example`).
   - Initialized in `src/layouts/Layout.astro` with `tracesSampleRate: 0` (errors only, no performance traces — saves quota and avoids noise).
   - When `PUBLIC_SENTRY_DSN` is unset (e.g., local dev, preview branches without secret), Sentry is *not* initialized — the SDK is bundled but inert.

3. **Uptime monitoring** — **UptimeRobot** (free tier: 50 monitors, 5-minute interval).
   - Single monitor: `HEAD https://fenia.com.ar/` every 5 minutes.
   - Alert via email to the dev when 2 consecutive checks fail.
   - **Why outside the host:** Cloudflare's own health checks live on the same infrastructure; an outside vantage point catches the failure mode where Cloudflare reports green but real users see red.

4. **Web Vitals** — **deferred** to a follow-up ADR. The `web-vitals` library + reporting to either Cloudflare Web Analytics (when custom events land) or Plausible custom events is the planned path. Today, manual Lighthouse runs from `npm run preview` are sufficient.

### Environment variables

Already documented in [.env.example](../../.env.example). Reproduced here for reference:

```
PUBLIC_SENTRY_DSN=https://xxx@xxx.ingest.sentry.io/xxx
```

Set in the Cloudflare Pages project Settings → Environment Variables for `Production` and `Preview` deploys. Local `.env` for the dev (gitignored) optional — most JS errors reproduce in preview, not local.

### CSP impact

Adding Sentry requires expanding [ADR-019](./ADR-019-version-hosting-config.md)'s CSP `connect-src` to include the Sentry ingest endpoint:

```
connect-src 'self' https://*.ingest.sentry.io
```

Cloudflare Web Analytics's beacon (`https://static.cloudflareinsights.com/beacon.min.js`) is auto-injected by Cloudflare on the edge when Web Analytics is enabled and does **not** require manual CSP whitelisting (Cloudflare modifies the response *after* our `_headers` rules are applied — verified behavior). If we ever move analytics off Cloudflare, this assumption breaks and the CSP must be updated.

## Consequences

**Easier:**

- **Visibility into traffic patterns** — page views by section / referrer / country, useful when deciding what content to invest in.
- **JS errors trigger an email**, not silent breakage.
- **Site-down detection in <10 minutes** instead of "the next time someone visits and tells us".
- **No cookie banner required** — privacy-by-design, fits the brand.
- **Cost ≈ USD 0/month** at our traffic.

**Harder / accepted trade-offs:**

- **Cloudflare Web Analytics has no custom events today.** Conversion-funnel analysis (`button_click`, `form_submit`) is *not* possible until we add Plausible Cloud or self-hosted Plausible. Acceptable for the marketing-page MVP.
- **Sentry adds ~30 KB gzipped** to the bundle. Tradeoff: error visibility > +30 KB. Mitigated by lazy-loading Sentry (`@sentry/browser` supports a thin loader script).
- **Three dashboards to check.** Mitigation: alerts route to the dev's email; dashboards are pull, not push.

## Alternatives considered

| Option | Why not |
|---|---|
| Google Analytics 4 | Requires cookie consent under EU/AR privacy norms; tracks cross-site; misaligned with brand |
| Plausible Cloud as the primary | ~USD 9/mo today; revisit once we need custom events. Right call for Phase 2, not Phase 1 |
| Self-hosted Plausible | Requires a server; violates the "operable by one dev with no infrastructure" principle |
| Vercel Analytics / Speed Insights | Disappears with the Vercel cutover |
| Cloudflare Web Analytics + no error tracking | Leaves the worst visibility gap (silent JS errors) wide open |
| Cloudflare Web Analytics + LogRocket / Hotjar | Session-replay tools are heavyweight, privacy-fraught, and overkill for a 3-page site |
| Datadog / New Relic | Pricing model assumes far larger scale; absurd for this site |

## Follow-ups

- Implementation PR after [ADR-018](./ADR-018-migrate-to-cloudflare-pages.md) cutover is stable (1 week of green Uptime checks on Cloudflare).
- Web Vitals reporting (`web-vitals` library) is its own future ADR.
- Custom-event analytics (Plausible Cloud switch) is a separate future ADR triggered by a real product question (e.g., "which WhatsApp CTA converts better").
