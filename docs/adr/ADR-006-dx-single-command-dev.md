# ADR-006 — Developer Experience: `npm run dev` must remain the single command

## Status
Accepted (2026-05-19). Hard constraint, owner-mandated.

## Context

The site owner is **non-technical**. He currently runs the site locally with:

```sh
npm run dev
```

…and nothing else. He has explicitly stated (2026-05-19): *"si se requieren levantar servicios extras o comandos raros, que se corran todos con el script de npm run dev."*

In the AI-driven modernization roadmap, several proposals could naively introduce multi-step or multi-terminal setups:

- A testimonial submission backend (Vercel serverless function) would tempt us to require `vercel dev` separately.
- An image-processing watcher (Sharp / Squoosh) would tempt us to require `npm run images` first.
- A Tailwind v4 standalone CLI watcher could be tempted to run separately.
- A lint/format on save could be a pre-step.

**Each of those would break the owner's mental model and operational autonomy.**

## Decision

**`npm run dev` is the single, sacred entry point for local development.**

Concretely:

1. **Any additional service required for local development must be orchestrated from inside the `dev` script** using `npm-run-all` (`run-p` for parallel, `run-s` for sequential) or `concurrently`. No "open three terminals" instructions are acceptable.

2. **Optional tooling** that the owner doesn't need (linting, type-checking, formatters, regenerating types) gets its own script (`npm run check`, `npm run lint`, `npm run sync`, etc.) — **never chained into `dev`**.

3. **`npm install` followed by `npm run dev`** must produce a working, reviewable site at `http://localhost:4321` even on a fresh clone with no `.env` file (because the site is currently fully static — see [.env.example](../../.env.example) for the placeholder structure).

4. **If a future feature genuinely requires server-side dev (e.g. a real form backend)**:
   - Prefer Vercel serverless functions with `vercel dev` wrapped inside `npm run dev` via `npm-run-all run-p`.
   - OR keep the function out of local-dev entirely: deploy it once, develop the frontend against the deployed preview function URL.
   - **Never** require the owner to install or learn the Vercel CLI.

5. **Document this constraint at the top of `AGENTS.md`** so future AI sessions don't accidentally violate it.

## Consequences

**Easier:**
- The owner remains operationally autonomous.
- Onboarding any new collaborator is trivially documented.
- The CI/CD pipeline mirrors local-dev exactly (Vercel runs `npm run build`, just like local does for previews).

**Harder / accepted trade-offs:**
- We must engineer around this constraint when adding services. That's a feature, not a bug — it forces architectural simplicity.
- Some debugging conveniences (e.g., separate terminals for backend logs vs frontend) are unavailable to the owner; the dev can use `npm-run-all`'s combined output and `--prefix` flags to keep things legible.
- If we ever truly need a multi-process dev that can't be hidden, this ADR must be explicitly superseded with the owner's agreement.
