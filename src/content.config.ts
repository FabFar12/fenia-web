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

    /**
     * Category — drives the badge. `Entrenamiento` (ADR-030) is a program used
     * in the browser: its page is /entrenamientos/<id>/ (copy in
     * src/content/trainings/<id>.yaml) instead of /kits/<id>/, and it is not
     * listed in the kits grid.
     */
    type: z.enum(['Guía', 'Caja de herramientas', 'Método', 'Curso', 'Workshop', 'Entrenamiento', 'Otro']),

    /**
     * Lifecycle state. Controls visibility and CTA behavior. See Productos.astro.
     *  - `coming-soon`   → shown with a "Próximamente" badge. CTA captures email
     *                      (suscribe a la lista de lanzamiento + entrega el
     *                      recurso gratuito vía el ESP).
     *  - `sample`        → "muestra disponible" (ADR-030, phase A): not on sale
     *                      yet; the page offers the free sample (`sampleUrl`)
     *                      and "Avisame cuando esté completo". The sales API
     *                      never charges it (only `live` is sold).
     *  - `live`          → shown normally. CTA is "Comprar ahora" → `paymentUrl`
     *                      if present; if `paymentUrl` is absent, no purchase
     *                      CTA is shown (informational card only).
     *  - `hidden`        → not rendered anywhere. Used for drafts/placeholders.
     */
    status: z.enum(['coming-soon', 'sample', 'live', 'hidden']),

    /** Visual accent color of the card's top stripe. */
    accent: z.enum(['cyan', 'coral']).default('cyan'),

    /** Short summary (~140 chars) shown on the card. */
    summary: z.string().min(20).max(280),

    /** Price. `null` means "consultar" (no fixed price). */
    price: z.number().nullable().default(null),

    /** Currency of `price`. Site is Argentina-only for now. */
    currency: z.enum(['ARS']).default('ARS'),

    /**
     * Time-limited price (docs/ventas D23). While `starts <= now < ends` the
     * kit costs `promo.price` instead of `price`, and the card, the kit page,
     * the countdown and Mercado Pago switch on their own at those instants
     * (no deploy needed). `ends` is a real deadline: when it passes, `price`
     * applies again. Never extend it silently (consumer law); `price` must be
     * the genuine regular price.
     */
    promo: z
      .object({
        price: z.number().positive(),
        label: z.string().min(3).default('Precio de lanzamiento'),
        starts: z.coerce.date(),
        ends: z.coerce.date(),
      })
      .refine((p) => p.ends > p.starts, { message: 'promo.ends must be after promo.starts' })
      .optional(),

    /**
     * Free sample of a training (ADR-030): a self-contained app under
     * public/app/, opened with "Probá gratis". Required with `status: sample`.
     */
    sampleUrl: z.string().regex(/^\/app\/[a-z0-9-]+\/[a-z0-9-]+\.html$/).optional(),

    /**
     * Phase A only (`status: sample`): the launch price announced as planned,
     * shown as "Precio de lanzamiento previsto". Nothing is charged with it.
     * When sales open, the launch price goes in `promo`, with a real deadline.
     */
    plannedPrice: z.number().positive().optional(),

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
     *  - `web-app`         → used in the browser on this site (ADR-030); buyers
     *                        get access once phase B (buyer login) exists.
     */
    deliveryMode: z.enum(['lead-magnet', 'mercadopago-auto', 'manual', 'web-app']),

    /** Which audience(s) this product is for. Used for filtering and analytics. */
    audiences: z
      .array(z.enum(['profesionales', 'emprendedores', 'empresas']))
      .default([]),

    /** Manual sort key for the grid, ascending. Lower shows first. */
    order: z.number(),

    /** Metadata only — no longer used for sorting (see `order`). */
    publishedAt: z.coerce.date(),
  })
    .refine((p) => !p.promo || (p.price != null && p.promo.price < p.price), {
      message: 'promo.price must be lower than the regular price',
    })
    .refine((p) => p.status !== 'sample' || p.sampleUrl !== undefined, {
      message: 'status "sample" needs a sampleUrl',
    })
    .refine((p) => p.plannedPrice === undefined || (p.status === 'sample' && p.price != null && p.plannedPrice < p.price), {
      message: 'plannedPrice is for status "sample" only, and must be lower than the regular price',
    })
    // Fail closed (ADR-030): a training on sale needs buyer access (phase B,
    // buyer login), which does not exist yet. Until then the API must never
    // charge one, so `live` is refused at build time.
    .refine((p) => p.type !== 'Entrenamiento' || p.status !== 'live', {
      message: 'a training cannot be "live" until buyer access exists (ADR-030, phase B)',
    }),
});

// ─────────────────────────────────────────────────────────────────────────────
// Training pages (ADR-030)
// The copy of each training's sales page, /entrenamientos/<id>/. One YAML file
// per training, named like its product (src/content/products/<id>.md), which
// keeps the price, status and sample link. Bracketed [PENDIENTE] items from
// the product team are never published: they stay out of this file.
// ─────────────────────────────────────────────────────────────────────────────

const titled = z.object({ title: z.string().min(2), text: z.string().min(2) });

const trainings = defineCollection({
  loader: glob({ pattern: '**/*.yaml', base: './src/content/trainings' }),
  schema: ({ image }) =>
    z.object({
      seo: z.object({ title: z.string().min(10), description: z.string().min(50).max(300) }),
      /** Button labels. "sample" opens the free sample; its clicks are counted (ADR-029). */
      ctas: z.object({ sample: z.string(), notify: z.string() }),
      hero: z.object({
        pill: z.string(),
        titleLine1: z.string(),
        titleLine2: z.string(),
        subtitle: z.string(),
        note: z.string(),
        images: z.array(z.object({ src: image(), alt: z.string().min(10) })).length(2),
      }),
      problem: z.object({ title: z.string(), text: z.string(), fact: z.string() }),
      how: z.object({
        title: z.string(),
        cards: z.array(titled).min(2),
        session: z.object({
          title: z.string(),
          steps: z.array(titled).min(3),
          images: z.array(z.object({ src: image(), alt: z.string().min(10) })).min(1),
        }),
      }),
      outcomes: z.object({ title: z.string(), items: z.array(z.string()).min(3), closing: z.string() }),
      journey: z.object({ title: z.string(), rows: z.array(z.object({ week: z.string(), focus: z.string() })).min(3) }),
      why: z.object({
        title: z.string(),
        text: z.string(),
        items: z.array(titled).min(1),
        image: z.object({ src: image(), alt: z.string().min(10) }),
        noPromise: titled,
      }),
      includes: z.object({ title: z.string(), items: z.array(z.string()).min(3) }),
      audience: z.object({
        title: z.string(),
        yes: titled,
        no: titled,
        others: z.object({ before: z.string(), linkText: z.string(), after: z.string(), href: z.string().startsWith('/') }),
      }),
      price: z.object({ title: z.string(), note: z.string() }),
      faq: z.object({ title: z.string(), items: z.array(z.object({ q: z.string(), a: z.string() })).min(1) }),
      closing: z.object({ title: z.string(), text: z.string() }),
      /**
       * Featured card above the kits in the home's Productos section (approved
       * 2026-11-18). One button, to the sales page: the page is where visits and
       * "Probá gratis" clicks are measured (ADR-030). Without this block, no card.
       */
      card: z
        .object({
          badge: z.string(),
          facts: z.array(z.string()).min(1).max(4),
          cta: z.string(),
          /** Label above the kits once a training sits on top of them. */
          kitsLabel: z.string(),
        })
        .optional(),
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

export const collections = { products, trainings, testimonials };
