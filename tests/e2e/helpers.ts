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

/** A sample answer of /api/metricas.php (ADR-029), for tests that look at the board without logging in. */
export const METRICAS = {
  range: '7d',
  from: '08/11',
  updated: '14/11 12:00',
  visits: {
    visitors: 1240, visits: 1610, views: 3020, whatsapp: 38,
    sources: [{ name: 'Instagram', n: 610, pct: 38 }, { name: 'Google', n: 354, pct: 22 }, { name: 'WhatsApp', n: 274, pct: 17 }, { name: 'Directo', n: 242, pct: 15 }, { name: 'Mail', n: 130, pct: 8 }],
    devices: [{ name: 'celular', n: 1004, pct: 81 }, { name: 'compu', n: 236, pct: 19 }],
    pages: [{ name: '/', n: 1500 }, { name: '/kits/kit-ensenar-con-ia/', n: 620 }, { name: '/kits/guia-evaluar-tiempos-ia/', n: 410 }],
    per_day: Array.from({ length: 30 }, (_, i) => ({ day: `${(i % 30) + 1}/11`, n: 20 + ((i * 37) % 60) })),
  },
  funnel: {
    total: { seen: 420, checkout: 40, paid: 12, rate: 2.9 },
    kits: [{ id: 'kit-ensenar-con-ia', title: 'Enseñar con IA sin automatizar la pedagogía', seen: 200, checkout: 20, paid: 6 }],
    since: '08/11',
  },
  sales: { count: 12, revenue: 156000, net: 143540.76, fees: 12459.24, fee_pct: 7.99, net_avg: 11961.73, fees_pending: 0, by_pay_type: [{ name: 'Dinero en cuenta', n: 9, fee_pct: 7.99 }, { name: 'Tarjeta de crédito', n: 3, fee_pct: 7.99 }], refunds: 0, failed: 1, review: 0, mail_pending: 0, per_kit: [{ name: 'Evaluar en tiempos de IA', n: 5 }, { name: 'Aprender con IA sin dejar de pensar', n: 4 }], per_day: Array.from({ length: 30 }, (_, i) => ({ day: `${(i % 30) + 1}/11`, n: i % 4 })) },
  kit_use: { buyers: 12, opened: 11, read: 9, fichas: 7, imprimibles: 3, zip: 6, guide: 4, renewed: 1 },
  recent: [{ when: '14/11 09:12', kit: 'Enseñar con IA sin automatizar la pedagogía', email: 'a***@gmail.com', amount: 13000, net: 11961.73, refunded: false, opened: true }],
  speed: [{ url: 'https://fenia.com.ar/', day: '2026-11-14', score: 88, lcp_ms: 2300, cls: 0.04, tbt_ms: 120, field_lcp_ms: null, field_inp_ms: null, field_cls: null, error: null }],
};

/** Opens /metricas with the API answered by the test: logged in as "dev", showing `METRICAS`. */
export async function openMetricas(page: Page): Promise<void> {
  await page.route('**/api/sesion.php', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: '{"user":"dev"}' }));
  await page.route('**/api/metricas.php**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(METRICAS) }));
  await page.goto('/metricas/');
  await page.locator('#mt-board').waitFor({ state: 'visible' });
}
