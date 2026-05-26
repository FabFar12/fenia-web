# ADR-025 — Product sales via Mercado Pago Link de Pago (MVP)

## Status
Accepted (2026-05-26). Closes [PENDING.md](../../PENDING.md) "¿Productos vende o solo informa?" (item #10 of 2026-05-26 list). Tier-A MVP — Tier-B (embedded checkout with PHP backend) deferred until validated by sales volume.

## Context

The site has had a `Productos` section since launch ([ADR-003](./ADR-003-content-layer.md)), but every product card was a placeholder linking to `#contacto` for a "Consultar" conversation. The owner now has a first real product ready to sell:

- **Guía para evaluar en tiempos de IA** — a PDF, infoproduct format, audience is the same as the site's (profesionales / emprendedores / empresas).

The decision space had three tiers:

1. **Tier A** — external payment link (Mercado Pago / Gumroad / Hotmart).
2. **Tier B** — embedded checkout via PHP backend on the same Hostinger plan, with webhooks, signed download URLs, MySQL purchase records.
3. **Tier C** — full self-hosted commerce stack (Astro SSR + Node + DB + email service).

Constraints that ruled out Tier B and Tier C for the MVP:

- Single non-technical owner. Anything that requires Fab to operate a webhook endpoint or rotate signing keys is a no-go.
- Single dev. No bandwidth to maintain a payment backend for a 1-product catalogue.
- Audience is **Argentina-primary**. Pesos argentinos, local payment methods (transferencia, cuotas, Mercado Pago saldo) are non-negotiable for conversion.
- The site is Astro SSG on shared LiteSpeed hosting ([ADR-022](./ADR-022-pivot-to-hostinger-only.md)). Adding Node-based backend code would require either Hostinger's Node support (limited on shared) or moving compute outside Hostinger (forbidden by owner mandate).
- Sales volume is **unproven**. Investing in Tier B before validating that the product sells = premature optimization.

## Decision

**Use Mercado Pago "Link de Pago" with built-in digital-product delivery** for the first product, and revisit when there is evidence (≥20 sales/month or ≥3 live products) to justify Tier B.

### How it works

1. The owner (Fab) creates the product inside his **Mercado Pago Vendedor** account:
   - Type: digital product.
   - Uploads the PDF as the deliverable.
   - Sets price in ARS.
   - Configures "URL de retorno tras pago exitoso" → `https://fenia.com.ar/gracias` (page to be created when activation happens).
   - Mercado Pago issues a permalink: `https://link.mercadopago.com.ar/<id>` (or equivalent).
2. The product is described in `src/content/products/guia-evaluar-tiempos-ia.md` (Astro Content Collection — see [ADR-003](./ADR-003-content-layer.md)):
   - `status: "live"` (once active; `coming-soon` while awaiting the link).
   - `cta.label: "Comprar ahora"`.
   - `cta.href: <Mercado Pago link>`.
   - `price: <number in ARS>`.
3. On click, the visitor is redirected to `checkout.mercadopago.com`, completes payment, receives the PDF via Mercado Pago's automated email, and lands back on `/gracias` on the site.
4. The 3 existing placeholder products (Guía de Neuro-Liderazgo, Toolkit de IA, Método de Bienestar) move to `status: "draft"` and disappear from the grid until they are real products.

### What the repo handles vs. what Mercado Pago handles

| Concern | Owned by |
|---|---|
| Product description, image, price display on the card | **Repo** (`src/content/products/*.md`) |
| Routing to checkout | **Repo** (a single `<a href>` in the `Productos.astro` template) |
| Payment processing, fraud, refunds, invoicing | **Mercado Pago** |
| Delivery of the PDF to the buyer | **Mercado Pago** (auto-email post-payment) |
| Purchase record / receipt for the buyer | **Mercado Pago** (per Argentine tax law, MP emits factura) |
| AFIP / tax reporting on the seller side | **Mercado Pago integration with Fab's monotributo / responsable inscripto** |
| Customer support on the purchase flow | **Mercado Pago first**, Fab via WhatsApp if escalation needed |

### Scope explicitly excluded from this ADR

- **Embedded checkout** (modal inside the site) — Tier B work.
- **Multiple products in a cart** — irrelevant for a single PDF.
- **User accounts / login** — out of scope.
- **Analytics on the purchase funnel** — deferred to [ADR-024](./ADR-024-observability-on-hostinger.md) (Plausible custom events). Tracking `click_buy_pdf` is a 3-line addition when Plausible lands.
- **Anti-piracy / watermarked PDFs** — at this price point and audience, not worth the complexity.
- **A/B testing different price points** — manual: change the number, observe.

## Consequences

**Easier:**

- **Time to live**: 1-2 hours of work once Fab provides the link + metadata. No backend, no DB, no email service.
- **Operational cost is purely transactional**: Mercado Pago fee (~6% + IVA) on each sale; no monthly infra cost.
- **Legal/tax handled by Mercado Pago**: automated factura electrónica, easier for Fab than a custom e-commerce flow.
- **Reversible**: if sales don't justify it, revert the product to `coming-soon` and the section is unchanged. If they do, the data already in `src/content/products/*.md` informs the Tier B design directly.
- **Pricing flexibility**: change the price in MP, no deploy needed; the site never knows the exact price unless we want it to.

**Harder / accepted trade-offs:**

- **The buyer leaves the domain** during checkout (~30 seconds on `checkout.mercadopago.com`). Some conversion is lost vs. an embedded flow. Acceptable for MVP.
- **No analytics on cart abandonment** — we only know when somebody clicked the CTA, not whether they completed payment. Plausible custom events fix the click-side; the conversion side stays opaque until we wire MP webhook → server-side event (Tier B work).
- **Branding gap**: the MP checkout looks like MP, not like FENIA. Acceptable; users trust MP.
- **No automated "post-purchase nurture"**: no email sequence after the buyer receives the PDF. If Fab wants that, it's a manual job (he exports the buyer list from MP and sends a follow-up via his email tool).
- **CSP unchanged**: the site never embeds the MP checkout, so we don't need to expand `frame-src`. If we ever move to embedded checkout (Tier B), CSP will need `https://*.mercadopago.com` in `frame-src` / `connect-src`.

**Migration path to Tier B (when justified):**

- The Content Collection schema in `src/content.config.ts` already has `price`, `cta`, and audience fields. Tier B would add:
  - A `mp_product_id` field to map the product to its MP-side ID.
  - A PHP endpoint at `/api/mp-webhook` that receives MP's webhook, validates the signature, writes the purchase to MySQL (Hostinger Business plan includes MySQL), and emits a signed download URL.
  - A `download.php` endpoint that validates the signed URL and streams the PDF behind authentication.
  - CSP expansion: `frame-src https://*.mercadopago.com`, `connect-src https://*.mercadopago.com` and Sentry/Plausible additions.
  - A new ADR documenting the schema, signing scheme, and security model.

The Tier A → B migration is a refactor, not a rewrite. The `src/content/products/*.md` files survive intact.

## Alternatives considered

| Option | Why not |
|---|---|
| Gumroad | Charges in USD, takes 10% + USD 0.50, buyer leaves domain to a non-FENIA-branded checkout. Worse for Argentine audience |
| Hotmart | Strong in LATAM but the platform's branding skew is "infoproduct funnel" — clashes with FENIA's premium consulting positioning |
| Lemon Squeezy | Excellent for global SaaS; weaker for one-time PDF in pesos; not well-known in Argentina |
| Stripe | Doesn't officially support Argentine merchants for ARS billing |
| Self-hosted Snipcart / Shopify Lite | Monthly fee not justified by a single product |
| Custom Tier B backend now | Premature optimization. Build it only when sales prove the demand |
| Sell exclusively via WhatsApp (manual cobro) | Doesn't scale, drives Fab's hand-attention to every transaction |

## Follow-ups

- ✅ **Activation scaffolding** (done in this commit + the immediate follow-up): product card created in `coming-soon`, `/gracias` page live, owner walkthrough at [`docs/fab-mercadopago-setup.md`](../fab-mercadopago-setup.md).
- ⏳ **Real activation**: when Fab provides the MP link + final metadata, switch the `.md` to `live` (1-2 hours of dev work).
- **Plausible custom event** (when [ADR-024](./ADR-024-observability-on-hostinger.md) lands): `click_buy_pdf` fired on click of the "Comprar ahora" CTA.
- **Tier B trigger**: revisit this ADR if monthly sales ≥ 20 or live product count ≥ 3.
- **Privacy policy** ([PENDING.md](../../PENDING.md) #12): once we sell to identifiable buyers (MP captures email), the legal page becomes more relevant.
