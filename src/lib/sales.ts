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
  /** "$ 17.000" */
  savings: string;
  /** Calendar days the promo spans in Argentina, both ends included (7/11 → 16/11 = 10). */
  totalDays: number;
  /** "del sábado 7/11 al lunes 16/11" */
  period: string;
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
  const dayOf = (d: Date) => {
    const p = new Intl.DateTimeFormat('es-AR', { timeZone: AR_TZ, weekday: 'long', day: 'numeric', month: 'numeric' }).formatToParts(d);
    const get = (type: string) => p.find((x) => x.type === type)?.value ?? '';
    return `${get('weekday')} ${get('day')}/${get('month')}`;
  };
  const firstDay = artDate(promo.starts);
  return {
    regular: formatPrice(price, currency),
    promoPrice: formatPrice(promo.price, currency),
    label: promo.label,
    percent: Math.floor(((price - promo.price) / price) * 100),
    savings: formatPrice(price - promo.price, currency),
    totalDays: daysBetween(firstDay, artDate(promo.ends)) + 1,
    period: `del ${dayOf(promo.starts)} al ${dayOf(promo.ends)}`,
    deadline: `${part('weekday')} ${part('day')}/${part('month')} a las ${part('hour')}:${part('minute')}`,
    activeAtBuild: now >= promo.starts && now < promo.ends,
    json: JSON.stringify({ regular: price, promo: promo.price, starts: promo.starts.getTime(), ends: promo.ends.getTime(), firstDay, currency }),
  };
}

const AR_TZ = 'America/Argentina/Buenos_Aires';

/** "2026-11-07": the calendar date in Argentina. */
export function artDate(d: Date): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: AR_TZ }).format(d);
}

/** Whole calendar days from one "YYYY-MM-DD" to another. */
export function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000);
}

export function kitUrl(product: CollectionEntry<'products'>): string {
  return `/kits/${product.id}/`;
}

/** "Avisame" link for kits that are not on sale yet. Title comes from the id, never the URL (H-03). */
export function notifyUrl(product: CollectionEntry<'products'>): string {
  return `/recursos-gratuitos?producto=${encodeURIComponent(product.id)}`;
}
