# ADR-019 — Version hosting configuration in the repo (Cloudflare Pages format)

## Status
**Superseded by [ADR-023](./ADR-023-htaccess-hosting-config.md) (2026-05-26, same day).** The Cloudflare Pages `_headers` / `_redirects` format is irrelevant under the [ADR-022](./ADR-022-pivot-to-hostinger-only.md) Hostinger pivot. The *principle* (version hosting config in the repo) is preserved in ADR-023; only the *format* changes to `.htaccess`. The files `public/_headers` and `public/_redirects` referenced here have been deleted; do not recreate them.

Originally: Accepted (2026-05-26). Required by [ADR-018](./ADR-018-migrate-to-cloudflare-pages.md).

## Context

Under [ADR-002](./ADR-002-hosting-vercel.md), the entire hosting configuration (build command, output directory, region, environment variables, headers, redirects) lived in the Vercel dashboard. The repo had no `vercel.json`. This made the deploy a **black box**:

- The dev could not recreate the deploy from a fresh clone without UI access.
- Any change in the dashboard was invisible to the rest of the team.
- Security-relevant headers (CSP, HSTS, Permissions-Policy) could not be reviewed in PRs because they did not exist in the codebase at all.
- Rolling back a configuration change required dashboard history, not `git revert`.

Migrating to Cloudflare Pages ([ADR-018](./ADR-018-migrate-to-cloudflare-pages.md)) is an opportunity to make the repository the source of truth for hosting configuration without breaking anything in the process.

## Decision

**Two files at the repository root carry the hosting configuration**, both in Cloudflare Pages's native syntax:

1. **`_headers`** — global and per-path HTTP response headers (security headers, cache-control).
2. **`_redirects`** — URL redirects and rewrites.

These files ship with the `dist/` build automatically (Astro copies anything at the repo root next to `public/` is not the path — Cloudflare Pages reads them from the publish directory; in our case we put them in `public/` so they end up at the root of `dist/`). See implementation notes in [docs/ai-context/deployment.md](../ai-context/deployment.md).

### Initial `_headers` baseline (2026-05-26)

```
/*
  Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
  X-Content-Type-Options: nosniff
  Referrer-Policy: strict-origin-when-cross-origin
  Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()
  X-Frame-Options: DENY
  Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; media-src 'self'; connect-src 'self'; frame-src 'self' https://tally.so https://*.tally.so; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; upgrade-insecure-requests
```

Long-cache for hashed Astro assets:

```
/_astro/*
  Cache-Control: public, max-age=31536000, immutable

/images/*
  Cache-Control: public, max-age=604800
```

### CSP — known technical debt

The CSP starts with `'unsafe-inline'` for both `script-src` and `style-src`. This is **deliberate and documented**, not an oversight:

- **`style-src 'unsafe-inline'`** is required because the codebase still uses extensive inline `style="..."` attributes in `.astro` components ([ADR-004 styling strategy](./ADR-004-styling-strategy.md) tracks the phased migration to Tailwind utilities). Tightening this CSP rule is a *consequence* of finishing ADR-004, not an independent task.
- **`script-src 'unsafe-inline'`** is required because Astro 6 inlines:
  - The `astro-island` custom-element bootstrap (`<script>(()=>{...})();</script>`).
  - View Transitions / ClientRouter helpers.
  - A custom inline `<script type="module">` in `index.astro` that wires `[data-service-slug]` click → `fenia:select-service` event.
  - Schema.org JSON-LD (this is `application/ld+json`, not executable JS, but some CSP parsers still validate against `script-src`).

  Tightening this requires either (a) extracting all inline scripts to bundled modules, or (b) per-deploy nonces (which require SSR — incompatible with our SSG output). Neither is in scope for the hosting migration.

A follow-up issue should track tightening the CSP once [ADR-004](./ADR-004-styling-strategy.md) completes and the inline scripts are externalized.

### Initial `_redirects` baseline

Empty (no redirects required today). The file exists so it is obvious where to add future ones (e.g. a `/blog/*` → `/articulos/*` migration).

## Consequences

**Easier:**

- **Source of truth in git.** Every header / redirect change goes through PR review.
- **Recreatable deploys.** A fresh clone + Cloudflare Pages project connect reproduces production exactly.
- **Security posture is reviewable.** Future CSP tightening, Permissions-Policy changes, etc. are visible in `git diff`.
- **Cache strategy is auditable.** Long-cache rules for `/_astro/*` (hash-versioned files) live next to the code that produces them.

**Harder / accepted trade-offs:**

- **CSP starts loose (`'unsafe-inline'`).** This is honestly worse than an ideal nonce-based CSP, but better than no CSP at all. The technical debt is documented and linked to [ADR-004](./ADR-004-styling-strategy.md).
- **`_headers` syntax is Cloudflare-flavored.** If we ever move off Cloudflare Pages, the file must be translated. The translation is mechanical (Vercel's `vercel.json` `headers` array, Netlify's identical `_headers` format, S3+CloudFront's response policies). Cost: ~1 hour.
- **Per-path overrides require care.** Cloudflare Pages applies the *most specific* `_headers` block, not a merge. Documented in [docs/ai-context/deployment.md](../ai-context/deployment.md) so future edits don't accidentally drop the global security headers from a sub-path.

## Alternatives considered

| Option | Why not |
|---|---|
| Keep configuration in the Cloudflare UI (mirror of the old Vercel pattern) | Defeats the purpose of the migration — same black-box problem with a different vendor |
| Use Cloudflare's Wrangler / `wrangler.toml` | Wrangler is the Workers + advanced Pages CLI. For this SSG site with no Functions, `_headers`/`_redirects` are simpler, sufficient, and the documented "Pages way" |
| Terraform / Pulumi for Cloudflare resources | Over-engineering for a single static project with one dev |
| Inline headers via a meta tag (`<meta http-equiv>`) | Subset of HTTP headers that work this way is small; HSTS, CSP `frame-ancestors`, X-Content-Type-Options all *must* be HTTP headers to be effective |
