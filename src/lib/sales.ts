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

/**
 * Price shown for a kit with a time-limited promo (D23). The page is static,
 * so the whole schedule travels in `json` and src/scripts/offers.ts switches
 * price, badge and countdown in the browser at the exact instants, with the
 * server's clock. The backend charges with the same schedule (Catalog.php).
 */
export interface Offer {
  regular: string;
  promoPrice: string;
  label: string;
  /** Rounded down: never advertise more discount than there is. */
  percent: number;
  /** "sábado 14/11 a las 23:59" in Argentina time. */
  deadline: string;
  /** Promo running at build time (the script corrects it on load). */
  activeAtBuild: boolean;
  json: string;
}

export function offerFor(product: CollectionEntry<'products'>, now = new Date()): Offer | null {
  const { price, promo, currency } = product.data;
  if (!promo || price == null) return null;
  const deadline = new Intl.DateTimeFormat('es-AR', {
    timeZone: 'America/Argentina/Buenos_Aires', weekday: 'long', day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(promo.ends);
  const part = (type: string) => deadline.find((p) => p.type === type)?.value ?? '';
  return {
    regular: formatPrice(price, currency),
    promoPrice: formatPrice(promo.price, currency),
    label: promo.label,
    percent: Math.floor(((price - promo.price) / price) * 100),
    deadline: `${part('weekday')} ${part('day')}/${part('month')} a las ${part('hour')}:${part('minute')}`,
    activeAtBuild: now >= promo.starts && now < promo.ends,
    json: JSON.stringify({ regular: price, promo: promo.price, starts: promo.starts.getTime(), ends: promo.ends.getTime(), currency }),
  };
}

export function kitUrl(product: CollectionEntry<'products'>): string {
  return `/kits/${product.id}/`;
}

/** "Avisame" link for kits that are not on sale yet. Title comes from the id, never the URL (H-03). */
export function notifyUrl(product: CollectionEntry<'products'>): string {
  return `/recursos-gratuitos?producto=${encodeURIComponent(product.id)}`;
}
