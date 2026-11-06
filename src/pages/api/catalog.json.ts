// Sales catalogue for the PHP backend in public/api (ADR-028).
// Single source of truth for price: the product .md files. PHP reads the
// built dist/api/catalog.json from disk and never trusts a price sent by the
// browser (docs/ventas/threat-model.md A8, C12). Hidden products are never
// emitted. Contract checked in CI by scripts/verify-catalog.mjs (test U-31).
import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';

export const GET: APIRoute = async () => {
  const products = (await getCollection('products'))
    .filter((p) => p.data.status !== 'hidden')
    .sort((a, b) => a.data.order - b.data.order)
    .map((p) => ({
      id: p.id,
      title: p.data.title,
      status: p.data.status,
      price: p.data.price,
      currency: p.data.currency,
    }));

  return new Response(JSON.stringify({ products }, null, 2), {
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
};
