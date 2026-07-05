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
     * Lifecycle state. Controls visibility and CTA behavior.
     *  - `draft`         → hidden everywhere, kept for editing
     *  - `coming-soon`   → shown with a "Próximamente" badge, CTA is "Avisame cuando esté listo"
     *  - `live`          → shown normally, CTA uses `cta.label` / `cta.href`
     *  - `archived`      → hidden from public, kept for history
     */
    status: z.enum(['draft', 'coming-soon', 'live', 'archived']),

    /** Visual accent color of the card's top stripe. */
    accent: z.enum(['cyan', 'coral']).default('cyan'),

    /** Short summary (~140 chars) shown on the card. */
    summary: z.string().min(20).max(280),

    /** Price in ARS. `null` means "consultar" (no fixed price). */
    price: z.number().nullable().default(null),

    /** Call-to-action button on the card. */
    cta: z.object({
      label: z.string(),
      href: z.url(),
    }),

    /** Which audience(s) this product is for. Used for filtering and analytics. */
    audiences: z
      .array(z.enum(['profesionales', 'emprendedores', 'empresas']))
      .default([]),

    /** Sort order: oldest first; newer items appear later on the grid. */
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
