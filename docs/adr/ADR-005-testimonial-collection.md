# ADR-005 — Testimonial Collection: Tally form + manual curation (Level A MVP)

## Status
Accepted (2026-05-19). MVP. Will be revisited when the site has a steady stream of new engagements (Level B candidate).

## Context

The pre-existing `Confianza.astro` section displays **3 anonymous testimonials** ("Director de Operaciones", "Fundadora y CEO", "Consultora en RRHH") that **were invented by an AI**. For a consultancy whose differential is "evidencia neurocientífica real", AI-invented social proof is a credibility risk.

The owner has confirmed the goal: **collect real reviews from real engaged clients**, then publish them on the site. Per his words: *"quiero lograr una forma de poder sugerir a la gente con la que trabajamos que nos puntúen en la página"*.

Three options were considered:

| Option | How it works | Time | Cost |
|--------|--------------|------|------|
| **A — Manual assisted (Tally + curation)** | Public form on tally.so; link shared via WhatsApp after engagements; reviews curated manually and added as Markdown in `src/content/testimonials/`. | 1–2 h | $0 |
| **B — Self-service backend** | Form on the site → Vercel serverless function → Notion DB or Google Sheets → moderation UI → publish. | 1–2 days | $0 (free tiers) |
| **C — Third-party widget** | Senja / Trustindex / Featurable embedded. | 1 h | $20–40/mo |

## Decision

**Option A — Tally + manual curation.** Rationale:

1. **Volume is low.** A consultancy onboarding a handful of new clients per month doesn't need a self-service backend.
2. **Curation quality matters.** Each testimonial gets human review before publication — fits the brand's "criterio" ethos.
3. **Zero ongoing cost.** Tally free tier is generous; no recurring subscription.
4. **No backend complexity.** Keeps the single-command `npm run dev` DX intact (see [ADR-006](./ADR-006-dx-single-command-dev.md)).
5. **Re-evaluable.** When volume grows past ~5 testimonials/month, migrate to Option B without rework — Tally exports to CSV/JSON, easy to import.

## Operational flow

```
Engagement closes
       │
       ▼
Owner sends WhatsApp message to the client with:
"Si querés ayudarnos a contar lo que hicimos juntos: <tally form url>"
       │
       ▼
Client fills Tally form:
  - Full name (required)
  - Role / position
  - Company / project
  - LinkedIn (optional)
  - Testimonial text (max 280 chars recommended)
  - Photo (optional)
  - Explicit consent to publish (checkbox required)
       │
       ▼
Owner receives email notification from Tally
       │
       ▼
Owner reviews → if approved, sends entry to dev
       │
       ▼
Dev creates src/content/testimonials/<slug>.md with frontmatter
       │
       ▼
Dev pushes to dev branch → Vercel preview deploy
       │
       ▼
Owner approves preview → merge to main → live
```

## Schema for `src/content/testimonials/<slug>.md`

See [`src/content.config.ts`](../../src/content.config.ts) for the authoritative Zod definition. Indicative fields:

```yaml
---
name: "María Pérez"               # required
role: "Directora de Operaciones"  # required
org: "Tech Co"                    # required
location: "Córdoba"               # optional
linkedIn: "https://linkedin.com/in/..."  # optional
photo: "/testimonials/maria.jpg"  # optional
rating: 5                         # required, 1–5
consentGivenAt: 2026-05-20        # required, date the owner has signed consent for
status: "approved"                # required: 'approved' | 'draft' | 'archived'
publishedAt: 2026-05-20           # required for status=approved
---

El programa transformó cómo tomamos decisiones a nivel directivo. Concreto, aplicable, y medible.
```

## What happens to the placeholder testimonials in production today?

**Pending owner decision** (see [PENDING.md #4](../../PENDING.md)):
- **Option α**: Mark the section as "Próximamente — testimonios verificables" until at least 1 real testimonial is approved.
- **Option β**: Replace with a "Casos de uso" section that talks about *engagements* (sectors, problem types) without quoting fake people.
- **Option γ**: Hide the section entirely until populated.

The AI **must NOT keep the AI-invented testimonials live** once this ADR is in effect. The corresponding component refactor is scheduled as a separate PR with mockup approval (see Plan Maestro Fase 4.2).

## Consequences

**Easier:**
- Zero backend, zero hosting cost for the testimonial pipeline.
- Each testimonial is provably consented and curated → defensible if questioned.
- The pipeline is owner-operable without dev intervention (until dev adds the .md, but that step can later be automated with a Notion → GitHub action if volume grows).

**Harder / accepted trade-offs:**
- Latency: a new testimonial takes 1–2 days to appear (Tally → review → dev → preview → merge).
- Manual step risks dropping submissions — Tally email notifications must be reliable.
- Photos require a manual upload to `public/testimonials/` and proper image optimization.
