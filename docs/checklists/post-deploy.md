# Post-deploy checklist — first 7 days after Hostinger cutover

> Run through this in the days after the first production deploy. Catches regressions before the Vercel project is deleted.
> Companion: [pre-deploy.md](./pre-deploy.md). Authoritative deploy flow: [docs/ai-context/deployment.md](../ai-context/deployment.md).

## First hour

- [ ] `https://fenia.com.ar/` loads the new content (visual check).
- [ ] HTTPS is enforced — `http://fenia.com.ar/` 301-redirects to `https://`.
- [ ] `curl -I https://fenia.com.ar/` confirms all security headers are present:
  - [ ] `Strict-Transport-Security: max-age=63072000; includeSubDomains; preload`
  - [ ] `Content-Security-Policy: …` (starts with `default-src 'self'`)
  - [ ] `X-Content-Type-Options: nosniff`
  - [ ] `X-Frame-Options: DENY`
  - [ ] `Referrer-Policy: strict-origin-when-cross-origin`
  - [ ] `Permissions-Policy: geolocation=(), camera=(), …`
- [ ] `/404` returns the custom Astro 404 (cyan branding), not a Hostinger default page.
- [ ] `/sitemap-index.xml` accessible.
- [ ] `/robots.txt` accessible and includes the `Sitemap:` directive.
- [ ] Browser DevTools console: no CSP violations on `/`, `/dejanos-tu-testimonio`, `/404`.
- [ ] 3D neural network renders in the hero section.

## First 24 hours

- [ ] UptimeRobot monitor configured: `HEAD https://fenia.com.ar/` every 5 min, alert to dev email after 2 consecutive failures.
- [ ] UptimeRobot reports 100% uptime over the window.
- [ ] Mobile visit (or Lighthouse mobile preset) confirms LCP < 4 s on simulated 4G.
- [ ] At least one WhatsApp CTA click verified end-to-end (opens WhatsApp chat with prefilled message).
- [ ] No Hostinger emails about resource limits / suspensions.

## First week

- [ ] Sentry browser SDK initialized:
  - [ ] `PUBLIC_SENTRY_DSN` set in GitHub Actions secrets and reflected in production.
  - [ ] Confirmed by inducing a `console.error("test-sentry")` in a deployed preview and seeing the event in Sentry.
- [ ] Plausible (or chosen analytics) firing on every page:
  - [ ] `PUBLIC_PLAUSIBLE_DOMAIN` set.
  - [ ] First page-views visible in the Plausible dashboard.
  - [ ] No new cookie consent banner introduced.
- [ ] CSP expanded in `public/.htaccess` to allow the new endpoints:
  - [ ] `script-src` includes Plausible origin.
  - [ ] `connect-src` includes Plausible + `https://*.ingest.sentry.io`.
- [ ] No new Sentry errors above "info" level for 5 consecutive days.
- [ ] Lighthouse on `fenia.com.ar/` within 5 points of the staging baseline.
- [ ] Owner has been told the cutover is stable.
- [ ] **Vercel project deleted** (only AFTER the above are green; this is the last step).

## Investigate immediately if

- A CSP violation appears in DevTools console or Sentry.
- LCP regresses by > 0.5 s versus the staging baseline.
- UptimeRobot reports > 0.5 % downtime in any 24h window.
- A user reports the site is "broken" or "lento" on any path / device.
- Hostinger sends a "resource usage exceeded" notification.

## Rollback procedure (if something is on fire)

1. Open [GitHub Actions](https://github.com/FabFar12/fenia-web/actions/workflows/ci.yml).
2. Find the last green deploy run on `main`.
3. Click *Re-run all jobs*. ETA 2–3 min.
4. While re-deploying, message the owner that a rollback is in progress.
5. Once stable, open an issue describing what caused the regression.
6. Fix forward in a new commit on `dev`; do not push a panic commit straight to `main`.

For severe failures (corrupted file structure on Hostinger): manually upload a known-good `dist/` from a tagged release via *hPanel → File Manager*. See [docs/ai-context/deployment.md § Rollback strategy](../ai-context/deployment.md).
