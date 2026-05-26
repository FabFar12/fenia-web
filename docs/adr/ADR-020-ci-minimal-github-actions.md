# ADR-020 — CI on GitHub Actions: quality gate + Hostinger deploy

## Status
Accepted (2026-05-26). **Same-day update**: when the owner pivoted to Hostinger-only ([ADR-022](./ADR-022-pivot-to-hostinger-only.md)), the workflow's scope was extended from "quality gate only" to "quality gate + deploy". Both responsibilities live in [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml).

The original "quality-gate-only" mandate is **narrowed, not contradicted**: pull-request builds are still purely a quality gate; only pushes to `main` trigger the deploy job. See the "Same-day update — Hostinger deploy" section at the end of this ADR.

## Context

The repository has **no automated quality gate** today. The audit ([docs/audit/2026-05-19-initial-audit.md](../audit/2026-05-19-initial-audit.md)) scored Testing at 0/10. The closest thing to validation is the human discipline of running `npm run check` before opening a PR — falible and skipped under time pressure.

The hosting migration is also a good moment to introduce CI because:

1. Cloudflare Pages's own build runner can fail at deploy-time; catching a broken build at PR-time (before merge) is cheaper than reverting from production.
2. AGENTS.md § PRESERVE OPERATIONAL CONTINUITY mandates "any change that risks visual regression must be validated against the live site via Playwright screenshots before/after, or via a Vercel preview deploy" — a basic CI step that proves the build still compiles is the first level of that gate.
3. The owner is non-technical. If a PR breaks the build, the failure should be visible **in GitHub before the merge**, not discovered when the production deploy fails afterwards.

The single-command DX constraint ([ADR-006](./ADR-006-dx-single-command-dev.md)) restricts what the *owner* runs locally; it does not restrict what runs in CI for the *dev*.

## Decision

**Add one GitHub Actions workflow: `.github/workflows/ci.yml`.**

It runs on:

- Every push to `dev` and `main`.
- Every pull request targeting `dev` or `main`.

It executes three steps, in this order:

1. `npm ci` — reproducible install using `package-lock.json`.
2. `npm run check` — `astro check`: TypeScript + Astro template diagnostics.
3. `npm run build` — `astro build`: catches anything that compiles in editor but breaks at build time.

**The job fails fast** (no `continue-on-error`) and **blocks merge** if any step fails (enforced via branch protection rules on `main` — these live in the GitHub UI; documented in [docs/ai-context/deployment.md](../ai-context/deployment.md)).

### Out of scope (for now)

The following are *deliberately not in this workflow*:

- **Unit tests** — there are none yet, and writing them just to have CI run something is the wrong direction. When tests exist, they get added here.
- **Visual regression (Playwright screenshots)** — high value but more setup. Tracked separately for a later ADR.
- **Lighthouse CI** — useful, but requires a deployed preview URL to measure. Better added once Cloudflare Pages previews are stable post-migration.
- **`npm audit` / dependency scanning** — Renovate or Dependabot handles this; not a CI concern.
- **Lint / format** — no ESLint/Prettier configured today ([ADR-001](./ADR-001-tech-stack.md) does not require them yet). If added, they get their own script and CI step.
- **Deploy steps** — *was* "Cloudflare Pages's own GitHub integration handles deploys". **Updated 2026-05-26 same day** under [ADR-022](./ADR-022-pivot-to-hostinger-only.md): Hostinger has no native git auto-deploy, so a `deploy` job was added to this workflow. See "Same-day update — Hostinger deploy" below.

### Node version

Pinned via `actions/setup-node@v4` with `node-version-file: 'package.json'` (reads the `engines.node` field) so the runner stays in lockstep with the engineers' local Node and Cloudflare Pages's `NODE_VERSION`. No drift.

## Consequences

**Easier:**

- **No more "I forgot to run `npm run check`".** The workflow catches TS / Astro diagnostics on every PR.
- **Broken builds never reach production.** If `npm run build` fails in CI, the PR cannot merge to `main`, so Cloudflare Pages never sees a broken build.
- **One canonical Node version.** No more "works on my machine" when a contributor uses Node 20 instead of 22.
- **Free.** GitHub Actions's free tier (2000 minutes/month for private, unlimited for public repos) covers this workflow for years.

**Harder / accepted trade-offs:**

- **Slightly longer PR feedback loop** (~60-90 s of CI per PR). Acceptable.
- **The owner sees a green/red checkmark next to PRs** — one more concept for them to absorb. Mitigation: tell them "green tick = the robot says the page builds; red = wait for Matías".
- **Branch protection rules live in the GitHub UI**, not the repo. We document them in `deployment.md` but cannot version them without a `.github/settings.yml` extension. Acceptable as long as the documentation note is maintained.

## Alternatives considered

| Option | Why not |
|---|---|
| No CI (status quo) | Leaves regressions undetected until they hit Cloudflare's build or, worse, production |
| Pre-commit hook (Husky) | Bypassable with `--no-verify`; runs only on the committer's machine; doesn't protect `main` |
| Run `npm run check` inside `npm run build` | Violates [ADR-006](./ADR-006-dx-single-command-dev.md) — optional tooling must not be chained into `dev`/`build` |
| Cloudflare Pages's built-in pre-deploy step | Cloudflare runs only `npm run build`; it does not run `npm run check`, and a TS error that doesn't fail the build slips through |
| GitLab CI / CircleCI / Buildkite | The repo is already on GitHub; Actions is the lowest-friction option |

## Same-day update — Hostinger deploy (2026-05-26)

The owner pivot to Hostinger-only ([ADR-022](./ADR-022-pivot-to-hostinger-only.md)) removed the previously-planned Cloudflare Pages GitHub integration as the deploy mechanism. Hostinger has no equivalent native git auto-deploy. The simplest workable replacement, given the existing GitHub Actions investment, is **a second job in the same workflow that uploads `dist/` to Hostinger via FTPS**.

### Workflow shape

Two jobs in `.github/workflows/ci.yml`:

1. **`check-and-build`** — runs on every push to `main`/`dev` and every PR. Steps: checkout, setup Node from `package.json` engines, `npm ci`, `npm run check`, `npm run build`. On `main` pushes, uploads `dist/` as a workflow artifact for the deploy job to consume.
2. **`deploy`** — runs ONLY on push to `main` (not PRs, not `dev`). `needs: check-and-build` so a failing build blocks deploy. Downloads the artifact and uploads via FTPS using [`SamKirkland/FTP-Deploy-Action@v4.3.5`](https://github.com/SamKirkland/FTP-Deploy-Action) with `protocol: ftps` and `security: strict`.

### Required GitHub Secrets

Configured in *Repo Settings → Secrets and variables → Actions → New repository secret*. **Until they are set, deploys to `main` will fail at the deploy step with a clear error.** The `check-and-build` job remains green.

| Secret name | Value (provided by Fab) |
|---|---|
| `HOSTINGER_FTP_HOST` | FTPS hostname (e.g. `ftp.fenia.com.ar` or the server IP from hPanel) |
| `HOSTINGER_FTP_PORT` | `21` for FTPS-explicit (Hostinger default), or `990` for FTPS-implicit |
| `HOSTINGER_FTP_USER` | FTP account username (create a dedicated one in hPanel scoped to `/public_html/`) |
| `HOSTINGER_FTP_PASSWORD` | FTP account password |
| `HOSTINGER_FTP_REMOTE_DIR` | Document root path (typically `/public_html/` or `/domains/fenia.com.ar/public_html/` — confirm exact path in hPanel File Manager) |

### Sync behaviour

The action keeps a `.ftp-deploy-sync-state.json` file in the remote root so subsequent deploys upload only changed files. This:

- **Pros:** much faster after the first deploy; reduced bandwidth.
- **Cons:** if `.ftp-deploy-sync-state.json` is deleted or corrupted on the server, the next deploy re-uploads everything (slow but correct). Documented in [`docs/ai-context/deployment.md`](../ai-context/deployment.md).

### Rollback

Hostinger has no atomic "promote previous deploy" button. To roll back:

1. In GitHub Actions, find the last green deploy run for `main`.
2. Re-run that workflow (`Re-run all jobs`). It will re-upload the *previous* artifact's bytes to Hostinger and overwrite the broken deploy.
3. ETA: 2–3 minutes including FTPS upload.

For severe cases (corrupted file structure), maintain a local `dist/` of the last good build by running `npm run build` from the corresponding git tag, then upload manually via the hPanel File Manager.

### Why FTPS and not SFTP

Hostinger's Premium tier (the most likely tier for the ARS 200K / 4 year plan) consistently supports FTPS but does not always grant SSH access. SFTP requires SSH. Business+ tier *does* support SSH; if Fab is on Business+, switching the workflow to SFTP via [`wlixcc/SFTP-Deploy-Action`](https://github.com/wlixcc/SFTP-Deploy-Action) is a 5-line change. Documented in [`docs/ai-context/deployment.md`](../ai-context/deployment.md).
