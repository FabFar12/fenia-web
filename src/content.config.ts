/**
 * Astro Content Collections — schema source of truth.
 *
 * Two collections:
 *   - `products`     → src/content/products/*.md
 *   - `testimonials` → src/content/testimonials/*.md
 *
 * Edit a collection by creating / editing a Markdown file in the corresponding folder.
 * See: docs/ai-context/content-model.md for the operational guide.
 */

import { defineCollection } from 'astro:content';
import { glob } from 'astro/loaders';
import { z } from 'astro/zod';

// ─────────────────────────────────────────────────────────────────────────────
// Products
// ─────────────────────────────────────────────────────────────────────────────

const products = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/products' }),
  schema: z.object({
    /** Human-readable name shown on cards and detail pages. */
    title: z.string().min(3),

    /** Category — drives the badge color & icon. */
    type: z.enum(['Guía', 'Caja de herramientas', 'Método', 'Curso', 'Workshop', 'Otro']),

    /**
     * Lifecycle state. Controls visibility and CTA behavior. See Productos.astro.
     *  - `coming-soon`   → shown with a "Próximamente" badge. CTA captures email
     *                      (suscribe a la lista de lanzamiento + entrega el
     *                      recurso gratuito vía el ESP).
     *  - `live`          → shown normally. CTA is "Comprar ahora" → `paymentUrl`
     *                      if present; if `paymentUrl` is absent, no purchase
     *                      CTA is shown (informational card only).
     *  - `hidden`        → not rendered anywhere. Used for drafts/placeholders.
     */
    status: z.enum(['coming-soon', 'live', 'hidden']),

    /** Visual accent color of the card's top stripe. */
    accent: z.enum(['cyan', 'coral']).default('cyan'),

    /** Short summary (~140 chars) shown on the card. */
    summary: z.string().min(20).max(280),

    /** Price. `null` means "consultar" (no fixed price). */
    price: z.number().nullable().default(null),

    /** Currency of `price`. Site is Argentina-only for now. */
    currency: z.enum(['ARS']).default('ARS'),

    /**
     * Mercado Pago "Link de Pago" for this product (see ADR-025). Only set
     * once the owner has an active payment link. NEVER a delivery/download
     * URL — Mercado Pago handles delivery post-payment on its own side.
     */
    paymentUrl: z.url().optional(),

    /**
     * Optional override for the CTA button text. If absent, Productos.astro
     * infers a sensible default from `status`/`paymentUrl` (see table there).
     */
    ctaLabel: z.string().optional(),

    /**
     * Who/what delivers the product to the buyer/subscriber. This only
     * describes the MECHANISM — the actual delivery URL/file NEVER lives in
     * this repo (see ADR-025, ADR-027).
     *  - `lead-magnet`     → the email service (ESP) automation delivers a
     *                        free resource after signup (`coming-soon` items).
     *  - `mercadopago-auto`→ Mercado Pago auto-delivers after payment (`live`).
     *  - `manual`          → the owner delivers by hand (WhatsApp/email).
     */
    deliveryMode: z.enum(['lead-magnet', 'mercadopago-auto', 'manual']),

    /** Which audience(s) this product is for. Used for filtering and analytics. */
    audiences: z
      .array(z.enum(['profesionales', 'emprendedores', 'empresas']))
      .default([]),

    /** Manual sort key for the grid, ascending. Lower shows first. */
    order: z.number(),

    /** Metadata only — no longer used for sorting (see `order`). */
    publishedAt: z.coerce.date(),
  }),
});

// ─────────────────────────────────────────────────────────────────────────────
// Testimonials
// See: docs/adr/ADR-005-testimonial-collection.md
// ─────────────────────────────────────────────────────────────────────────────

const testimonials = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/testimonials' }),
  schema: ({ image }) =>
    z.object({
      name: z.string().min(2),
      role: z.string().min(2),
      org: z.string().min(2),
      location: z.string().optional(),
      linkedIn: z.url().optional(),

      /** Image must live in `public/testimonials/` or be co-located. */
      photo: image().optional(),

      rating: z.number().int().min(1).max(5),

      /** Date the owner has signed written consent for publication. Required. */
      consentGivenAt: z.coerce.date(),

      /** Date this testimonial went live on the site. */
      publishedAt: z.coerce.date(),

      /**
       *  - `draft`     → not shown
       *  - `approved`  → shown (subject to the section's display cap)
       *  - `archived`  → kept for history but not shown
       */
      status: z.enum(['draft', 'approved', 'archived']),
    }),
});

export const collections = { products, testimonials };
