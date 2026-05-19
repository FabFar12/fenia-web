# AI Context — Index & Meta-Routing

This folder contains machine-readable context files to help AI assistants (and human collaborators) understand the project quickly without re-deriving it from the code.

> [!IMPORTANT]
> **AI ASSISTANTS**: You **MUST** cross-reference these models with their corresponding Architecture Decisions (`docs/adr/`) before writing code. Mirror style: see [CoffeeAppMain's `docs/ai-context/`](https://github.com/) for the originating convention.

## Knowledge Map

| Concept / File | Read this BEFORE making changes to… | Associated ADRs |
|---|---|---|
| [`system-overview.md`](./system-overview.md) | Any structural change, new section, new page, or change to the home page composition. | [ADR-001](../adr/ADR-001-tech-stack.md) |
| [`content-model.md`](./content-model.md) | **Any** edit to WhatsApp number, social handles, hero stats, products, testimonials, audience cards, methodology pillars, services list. **NEVER edit those in components — they live in `src/data/site.ts` or `src/content/`.** | [ADR-003](../adr/ADR-003-content-layer.md), [ADR-005](../adr/ADR-005-testimonial-collection.md) |
| [`deployment.md`](./deployment.md) | DNS, Vercel project settings, env vars, custom domains, any infra-touching change. | [ADR-002](../adr/ADR-002-hosting-vercel.md), [ADR-006](../adr/ADR-006-dx-single-command-dev.md) |
| [`style-guide.md`](./style-guide.md) | Colors, typography, spacing, animations, component look & feel, responsive behavior. | [ADR-001](../adr/ADR-001-tech-stack.md), [ADR-004](../adr/ADR-004-styling-strategy.md) |

## How to use

When starting a new AI session on this codebase:

1. **Always** read [`AGENTS.md`](../../AGENTS.md) at the repo root first.
2. Read [`system-overview.md`](./system-overview.md) for the big picture (5 min read).
3. Use the **Knowledge Map** above to find the specific context file + ADR for your current task.
4. If you modify any rules, **UPDATE THESE MARKDOWN FILES IN THE SAME PR** so future AI sessions stay in sync.
5. If you discover the docs are out of date, fix the docs before fixing the code — out-of-sync docs are worse than no docs.

## Audit history

Point-in-time analyses live in [`../audit/`](../audit/). They are snapshots, not living documents — never edit a past audit; create a new one for the new state.
