# Pre-deploy checklist — Hostinger production cutover

> Run through this BEFORE the first push to `main` triggers a deploy to production. Most items are one-time (initial cutover); subsequent deploys are automatic via the workflow.
> Companion: [post-deploy.md](./post-deploy.md). Authoritative deploy flow: [docs/ai-context/deployment.md](../ai-context/deployment.md).

## Owner inputs (Fab)

- [ ] Hostinger plan tier confirmed (PENDING #2 — Premium / Business / Cloud Startup / …).
- [ ] FTPS account created in *hPanel → Files → FTP Accounts*, scoped to `/public_html/` for least-privilege. Password ≥20 chars.
- [ ] Five values delivered to the dev via a secure channel (password manager link preferred, NOT plain WhatsApp):
  - [ ] `HOSTINGER_FTP_HOST` (hostname; e.g. `ftp.fenia.com.ar`).
  - [ ] `HOSTINGER_FTP_PORT` (typically `21` for FTPS-explicit).
  - [ ] `HOSTINGER_FTP_USER`.
  - [ ] `HOSTINGER_FTP_PASSWORD`.
  - [ ] `HOSTINGER_FTP_REMOTE_DIR` (typically `/public_html/`; verify exact path in File Manager).
- [ ] Web server confirmed (LiteSpeed assumed; Apache works without changes).

## GitHub configuration (dev)

- [ ] All 5 `HOSTINGER_FTP_*` secrets set: *Repo → Settings → Secrets and variables → Actions → New repository secret*.
- [ ] `main` branch protection enabled: *Repo → Settings → Branches → Add branch protection rule*.
  - [ ] Require a pull request before merging.
  - [ ] Require status check `astro check + build` to pass.
  - [ ] Block force pushes.
  - [ ] Block deletions.
- [ ] GitHub Environment `production` exists (auto-created on first deploy run, or pre-create via UI for an extra manual review gate).
- [ ] Repository is **private** if FTPS creds live as Secrets (default GitHub Secrets are safe in public repos too, but private adds belt-and-suspenders).

## Staging validation (recommended before touching `main`)

- [ ] Subdomain `staging.fenia.com.ar` created in hPanel pointing at a separate document root.
- [ ] Workflow temporarily edited (a branch off `dev`) so the `deploy` job points at the staging document root.
- [ ] Push to `dev` triggers staging deploy.
- [ ] `curl -I https://staging.fenia.com.ar/` confirms headers:
  - [ ] `Strict-Transport-Security`
  - [ ] `Content-Security-Policy`
  - [ ] `X-Content-Type-Options: nosniff`
  - [ ] `X-Frame-Options: DENY`
  - [ ] `Referrer-Policy: strict-origin-when-cross-origin`
  - [ ] `Permissions-Policy`
- [ ] Page loads visually in a real browser — no broken images, no CSP violations in DevTools console.
- [ ] 3D neural network renders (canvas appears, no errors in console).
- [ ] WhatsApp CTAs route to `wa.me/5493513559947` with correct prefilled text.
- [ ] `/dejanos-tu-testimonio` loads with the WhatsApp fallback (no `PUBLIC_TALLY_TESTIMONIAL_FORM_URL` set yet).
- [ ] `/404` reachable via a non-existent path (e.g. `/xyz-does-not-exist`).
- [ ] Lighthouse score acceptable: Performance ≥ 80, SEO ≥ 95, Accessibility ≥ 90, Best Practices ≥ 95.

## Production cutover

- [ ] Workflow restored to deploy `main` → production document root (commit on `dev`, PR to `main`, merge).
- [ ] Vercel project paused (NOT deleted yet — leave one week for rollback safety).
- [ ] Last good Vercel deploy URL / commit SHA noted for emergency rollback.
- [ ] DNS TTL lowered to 300s 48h before cutover *only if DNS records change* (under Hostinger-only the domain already points at Hostinger; usually no DNS change needed).
- [ ] Push to `main` → GitHub Actions runs CI + deploy → verify within 5 minutes that `https://fenia.com.ar/` serves the new content.

## Communications

- [ ] Owner (Fab) notified that the cutover happened and that for the next week the dev is on watch.
