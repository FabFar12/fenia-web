// Shared pieces of the E2E battery.
import type { BrowserContext, Page } from '@playwright/test';

export interface Promo {
  price: number;
  starts: number;
  ends: number;
}
export interface Kit {
  id: string;
  title: string;
  status: string;
  price: number;
  promo: Promo | null;
}

/** The built catalogue: the same source the backend charges from. */
export async function catalog(page: Page): Promise<Kit[]> {
  const res = await page.request.get('/api/catalog.json');
  const body = (await res.json()) as { products: (Omit<Kit, 'promo'> & { promo: { price: number; starts: string; ends: string } | null })[] };
  return body.products.map((p) => ({
    ...p,
    promo: p.promo ? { price: p.promo.price, starts: Date.parse(p.promo.starts), ends: Date.parse(p.promo.ends) } : null,
  }));
}

/**
 * Freezes the browser clock at `at` and makes the "server clock" agree (the
 * promo script reads it from the Date header of /api/catalog.json). Lets one
 * test show today, a Monday, the last day or after the deadline.
 */
export async function setClock(context: BrowserContext, page: Page, at: number): Promise<void> {
  await context.route('**/api/catalog.json', async (route) => {
    if (route.request().method() === 'HEAD') {
      await route.fulfill({ status: 200, headers: { date: new Date(at).toUTCString() }, body: '' });
    } else {
      await route.continue();
    }
  });
  await page.clock.setFixedTime(at);
}

/** "$ 13.000" as the site writes it (es-AR, no decimals). */
export function money(n: number): string {
  return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 }).format(n);
}

export const DAY = 86_400_000;
