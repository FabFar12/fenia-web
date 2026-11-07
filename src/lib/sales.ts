// Which kits can be bought on this build (docs/ventas, ADR-028).
//
// Production: only `live` kits. Staging is built with PUBLIC_SALES_PREVIEW=true
// so `coming-soon` kits can also be bought there (Mercado Pago sandbox),
// without changing what the real site shows. The catalogue the PHP backend
// reads (src/pages/api/catalog.json.ts) follows the same rule.
import type { CollectionEntry } from 'astro:content';

export const salesPreview = import.meta.env.PUBLIC_SALES_PREVIEW === 'true';

export function isPurchasable(product: CollectionEntry<'products'>): boolean {
  const { status, price } = product.data;
  const sellable = status === 'live' || (salesPreview && status === 'coming-soon');
  return sellable && typeof price === 'number' && price > 0;
}

export function formatPrice(price: number, currency: 'ARS' = 'ARS'): string {
  return new Intl.NumberFormat('es-AR', { style: 'currency', currency, maximumFractionDigits: 0 }).format(price);
}

export function kitUrl(product: CollectionEntry<'products'>): string {
  return `/kits/${product.id}/`;
}

/** "Avisame" link for kits that are not on sale yet. Title comes from the id, never the URL (H-03). */
export function notifyUrl(product: CollectionEntry<'products'>): string {
  return `/recursos-gratuitos?producto=${encodeURIComponent(product.id)}`;
}
