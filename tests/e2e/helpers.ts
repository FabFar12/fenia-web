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

/**
 * What /api/kit.php answers for a kit with everything ("Tu kit", D25). Tests
 * that look at the page without buying answer the API with this.
 */
export const TU_KIT = {
  usable: true,
  kit: 'kit-ensenar-con-ia',
  kit_title: 'Enseñar con IA sin automatizar la pedagogía',
  remaining: 10,
  max: 10,
  expires_on: '07/12/2026',
  bought_on: '7/11',
  email_masked: 'a***@gmail.com',
  resources: { guide: { size: 37 }, fichas: { size: 8 }, imprimibles: { size: 8 } },
};

/** Opens /descarga with a well-formed token and the API answering `info`. */
export async function openTuKit(page: Page, info: object = TU_KIT): Promise<void> {
  await page.route('**/api/kit.php', (route) =>
    route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(info) }),
  );
  await page.goto(`/descarga/#t=${'A'.repeat(43)}`);
  await page.locator('#dl-kit').waitFor({ state: 'visible' });
}
