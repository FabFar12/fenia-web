# ADR-023 — `.htaccess` for hosting configuration on Hostinger

## Status
Accepted (2026-05-26). Supersedes [ADR-019](./ADR-019-version-hosting-config.md). Required by [ADR-022](./ADR-022-pivot-to-hostinger-only.md).

## Context

[ADR-019](./ADR-019-version-hosting-config.md) versioned hosting configuration through Cloudflare Pages's `_headers` and `_redirects` files. That plan was reverted hours later by the owner ([ADR-022](./ADR-022-pivot-to-hostinger-only.md)). Hostinger does not read `_headers` / `_redirects`; the equivalent for shared LiteSpeed / Apache hosting is `.htaccess` placed in the document root.

The principle from [ADR-019](./ADR-019-version-hosting-config.md) still holds: **hosting configuration is source-of-truth in the repository, not in the provider UI.** What changes is the format and the location.

## Decision

**One file at `public/.htaccess`** carries all hosting configuration: HTTPS enforcement, security headers, cache control, compression, error documents, and hardening flags.

Astro copies `public/*` verbatim to the root of `dist/` at build time. The GitHub Actions deploy job ([ADR-020](./ADR-020-ci-minimal-github-actions.md)) uploads `dist/.htaccess` via FTPS to the Hostinger document root, where LiteSpeed / Apache reads it per-request.

### What lives in `.htaccess` (initial baseline, 2026-05-26)

| Block | Purpose |
|---|---|
| `mod_rewrite` HTTPS redirect | Force all traffic to HTTPS via 301 |
| `mod_headers` security headers | HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, X-Frame-Options, CSP |
| `mod_headers` cache control (per FilesMatch) | 1-year immutable for hashed Astro assets, 1-week for hand-managed media, 1-hour for HTML/XML |
| `mod_deflate` compression | Gzip for text, JSON, fonts, SVG (LiteSpeed adds brotli automatically) |
| `ErrorDocument 404` | Serve `/404.html` (already emitted by Astro) for 404s |
| `Options -Indexes` + `ServerSignature Off` | No directory listing, no server version disclosure |

The complete current content lives at [`public/.htaccess`](../../public/.htaccess) — that file is the source of truth. This ADR documents only the *principle* and the *categories*, not the exact bytes.

### What does NOT live in `.htaccess`

- Application logic, anything dynamic (we have none).
- Redirect rules for marketing URLs — defer until needed; will become a section if/when added.
- LSCache directives (`<IfModule LiteSpeed>`) — deliberately omitted to keep the file portable to plain Apache. Hostinger's LiteSpeed delivers reasonable cache behavior without explicit LSCache rules at this site's scale.

### CSP technical debt (carries over verbatim from [ADR-019](./ADR-019-version-hosting-config.md))

CSP still includes `'unsafe-inline'` for both `script-src` and `style-src` because:

- The codebase still uses extensive inline `style="..."` attributes ([ADR-004](./ADR-004-styling-strategy.md) is the long-term fix).
- Astro 6 inlines `astro-island` bootstrap, View Transitions / ClientRouter helpers, and one custom `<script type="module">` in `index.astro` for `[data-service-slug]` clicks.

Tightening requires either externalising every inline script or per-deploy nonces (the latter incompatible with SSG). Both out of scope for the hosting migration. Tracked as deferred work tied to [ADR-004](./ADR-004-styling-strategy.md) closure.

## Consequences

**Easier:**

- **Source of truth in git.** Every header / cache change goes through PR review.
- **Security posture reviewable.** CSP, HSTS, Permissions-Policy are visible in `git diff`.
- **Recreatable deploys.** A fresh clone + `npm run build` + FTPS upload reproduces production exactly.
- **No "config drift in the hPanel".** The hPanel UI for `.htaccess` is bypassed; the file in the repo wins (assuming nobody edits it on-server out-of-band — see hardening below).

**Harder / accepted trade-offs:**

- **`.htaccess` is parsed per-request** (unless cached by LiteSpeed). At this site's traffic the cost is negligible, but on a high-traffic site this would warrant moving directives to the main server config (which is not possible on shared hosting).
- **Easy to lock yourself out.** A bad `RewriteRule` can return 500 for every request. Mitigation: stage every `.htaccess` change in a separate Hostinger subdomain first, OR keep the previous version locally so re-uploading the prior file is a 30-second recovery.
- **`Options -Indexes` may trigger 500 on restrictive shared plans.** If post-deploy the site returns 500, comment that line and redeploy. Documented in [`docs/ai-context/deployment.md`](../ai-context/deployment.md).
- **No per-deploy CSP nonces.** Static `'unsafe-inline'` weakens CSP versus a nonce-based policy. Accepted as known debt.
- **Not portable to non-Apache-compatible hosts** (CloudFront, S3 native, Caddy with custom config). Migration cost: ~30 min mechanical rewrite to the target host's format.

## Alternatives considered

| Option | Why not |
|---|---|
| Set headers via Hostinger's hPanel UI ("Custom Headers" or similar) | The hPanel UI is the black box we are *replacing*; defeats the source-of-truth principle |
| `<meta http-equiv>` tags in HTML for CSP / HSTS | Subset that works this way is small; HSTS and CSP `frame-ancestors` *must* be HTTP headers to be effective |
| Move config from `public/.htaccess` to repo root and copy at build time via a script | Adds a build step, violates "no magic outside Astro" — `public/` → `dist/` copy is Astro's native behavior, simpler |
| Separate `.htaccess` files per directory (e.g. one for `/_astro`, one for root) | Premature; the current FilesMatch blocks handle per-extension rules adequately |
| Use LiteSpeed-specific cache directives | Locks the file to LiteSpeed-only and complicates emergency rollback to a different host |
