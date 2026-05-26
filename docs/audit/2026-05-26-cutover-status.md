# Audit — 2026-05-26 — Cutover status snapshot at session close

> **Frozen handoff document.** Do not edit; create a successor audit when the cutover is fully closed.
> Audience: the next AI / developer who picks up the work. Read this first, then `PENDING.md`.

## TL;DR for the next operator

The Vercel → Hostinger cutover is **technically complete server-side**. The site is verified live on Hostinger with all security headers active. Three small actions remain to formally close the migration:

1. **The dev (Matías) needs to flush local DNS** and visually confirm the site loads from Hostinger in their own browser.
2. **Pause the Vercel project** (requires Vercel access — `PENDING.md` #3).
3. **Observe 7 days**, then delete Vercel.

Everything else is roadmap, not blocker.

---

## Independent verification at session close (2026-05-26 19:17 UTC)

Running from outside the user's local network, forcing the resolver to Hostinger's IP:

```sh
curl -I --resolve fenia.com.ar:443:147.79.84.112 https://fenia.com.ar/
```

Returned:

```
HTTP/1.1 200 OK
Server: LiteSpeed
platform: hostinger
panel: hpanel
Content-Type: text/html
Content-Length: 49323
Last-Modified: Tue, 26 May 2026 18:56:05 GMT
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; media-src 'self'; connect-src 'self'; frame-src 'self' https://tally.so https://*.tally.so; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()
X-Frame-Options: DENY
Cache-Control: public, max-age=3600
alt-svc: h3=":443"; ma=2592000, h3-29=":443"; ma=2592000, ...
```

**This proves:** the site is served by Hostinger LiteSpeed, the `public/.htaccess` is being read and applied (all security headers are present), the certificate is valid via HTTPS, HTTP/3 is offered, and the build artifact matches the local `dist/` (`Content-Length: 49323`).

## DNS propagation at session close

DNSChecker.org showed **12 of 13 international resolvers** returning `147.79.84.112` for `fenia.com.ar`. The single laggard (Amsterdam OpenTLD BV) is irrelevant — those resolvers update on their own clock and the rest of the internet already sees Hostinger.

## Architecture summary

| Layer | Provider | Notes |
|---|---|---|
| Web hosting | **Hostinger** (LiteSpeed shared, server `srv1665.hstgr.io`) | IP `147.79.84.112` |
| DNS | **Hostinger** | Nameservers `aurora.dns-parking.com` / `nebula.dns-parking.com` |
| Domain registrar | **Hostinger** | 4-year prepay (ARS 200K, exact tier still pending — `PENDING.md` #2) |
| Email | **Hostinger mail** | MX records to `mx1.hostinger.com` / `mx2.hostinger.com`, DKIM via `hostingermail-{a,b,c}._domainkey` CNAMEs, SPF and DMARC TXT |
| Source control | **GitHub** | `github.com/FabFar12/fenia-web` |
| CI / deploy | **GitHub Actions** | `.github/workflows/ci.yml` — quality gate on every PR/push, FTPS deploy on push to `main` |

## Commits added in this session (in order)

```
85a9262  feat(hosting): pivot to Hostinger-only (ADR-022)
064ac6f  chore(repo): hardening — PR template, dependabot, editorconfig, checklists
ae7266d  fix(ci): FTPS security strict → loose for IP-based connect
6e869cf  fix(ci): include hidden files in dist/ artifact so .htaccess survives
```

Plus the merge commits `1f10036`, `da7d79b`, `56a10bb` on `main` produced by `--no-ff` merges of `dev`.

ADRs documented during the session: **022, 023, 024**. Superseded same-day: **002, 018, 019, 021**. Extended same-day: **020** (CI gained the deploy job).

## What's actually live right now

- `https://fenia.com.ar/` → served from Hostinger, HTTPS forced, all security headers from `public/.htaccess`.
- `https://www.fenia.com.ar/` → CNAME to `fenia.com.ar`, same content.
- Email on `@fenia.com.ar` → Hostinger mail, MX records untouched.
- Vercel project → still receiving auto-deploys but DNS no longer points there. **Inert but billing/running**.

## What remains to close the cutover

### 🔴 1. Dev's local DNS cache (Matías)
The dev's own Chrome + Windows are still resolving `fenia.com.ar` to the old Vercel IP because of local cache. The internet at large has already updated. Steps to flush, in order:

```
ipconfig /flushdns                                 (CMD as admin)
chrome://net-internals/#dns       → Clear host cache
chrome://net-internals/#sockets   → Flush socket pools
```

Then close all tabs of `fenia.com.ar`, open a fresh incognito window, visit `https://fenia.com.ar/`, open DevTools → Network → Response headers. Expected: `Server: LiteSpeed`, `Remote Address: 147.79.84.112:443`.

**Until this is confirmed visually from the dev's browser, the cutover is not formally closed.** Independent verification (the `curl --resolve` above) confirms the server is correct, but the dev's last-mile confirmation matters for the operational record.

### 🔴 2. Pause Vercel
Owner action — requires inviting the dev to the Vercel project OR sending screenshots of Build Settings, Environment Variables, Domains, Functions Region. Then:

1. Vercel dashboard → `fenia-web` project → Settings → Git → **Disconnect** or **Pause auto-deploys**.
2. Do **NOT delete** the project yet — leave one week as a safety net.
3. If the plan is Hobby, no further action. If Pro, schedule cancellation for after the 1-week observation.

See `PENDING.md` #3.

### 🟡 3. Observability baseline (this week)
[ADR-024](../adr/ADR-024-observability-on-hostinger.md). Three pieces, all free or cheap:

- **UptimeRobot** — sign up, add monitor `HEAD https://fenia.com.ar/` every 5 min, alert to dev email after 2 consecutive failures. 10 min of work, free tier.
- **Sentry browser SDK** — sign up, create a project, get DSN, add as `PUBLIC_SENTRY_DSN` GitHub Secret + Vercel-style `.env`, initialize in `src/layouts/Layout.astro`. Bundle costs ~30 KB gzipped. Free tier: 5K errors/month.
- **Plausible Cloud** — sign up, add `fenia.com.ar`, get script tag, inject behind `PUBLIC_PLAUSIBLE_DOMAIN` env var. USD 9/month after trial.

When you add Plausible and Sentry, also **update the CSP in `public/.htaccess`** to extend:

- `script-src` to include `https://plausible.io`
- `connect-src` to include `https://plausible.io` and `https://*.ingest.sentry.io`

### 🟡 4. Dependabot PRs
Cargar `.github/dependabot.yml` causó cinco PRs automáticos abrir el mismo día. Estado a revisar en `https://github.com/FabFar12/fenia-web/pulls`:

- `chore(deps-dev): Bump typescript from 5.9.3 to 6.0.3` — major bump, no autorizado por la `ignore` rule. Revisar release notes y mergear si compatible.
- `chore(deps): Bump the minor-and-patch group with 8 updates` — minor/patch grouped. **Mergear** después de leer el diff y correr CI.
- `chore(deps): Bump actions/checkout from 4 to 6` — major GH action. Generalmente safe. Verificar release notes, mergear si OK.
- `chore(deps): Bump SamKirkland/FTP-Deploy-Action from 4.3.5 to 4.4.0` — minor bump. **Mergear** después de verificar que no rompe el deploy.
- `chore(deps): Bump actions/setup-node from 4 to 6` — major GH action. Verificar release notes.

Workflow para cada uno: open PR → review files changed → if it's a major bump for a load-bearing lib, read release notes for breaking changes → if CI is green and changes look OK, **merge to dev**, never directly to main.

### 🟢 5. Eliminar Vercel (después de 7 días)
Una vez que UptimeRobot tenga 7 días consecutivos verdes y no haya errores en Sentry:

1. Vercel dashboard → project Settings → bottom of page → **Delete project**.
2. If on a paid plan, cancel the subscription separately.
3. Update `docs/ai-context/deployment.md` to remove any remaining "Vercel as safety net" language.

## Future roadmap (no urgency, post-cutover)

### 🟢 6. Switch FTPS to `security: strict` with a real hostname
We discovered during the cutover that Hostinger's canonical hostname for this server is **`srv1665.hstgr.io`** (visible in the File Manager URL: `srv1665-files.hstgr.io`). The certificate covers `*.hstgr.io`. We could:

1. Change the GitHub Secret `HOSTINGER_FTP_HOST` from `147.79.84.112` to `srv1665.hstgr.io` (or whatever the actual FTP server FQDN is — verify in hPanel → FTP Accounts, the listed hostname).
2. Change `security: loose` back to `security: strict` in `.github/workflows/ci.yml`.
3. Test by triggering a deploy.

If it fails, revert. Documented in `ADR-022` and `ADR-020`'s same-day update section. ~10 minutes of work.

### 🟢 7. Optimize the intro video (2.4 MB → ~800 KB)
The hero `intro-fenia.mp4` is 63% of total build weight. Re-encode to H.265/AV1 + `<video poster="..." preload="none">`. Requires owner approval (PROPOSE-THEN-EXECUTE per AGENTS.md — it's a visible asset). Draft an ADR proposing the change before touching the file. Tooling: ffmpeg locally.

### 🟢 8. Tighten CSP — remove `'unsafe-inline'`
The CSP in `public/.htaccess` currently has `'unsafe-inline'` for both `script-src` and `style-src`. This is **documented technical debt** in [ADR-023](../adr/ADR-023-htaccess-hosting-config.md), linked to:

- The codebase still using inline `style="..."` attributes ([ADR-004 Tailwind migration](../adr/ADR-004-styling-strategy.md) is the long-term fix).
- Astro inlining small scripts (astro-island bootstrap, View Transitions, the `[data-service-slug]` handler in `index.astro`).

When ADR-004 closes (Tailwind utilities replace inline styles), AND the inline scripts are externalised, the CSP can be tightened. New ADR required when this is tackled.

### 🟢 9. Add Lighthouse CI
A GitHub Actions workflow that runs Lighthouse against the build output (or a deploy preview) and fails the PR if LCP > 2.5s, CLS > 0.1, etc. Not strictly necessary but valuable for catching regressions. Tier B work mentioned during the session, postponed.

### 🟢 10. Staging subdomain
If Hostinger Business supports subdomain creation:

1. Create `staging.fenia.com.ar` in hPanel.
2. Modify `.github/workflows/ci.yml` to add a third job `deploy-staging` that deploys `dev` branch to that subdomain via FTPS.
3. Restores per-PR/per-branch preview deploys, eliminating the biggest functional gap vs. Vercel.

## Inherited PENDING items (not blockers)

These are owner-side decisions, unrelated to the cutover. See `PENDING.md` for details.

- #4 Real product catalogue
- #5 Real testimonials (via Tally)
- #6 Real Hero KPIs
- #7 Products section as commerce or directory
- #8 "Who's behind" section
- #9 Privacy policy + ToS (Ley 25.326)
- #10 Custom favicon set
- #11 OG image

## Important context for the next AI

### Decisions you should NOT revert

- **Hosting on Hostinger** — owner override (Fab) of an earlier recommendation. Documented in [ADR-022](../adr/ADR-022-pivot-to-hostinger-only.md). Treat as fixed unless Fab himself revisits it.
- **`security: loose` for FTPS** — required because the deploy connects by IP; the cert covers `*.hstgr.io` only. Documented in [ADR-022](../adr/ADR-022-pivot-to-hostinger-only.md). Can be tightened later (item #6 above), not blindly reverted.
- **CSP includes `'unsafe-inline'`** — known debt, fix path documented in [ADR-023](../adr/ADR-023-htaccess-hosting-config.md). Do **not** drop `'unsafe-inline'` until both Tailwind migration ([ADR-004](../adr/ADR-004-styling-strategy.md)) AND inline-script externalisation are done.
- **`include-hidden-files: true`** in the upload-artifact step — critical so `.htaccess` survives the artifact round-trip. Removing it = silent breakage of all security headers on the next deploy.
- **The DNS apex `A` record** points to `147.79.84.112`. **MX, SPF, DKIM, DMARC, and the Google verification CNAME `53cdkyvq73ze` are sacred** — touching them breaks email.

### Things the user (Matías) values

See the memory directory `C:\Users\Cinesa\.claude\projects\c--Users-Cinesa-OneDrive-Escritorio-fenia-web\memory\` for the persistent context:

- Decisive recommendations over balanced buffets.
- Sees no value in PRs without a reviewer (single-dev workflow).
- Authorised pushes to `dev` autonomously; pushes to `main` require explicit per-event authorisation.
- Non-technical owner (Fab) makes account-level decisions; Matías executes.

### Where credentials live

| Where | What |
|---|---|
| GitHub repo Secrets (Settings → Secrets and variables → Actions) | `HOSTINGER_FTP_HOST` (= `147.79.84.112`), `HOSTINGER_FTP_PORT` (= `21`), `HOSTINGER_FTP_USER` (= `u916694034.deploy`), `HOSTINGER_FTP_PASSWORD` (set, rotated mid-session), `HOSTINGER_FTP_REMOTE_DIR` (= `/`) |
| Hostinger hPanel → FTP Accounts | The `deploy` FTP user, scoped to `/public_html/`. Password is the one stored in GitHub Secrets; rotate if leaked. |
| Cloudflare / Plausible / Sentry | Not yet created. Will be needed for [ADR-024](../adr/ADR-024-observability-on-hostinger.md) work. |
| Vercel project | Owner (Fab) has access; dev access still pending (`PENDING.md` #3). |

### Gotchas you'll hit if you don't read the ADRs

- The FTP cuenta is jailed to `/home/u916694034/domains/fenia.com.ar/public_html`. Setting `server-dir` to an absolute path nests the files (we hit this — files ended up in `public_html/home/u916694034/.../public_html/`). Always `/`.
- `upload-artifact@v4` strips dotfiles by default. The line `include-hidden-files: true` in `.github/workflows/ci.yml` is what makes `.htaccess` survive the round-trip. Don't remove it.
- The cert mismatch (TLS strict failing for IP) is the reason `security: loose`. Don't flip back to strict until you also switch `HOSTINGER_FTP_HOST` to a hostname under `*.hstgr.io`.
- Cloudflare DNS is **not** in the stack. Only Hostinger DNS, by owner mandate. Don't propose adding Cloudflare in front.

## Acceptance criteria for "cutover complete"

- [ ] `https://fenia.com.ar/` returns `Server: LiteSpeed` + `platform: hostinger` from any independent vantage point.
- [ ] `https://fenia.com.ar/` returns all 6 security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
- [ ] DNS propagation > 90% globally.
- [x] Email on `@fenia.com.ar` still works (MX/SPF/DKIM/DMARC unchanged).
- [ ] The dev (Matías) has visually confirmed Hostinger is serving from their own browser after a DNS flush.
- [ ] Vercel project is paused.
- [ ] UptimeRobot monitor is in place.
- [ ] No JS errors in production (verified once Sentry is wired).
- [ ] 7 days of stable operation.
- [ ] Vercel project deleted.

The first three are done. The rest are the work remaining.
