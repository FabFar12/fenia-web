# Deployment — Hostinger (web + DNS + domain + email) + GitHub Actions FTPS

> Last updated: 2026-05-26 (post-pivot). Single-provider architecture: everything web-facing runs on Hostinger.
> Active ADRs: [ADR-022](../adr/ADR-022-pivot-to-hostinger-only.md), [ADR-023](../adr/ADR-023-htaccess-hosting-config.md), [ADR-020](../adr/ADR-020-ci-minimal-github-actions.md), [ADR-024](../adr/ADR-024-observability-on-hostinger.md).
> Historical (superseded): [ADR-002](../adr/ADR-002-hosting-vercel.md), [ADR-018](../adr/ADR-018-migrate-to-cloudflare-pages.md), [ADR-019](../adr/ADR-019-version-hosting-config.md), [ADR-021](../adr/ADR-021-observability-baseline.md). Pivot record: [docs/audit/2026-05-26-hosting-pivot-to-hostinger.md](../audit/2026-05-26-hosting-pivot-to-hostinger.md).

## Topology — who does what

| Layer | Provider | What it does |
|---|---|---|
| Web hosting | **Hostinger** (shared, LiteSpeed assumed) | Serves `https://fenia.com.ar/` from `dist/` uploaded via FTPS |
| DNS | **Hostinger** | Resolves `fenia.com.ar` and `www.fenia.com.ar` to the Hostinger origin |
| Domain registrar | **Hostinger** | Owns the `fenia.com.ar` registration (prepaid 4 years) |
| Email | **Hostinger** | Mailboxes on the `@fenia.com.ar` domain |
| Source control | **GitHub** | `github.com/FabFar12/fenia-web` |
| CI + Deploy | **GitHub Actions** | `astro check` + `npm run build` on every PR; FTPS upload of `dist/` to Hostinger on push to `main`. See [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) and [ADR-020](../adr/ADR-020-ci-minimal-github-actions.md) |
| Analytics | Plausible *(planned, post-cutover)* | Page views + custom events. See [ADR-024](../adr/ADR-024-observability-on-hostinger.md) |
| Error tracking | Sentry browser SDK *(planned, post-cutover)* | JS error capture |
| Uptime | UptimeRobot *(planned, post-cutover)* | External liveness check every 5 min |

## Production

- **URL**: `https://fenia.com.ar/`
- **Hosting**: Hostinger shared (LiteSpeed assumed; the `.htaccess` is Apache-syntax and works on both).
- **Build command** (in CI): `npm run build` (`astro build`).
- **Output directory** (in CI): `dist/`.
- **Document root** (on Hostinger): typically `/public_html/` or `/domains/fenia.com.ar/public_html/`. Confirm the exact path in hPanel → Files → File Manager and set it as `HOSTINGER_FTP_REMOTE_DIR` in GitHub Secrets.
- **Node version (in CI)**: read from `engines.node` in [package.json](../../package.json) (≥22.12) by `actions/setup-node`.
- **Hosting config**: [`public/.htaccess`](../../public/.htaccess) — copied into `dist/.htaccess` at build, uploaded to the document root by the deploy job. See [ADR-023](../adr/ADR-023-htaccess-hosting-config.md).

## Deploy flow (every push to `main`)

```
git push origin main
  │
  ▼
GitHub Actions ── job 1: check-and-build ──────────────────────┐
  │   npm ci                                                    │
  │   npm run check                                             │
  │   npm run build → dist/                                     │
  │   upload-artifact dist/                                     │
  ▼                                                              │
job 2: deploy (needs: check-and-build)                          │
  │   download-artifact dist/                                   │
  │   validate HOSTINGER_FTP_* secrets present                  │
  │   FTPS upload dist/* → ${HOSTINGER_FTP_REMOTE_DIR}/         │
  │   (diff-sync via .ftp-deploy-sync-state.json)               │
  ▼                                                              │
Hostinger LiteSpeed serves the new files immediately            │
  (.htaccess reloads on next request; no restart needed)        ▼
                                                          https://fenia.com.ar/
```

Pull requests run only **job 1** (check-and-build). They never deploy.

## Required GitHub Secrets

Configure at *Repo Settings → Secrets and variables → Actions → New repository secret*. Until **all** of these are set, deploys will fail at a clear pre-flight check step with a list of the missing secrets.

| Secret | Where to find it in hPanel | Example value |
|---|---|---|
| `HOSTINGER_FTP_HOST` | hPanel → Files → FTP Accounts → *Hostname* | `ftp.fenia.com.ar` or the public IP |
| `HOSTINGER_FTP_PORT` | Standard FTPS-explicit | `21` (or `990` if implicit FTPS is enforced) |
| `HOSTINGER_FTP_USER` | hPanel → Files → FTP Accounts. **Create a dedicated FTP account** scoped to `/public_html/` for least-privilege. | `deploy@fenia.com.ar` |
| `HOSTINGER_FTP_PASSWORD` | Set when creating the FTP account | (random ≥20 chars) |
| `HOSTINGER_FTP_REMOTE_DIR` | hPanel → Files → File Manager — copy the path to the directory that contains `index.html` of the live site | `/public_html/` typical |

### Switching to SFTP (Business+ plan with SSH)

If Fab is on Business+ and prefers SFTP (SSH-based) over FTPS:

1. Generate an SSH keypair locally (`ssh-keygen -t ed25519 -C "fenia-deploy"`).
2. Add the public key to hPanel → Advanced → SSH Access.
3. In the workflow, replace the FTPS step with `wlixcc/SFTP-Deploy-Action@v1.2.4` (or current). Five-line change.
4. Replace the `HOSTINGER_FTP_*` secrets with `HOSTINGER_SSH_HOST`, `HOSTINGER_SSH_PORT`, `HOSTINGER_SSH_USER`, `HOSTINGER_SSH_KEY`.

SFTP is preferable when available — stronger transport, cleaner credential rotation.

## Configuration source-of-truth — `public/.htaccess`

Astro copies everything in `public/` verbatim to `dist/` at build time. The FTPS deploy uploads `dist/.htaccess` to the Hostinger document root, where LiteSpeed/Apache reads it per-request.

**What's in there** (full content in the file itself):

- HTTPS redirect (301 from `http://` to `https://`).
- Security headers: HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, X-Frame-Options, CSP.
- Cache policy: 1-year immutable for hashed Astro assets, 1-week for hand-managed media, 1-hour for HTML.
- Gzip compression directives (LiteSpeed adds Brotli automatically).
- `ErrorDocument 404 /404.html` — serves the Astro-emitted 404 page.
- Hardening: `Options -Indexes`, `ServerSignature Off`.

### Editing `.htaccess` safely

- **Stage every change**. A bad `RewriteRule` returns 500 for every request — recovery requires either re-deploying the previous version (via Re-run on the prior GitHub Actions run) or manually uploading the prior file via hPanel File Manager.
- **Keep a local copy of the last good `.htaccess`** before editing if the change is risky.
- **`Options -Indexes` may 500 on some restrictive plans.** If it happens, comment that line, redeploy. Add an issue to revisit if the rest of the config is more important than directory-listing hardening.
- **CSP changes are highest-risk.** Use the browser DevTools console on a preview-deploy URL (locally `npm run preview` and copy the headers manually, or on a Hostinger preview subdomain if set up) before merging.

## DNS

`fenia.com.ar` and `www.fenia.com.ar` resolve via Hostinger DNS at the Hostinger origin's IP. The `MX`, `SPF`, `DKIM`, and `DMARC` records for the `@fenia.com.ar` mailboxes are managed in the same hPanel → DNS Zone Editor view.

- **TLS**: Hostinger issues and auto-renews via Let's Encrypt (hPanel → SSL).
- **Renewal**: confirm SSL is set to "Force HTTPS" in hPanel; the `.htaccess` also enforces it as a belt-and-suspenders measure.

## GitHub branch protection (UI-only)

Branch protection lives in GitHub's UI, not the repo. Recommended settings for `main`:

- Require pull request reviews before merging.
- Require status checks to pass before merging — at minimum the `check-and-build` job from [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml).
- Do not allow force pushes.
- Do not allow deletion.

When the dev gains admin rights on the repo, applying these is a 2-minute task. Reflect any changes here.

## Local development

```sh
npm install
npm run dev
```

Single command, by design ([ADR-006](../adr/ADR-006-dx-single-command-dev.md)). Available at `http://localhost:4321`.

No services to start, no `.env` to populate, no Docker. If a future feature breaks this property, it must explicitly supersede ADR-006 with owner approval.

## Production build verification before merging

```sh
npm run build      # produces dist/ — same command CI runs
npm run preview    # serves dist/ locally to verify
npm run check      # astro check — TypeScript + Astro diagnostics
```

A green `npm run build` + visual approval of the local preview = safe to merge. **There are no per-PR preview deploys** — Hostinger doesn't provide them. If a visual regression is high-risk, set up a `preview.fenia.com.ar` subdomain in hPanel and add a parallel GitHub Actions job that deploys `dev` there. Tracked as a future improvement, not committed today.

## Migration from Vercel — runbook

The migration from Vercel to Hostinger is staged so production is never at risk:

1. **Repo prep** *(done 2026-05-26)*: ADRs 022/023/024 written, `public/.htaccess` added, `.github/workflows/ci.yml` extended with deploy job, all docs updated. Vercel still serves production at this point.
2. **Fab provides credentials and confirms plan** ([PENDING.md](../../PENDING.md)):
   - FTPS host / user / password / port / remote dir.
   - Hostinger plan tier (Premium / Business / Cloud Startup / …) — confirms SSH availability and resource limits.
   - Whether the Hostinger LiteSpeed instance is at default or modified.
3. **Configure GitHub Secrets**: dev sets the five `HOSTINGER_FTP_*` secrets.
4. **First deploy via push to `dev`** (NOT `main`): edit the workflow temporarily to deploy from `dev` to a Hostinger *subdomain* (e.g. `staging.fenia.com.ar`) to validate the full pipeline without touching production. Lighthouse run + functional smoke (`/`, `/dejanos-tu-testimonio`, `/404`, headers via `curl -I`).
5. **Cutover decision**: if staging looks correct, restore the workflow to deploy from `main` to the production document root. Disable the Vercel auto-deploy by pausing the Vercel project.
6. **Push to `main`**: triggers the first production deploy on Hostinger. Verify `https://fenia.com.ar/` returns the new content immediately.
7. **Watch 24–72 h**: UptimeRobot (once configured) catches origin issues; manual checks confirm headers and `.htaccess` behavior.
8. **Vercel cleanup**: after 1 week of stable Hostinger operation, delete the Vercel project. Owner cancels the Vercel plan if applicable.

## Rollback strategy

Hostinger has no atomic "promote previous deploy". Two paths, in order of preference:

1. **Re-run a prior green deploy in GitHub Actions** (`Re-run all jobs` on the last good `main` workflow). It downloads the prior artifact (kept for 7 days) and uploads it via FTPS. ETA: 2–3 minutes.
2. **Manual upload from a local build**: `git checkout <last-good-tag> && npm ci && npm run build` then upload `dist/` via hPanel File Manager. ETA: 10–15 minutes including upload.

For severe failures (corrupted file structure on the server): manual upload is the safe fallback.

## Monitoring (planned — post-cutover)

- **Plausible** — privacy-friendly analytics with custom events.
- **Sentry browser SDK** — JS errors with stacktraces; `PUBLIC_SENTRY_DSN` env var.
- **UptimeRobot** — external 5-minute HTTPS check on `https://fenia.com.ar/`.
- **Lighthouse CI** — *future*; once staging is stable, add a manual-trigger workflow that runs Lighthouse against the staging URL.

See [ADR-024](../adr/ADR-024-observability-on-hostinger.md) for the full plan.
