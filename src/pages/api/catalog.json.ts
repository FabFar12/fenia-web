// Sales catalogue for the PHP backend in public/api (ADR-028).
// Single source of truth for price: the product .md files. PHP reads the
// built dist/api/catalog.json from disk and never trusts a price sent by the
// browser (docs/ventas/threat-model.md A8, C12). Hidden products are never
// emitted. Contract checked in CI by scripts/verify-catalog.mjs (test U-31),
// which runs on the production build (no sales preview).
import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import { isPurchasable } from '../../lib/sales';

export const GET: APIRoute = async () => {
  const products = (await getCollection('products'))
    .filter((p) => p.data.status !== 'hidden')
    .sort((a, b) => a.data.order - b.data.order)
    .map((p) => ({
      id: p.id,
      title: p.data.title,
      // On a staging build (PUBLIC_SALES_PREVIEW) coming-soon kits are sold
      // in the sandbox, so the backend must see them as live.
      status: isPurchasable(p) ? 'live' : p.data.status,
      price: p.data.price,
      currency: p.data.currency,
      // The backend charges promo.price while starts <= now < ends (D23).
      promo: p.data.promo
        ? {
            price: p.data.promo.price,
            label: p.data.promo.label,
            starts: p.data.promo.starts.toISOString(),
            ends: p.data.promo.ends.toISOString(),
          }
        : null,
    }));

  return new Response(JSON.stringify({ products }, null, 2), {
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
};
