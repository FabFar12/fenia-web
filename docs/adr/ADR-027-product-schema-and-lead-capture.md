# ADR-027 — Product schema v2, CTA-by-status, and email lead capture

## Status
Accepted (2026-07-07). Extends [ADR-025](./ADR-025-product-sales-via-mercadopago.md) (which stays valid for how Mercado Pago itself works) and closes the "how do we scale past one product" gap it deliberately left open.

## Context

ADR-025 shipped the minimum to sell one PDF via a Mercado Pago Link de Pago. With 6 products now in the collection (3 real coming-soon kits, 3 hidden placeholders), two gaps showed up:

1. The `cta: {label, href}` free-form object couldn't express "this button behaves differently depending on whether the product is sellable yet." Every product needed its own hand-written WhatsApp "avisame" link, and there was no way to route a not-yet-sellable product into an actual growth mechanism (an email list) instead of a dead-end WhatsApp message.
2. The `status` enum (`draft | coming-soon | live | archived`) mixed two concerns — "is this visible" and "is this editorially finished" — with no behavioral difference between `draft` and `archived`. Simplified to `coming-soon | live | hidden`.

The owner wants `coming-soon` products to double as lead generation: capture an email, subscribe it to a launch list, and hand back a free resource — instead of just collecting "notify me" WhatsApp clicks that go nowhere until the product ships.

## Decision

**Schema** (`src/content.config.ts`, `products` collection): replace `cta` with `price` + `currency` + `paymentUrl` (optional) + `ctaLabel` (optional) + `deliveryMode` + `order`. See `docs/ai-context/content-model.md` for the field-by-field reference.

**CTA behavior**, computed in `Productos.astro` from `status`/`paymentUrl` — never hand-authored per product:

| Status | CTA | Destination |
|---|---|---|
| `coming-soon` | "Quiero el recurso gratis" (or `ctaLabel`) | `/recursos-gratuitos?producto=<id>` — email capture |
| `live` + `paymentUrl` | "Comprar ahora" (or `ctaLabel`) | `paymentUrl` (Mercado Pago) |
| `live` sin `paymentUrl` | none | card informativa, sin botón de compra |
| `hidden` | — | not rendered |

**`deliveryMode` never stores a delivery URL.** It's a label for *who* delivers (`lead-magnet` → the ESP automation; `mercadopago-auto` → Mercado Pago itself, per ADR-025; `manual` → the owner by hand), never *where*. The actual Google Drive link / signed download URL / attachment lives in the ESP's or Mercado Pago's own dashboard, outside git — same reasoning as ADR-025's "what the repo handles vs. what Mercado Pago handles" table, extended to the free-resource path.

**Email capture channel: an embedded ESP form (Mailerlite), not a custom backend.** Same constraint set as ADR-025 drove this: static Astro site on Hostinger shared hosting, single non-technical owner, single dev with no bandwidth for a webhook endpoint. A self-hosted capture endpoint would need either Node compute (limited on shared Hostinger) or an external service to operate (forbidden by the single-provider mandate — see [ADR-022](./ADR-022-pivot-to-hostinger-only.md)). An embedded form keeps the entire flow client-side: the owner builds the form + automation (welcome email with the free resource) in Mailerlite's dashboard, the site just embeds an iframe.

Implementation mirrors the existing Tally pattern from [ADR-005](./ADR-005-testimonial-collection.md): a `PUBLIC_MAILERLITE_FORM_URL` env var. When set, `/recursos-gratuitos` embeds the iframe; when unset, it falls back to a WhatsApp "avisame" link so the site never ships a broken form (AGENTS.md "no placeholder lies"). CSP `frame-src` extended with `https://*.mailerlite.com` in `public/.htaccess`.

**Hero stats** (`src/data/site.ts`): "12+ Productos digitales" (invented) replaced with "3 Kits en camino" (the real coming-soon count, hand-set — not derived at build time, since that would require the component to import `getCollection` into a data file, out of scope here). "100% Enfoque aplicado" (unsourced claim, flagged in `PENDING.md` #9) removed rather than reworded, since there's no number to back it.

## Consequences

**Easier:**
- Adding a product no longer requires writing a bespoke WhatsApp CTA — the grid infers the right button from `status`/`paymentUrl`.
- `coming-soon` products now generate an email list instead of only a WhatsApp "notify me" click that has no automation behind it.
- Migrating a product from `coming-soon` to `live` is a two-field edit (`status`, `paymentUrl`) instead of rewriting a `cta` object.

**Harder / accepted trade-offs:**
- **Mailerlite account setup is on the owner** (see `docs/fab-mailerlite-setup.md`) — same operational split as the Mercado Pago setup in ADR-025. Until that env var is set, `coming-soon` products fall back to WhatsApp, same as before this change.
- **One shared launch-list form for all `coming-soon` products for now** — the `producto` query param is passed through to Mailerlite so the owner *can* build per-product automation branches, but nothing enforces per-product free-resource delivery. If the owner wants a distinct lead magnet per kit, that's an ESP-side automation decision, not a schema change.
- **Hero "3 Kits en camino" is hand-set, not derived.** If a kit count changes, someone has to remember to update `site.ts`. Deriving it from `getCollection('products')` is a follow-up if this drifts again.
- **Breaking schema change** — all 6 product `.md` files needed migration in the same commit. No backward-compat shim was kept; `cta` is gone entirely.

## Alternatives considered

| Option | Why not |
|---|---|
| Custom serverless/PHP endpoint for email capture | Reintroduces the backend-operational burden ADR-022/ADR-025 explicitly ruled out for this owner/dev pair |
| Per-product `cta.href` kept as free text (status quo) | Can't express "route to email capture vs. Mercado Pago vs. nothing" without every product author remembering the right URL by hand |
| Derive Hero product count from `getCollection` at build time | Real fix, but requires restructuring `site.ts` (currently a plain data module, no Astro APIs) — deferred, tracked as a known trade-off above |
