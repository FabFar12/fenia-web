// Launch promo (docs/ventas D23) at any moment of its life, with a simulated
// clock: running, last day and after the deadline. Reads the promo dates from
// the catalogue, so it keeps working when they change; skips if there is none.
import { test, expect } from '@playwright/test';
import { catalog, money, setClock, DAY, type Kit } from './helpers';

const artDate = (t: number) => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Argentina/Buenos_Aires' }).format(t);
const daysBetween = (a: string, b: string) => Math.round((Date.parse(`${b}T00:00:00Z`) - Date.parse(`${a}T00:00:00Z`)) / DAY);
const two = (n: number) => String(n).padStart(2, '0');

/** What the countdown must say at `at` (same rules as src/scripts/offers.ts). */
function expected(kit: Kit, at: number) {
  const p = kit.promo!;
  const total = daysBetween(artDate(p.starts), artDate(p.ends)) + 1;
  const day = Math.min(total, daysBetween(artDate(p.starts), artDate(at)) + 1);
  const secs = Math.floor((p.ends - at) / 1000);
  const days = Math.floor(secs / 86400);
  const clock = `${Math.floor((secs % 86400) / 3600)} h ${two(Math.floor((secs % 3600) / 60))} min ${two(secs % 60)} s`;
  return { total, day, big: days > 0 ? `${days} ${days === 1 ? 'día' : 'días'}` : clock, small: days > 0 ? `y ${clock}` : '' };
}

let kit: Kit | undefined;
test.beforeEach(async ({ page }) => {
  kit = (await catalog(page)).find((k) => k.status === 'live' && k.promo);
  test.skip(!kit, 'no hay promo en el catálogo');
});

for (const [name, when] of [
  ['primer día', (k: Kit) => k.promo!.starts + 3_600_000],
  ['día 3', (k: Kit) => k.promo!.starts + 2 * DAY + 3 * 3_600_000],
  ['último día', (k: Kit) => k.promo!.ends - 6 * 3_600_000],
] as const) {
  test(`promo corriendo: ${name}`, async ({ page, context }) => {
    const at = when(kit!);
    const want = expected(kit!, at);
    await setClock(context, page, at);
    await page.goto('/#productos');

    const bar = page.locator('.promo-bar');
    await expect(bar).toBeVisible();
    await expect(bar).toContainText(want.big);
    await expect(bar).toContainText(want.day === want.total ? '¡Último día! Quedan' : 'Quedan');

    const banner = page.locator('.promo-card');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText(`Oferta de ${want.total} días`);
    await expect(banner).toContainText(`Día ${want.day} de ${want.total}`);
    await expect(banner.locator('[data-offer-big]')).toHaveText(want.big);
    if (want.small) await expect(banner.locator('[data-offer-small]')).toHaveText(want.small);

    const card = page.locator('.productos-grid .tilt-card').first();
    await expect(card.locator('s')).toHaveText(money(kit!.price));
    await expect(card.locator('[data-offer-price]').first()).toHaveText(money(kit!.promo!.price));
    await expect(card).toContainText(`Ahorrás ${money(kit!.price - kit!.promo!.price)}`);
    await expect(card.locator('a')).toContainText('% OFF');
  });
}

test('promo vencida: todo vuelve al precio normal, solo', async ({ page, context }) => {
  await setClock(context, page, kit!.promo!.ends + 5_000);
  await page.goto('/#productos');
  await expect(page.locator('.promo-bar')).toBeHidden();
  await expect(page.locator('.promo-card')).toBeHidden();
  const card = page.locator('.productos-grid .tilt-card').first();
  await expect(card.locator('[data-offer-price]').first()).toHaveText(money(kit!.price));
  await expect(card.locator('s')).toBeHidden();
  await expect(card.locator('a')).not.toContainText('OFF', { useInnerText: true });

  await page.goto(`/kits/${kit!.id}/`);
  await expect(page.locator('.kit-price [data-offer-price]')).toHaveText(money(kit!.price));
  await expect(page.locator('.kit-price-regular')).toBeHidden();
  await expect(page.locator('.kit-left')).toBeHidden();
});
