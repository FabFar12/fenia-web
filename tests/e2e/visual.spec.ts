// Reference screenshots of what sells: promo band, products section, kit buy
// box. The clock is frozen inside the promo so the countdown is always the
// same. A difference fails the test; after an approved visual change, refresh
// them with `npm run e2e:update` (and commit the new images in fotos/).
// Tagged @staging: the references were taken there.
import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { catalog, setClock, openTuKit, DAY } from './helpers';

const stylePath = fileURLToPath(new URL('./fotos.css', import.meta.url));

test.describe('@staging capturas de referencia', () => {
  test.beforeEach(async ({ page, context }) => {
    const kit = (await catalog(page)).find((k) => k.status === 'live' && k.promo);
    // Inside the promo when there is one; otherwise a fixed date.
    await setClock(context, page, kit ? kit.promo!.starts + 2 * DAY + 3 * 3_600_000 : Date.parse('2026-11-09T19:00:00Z'));
  });

  test('franja de la promo', async ({ page }) => {
    await page.goto('/');
    const bar = page.locator('.promo-bar');
    test.skip(!(await bar.isVisible()), 'sin franja de promo');
    await page.evaluate(() => document.fonts.ready);
    await expect(bar).toHaveScreenshot('franja.png', { stylePath });
  });

  test('sección productos', async ({ page }) => {
    await page.goto('/#productos');
    const section = page.locator('#productos');
    await section.scrollIntoViewIfNeeded();
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(1500);
    await expect(section).toHaveScreenshot('productos.png', { mask: [page.getByText(/^\d+ — /)], stylePath });
  });

  test('caja de compra del kit', async ({ page }) => {
    const kit = (await catalog(page)).find((k) => k.status === 'live');
    test.skip(!kit, 'no hay kits a la venta');
    await page.goto(`/kits/${kit!.id}/`);
    const box = page.locator('.kit-buy');
    await page.evaluate(() => document.fonts.ready);
    await expect(box).toHaveScreenshot('caja-de-compra.png', { stylePath });
  });

  test('Tu kit (datos simulados, sin comprar)', async ({ page }) => {
    await openTuKit(page);
    await page.evaluate(() => document.fonts.ready);
    await expect(page.locator('.dl-section')).toHaveScreenshot('tu-kit.png', { stylePath });
  });
});
