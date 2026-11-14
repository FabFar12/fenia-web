// The site works on every screen: home, products grid, kit pages, support pages.
import { test, expect } from '@playwright/test';
import { catalog, money } from './helpers';

test.describe('Home', () => {
  test('carga sin errores de JavaScript', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto('/');
    await page.waitForTimeout(2000);
    expect(errors).toEqual([]);
  });

  test('sin scroll horizontal', async ({ page }) => {
    await page.goto('/');
    await page.waitForTimeout(1000);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
  });

  test('productos: columnas según la pantalla y botones dentro de su tarjeta', async ({ page }, info) => {
    await page.goto('/#productos');
    const grid = page.locator('.productos-grid');
    await expect(grid).toBeVisible();
    const columns = await grid.evaluate((g) => getComputedStyle(g).gridTemplateColumns.split(' ').length);
    expect(columns).toBe(info.project.name === 'celular' ? 1 : 3);
    const outside = await page.$$eval('.productos-grid .tilt-card a', (links) =>
      links.filter((a) => a.getBoundingClientRect().right > a.closest('.tilt-card')!.getBoundingClientRect().right + 0.5).length,
    );
    expect(outside).toBe(0);
  });
});

test.describe('Kits', () => {
  test('cada kit a la venta muestra el precio vigente y el formulario', async ({ page }) => {
    const now = Date.now();
    for (const kit of (await catalog(page)).filter((k) => k.status === 'live')) {
      await page.goto(`/kits/${kit.id}/`);
      await expect(page.locator('h1')).toHaveText(kit.title);
      await expect(page.locator('#buy-form')).toBeVisible();
      const running = kit.promo && now >= kit.promo.starts && now < kit.promo.ends;
      const expected = money(running ? kit.promo!.price : kit.price);
      await expect(page.locator('.kit-price [data-offer-price], .kit-price').first()).toContainText(expected);
    }
  });

  test('el formulario frena errores sin llegar a Mercado Pago', async ({ page }) => {
    const kit = (await catalog(page)).find((k) => k.status === 'live');
    test.skip(!kit, 'no hay kits a la venta');
    let checkoutCalls = 0;
    page.on('request', (r) => { if (r.url().includes('/api/checkout.php')) checkoutCalls++; });
    await page.goto(`/kits/${kit!.id}/`);
    await page.fill('#buy-email', 'ana@example.com');
    await page.fill('#buy-email2', 'otra@example.com');
    await page.check('#buy-terms');
    await page.click('#buy-submit');
    await expect(page.locator('#buy-error')).toHaveText('Los dos emails no coinciden.');
    await page.fill('#buy-email2', 'ana@example.com');
    await page.uncheck('#buy-terms');
    await page.click('#buy-submit');
    await expect(page.locator('#buy-error')).toContainText('aceptar los términos');
    expect(checkoutCalls).toBe(0);
  });
});

test.describe('Páginas de soporte', () => {
  test('/gracias sin código de compra', async ({ page }) => {
    await page.goto('/gracias/');
    await expect(page.getByText('No encontramos tu compra.')).toBeVisible({ timeout: 20_000 });
  });

  test('/descarga con un link mal formado y con uno inexistente', async ({ page }) => {
    await page.goto('/descarga/#t=abc');
    await expect(page.locator('#dl-error')).toContainText('no es válido');
    await page.goto('about:blank');
    await page.goto(`/descarga/#t=${'A'.repeat(42)}Q`);
    await expect(page.locator('#dl-error')).toContainText(/venció|demasiados intentos/);
    await expect(page.locator('#dl-kit')).toBeHidden();
  });

  test('"No me llegó" responde siempre lo mismo', async ({ page }) => {
    await page.goto('/no-me-llego/');
    await page.fill('input[name=email]', 'nadie-e2e@example.com');
    await page.click('form button[type=submit]');
    await expect(page.locator('main')).toContainText(/Si ese email tiene una compra|demasiados intentos/);
  });
});

test('www lleva al dominio principal', async ({ page, baseURL }) => {
  test.skip(!/\/\/fenia\.com\.ar/.test(baseURL ?? ''), 'solo en producción');
  const res = await page.request.get('https://www.fenia.com.ar/', { maxRedirects: 0 });
  expect(res.status()).toBe(301);
  expect(res.headers()['location']).toBe('https://fenia.com.ar/');
});
