// "Conversar con IA sin creerle todo", phase A (ADR-030): the sales page and
// the free sample, in a real browser against a deployed site.
//  - the page shows the sample and "Avisame", the planned price, nothing to buy;
//  - at 360 px nothing scrolls sideways (most traffic comes from phones);
//  - "Probá gratis" is a full page load into the app, which runs under its own
//    policy with no violation (acceptance test 6 of the handoff), and the click
//    is counted with the page it came from (ADR-029).
import { test, expect } from '@playwright/test';

const PAGE = '/entrenamientos/conversar-con-ia/';
const SAMPLE = '/app/conversar-con-ia/muestra.html';

test('entrenamiento: fase A, muestra gratis y lista de espera, sin compra', async ({ page }) => {
  await page.goto(PAGE);
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(/Conversar con IA\s*sin creerle todo/);
  await expect(page.getByText('Entrenamiento · 6 semanas', { exact: true })).toBeVisible();

  const tryIt = page.getByRole('link', { name: /Probá gratis el día 0/ });
  await expect(tryIt).toHaveCount(3);
  for (const link of await tryIt.all()) await expect(link).toHaveAttribute('href', SAMPLE);
  const notify = page.getByRole('link', { name: 'Avisame cuando esté completo' });
  await expect(notify.first()).toHaveAttribute('href', '/recursos-gratuitos?producto=conversar-con-ia');

  await expect(page.getByText(/Precio de lanzamiento previsto:\s*\$\s?15\.000\s*\(lista \$\s?30\.000\)/)).toBeVisible();
  await expect(page.locator('#buy-form')).toHaveCount(0);
  await expect(page.getByRole('button', { name: /Comprar|Pagar/ })).toHaveCount(0);
  await expect(page.getByRole('link', { name: /^Comprar/ })).toHaveCount(0);
  await expect(page.locator('a[href*="programa.html"], a[href*="piloto.html"]')).toHaveCount(0);
  await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', /\/entrenamientos\/conversar-con-ia\/og\.jpg$/);

  // The FAQ opens and closes like any disclosure.
  const faq = page.getByText('¿Tengo que pagar alguna IA?');
  await faq.click();
  await expect(page.getByText('No. Todo funciona con las versiones gratuitas.')).toBeVisible();
});

// «Avisame» (ADR-027, E2E-04): the title comes from the product id, never from
// the URL (H-03). With a Mailerlite form connected (leadCapture.formUrl) the
// page embeds it; until then it offers WhatsApp with the training named.
test('entrenamiento: «Avisame» lleva a la lista con el título correcto', async ({ page }) => {
  await page.goto('/recursos-gratuitos?producto=conversar-con-ia&titulo=Texto%20inventado');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(/Sumate a la lista para\s+Conversar con IA sin creerle todo/);
  await expect(page.getByText('Texto inventado')).toHaveCount(0);
  const form = page.locator('#lg-iframe');
  if (await form.count()) {
    await expect(form).toHaveAttribute('src', /^https:\/\/([a-z0-9-]+\.)*mailerlite\.com\/.*producto=conversar-con-ia/);
  } else {
    const wa = page.locator('#lg-wa-link');
    await expect(wa).toHaveAttribute('href', /wa\.me\/\d+\?text=.*Conversar%20con%20IA%20sin%20creerle%20todo/);
  }
});

test('entrenamiento: a 360 px no hay desplazamiento horizontal', async ({ browser }) => {
  const context = await browser.newContext({ viewport: { width: 360, height: 780 } });
  const page = await context.newPage();
  await page.goto(PAGE);
  await page.evaluate(() => document.fonts.ready);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
  await context.close();
});

test('entrenamiento: «Probá gratis» abre la muestra entera, sin errores de CSP, y cuenta el clic', async ({ page, browserName }) => {
  // The counter skips automated browsers; pretend to be a person and keep the
  // beacon here (204, as the API answers), so nothing reaches the metrics.
  const beacons: Record<string, unknown>[] = [];
  await page.addInitScript(() => Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }));
  await page.route('**/api/hit.php', async (route) => {
    beacons.push(JSON.parse(route.request().postData() ?? '{}'));
    await route.fulfill({ status: 204 });
  });
  // Anything the policy blocks in the app (acceptance test 6).
  await page.addInitScript(() => {
    const w = window as unknown as { __csp: string[] };
    w.__csp = [];
    document.addEventListener('securitypolicyviolation', (e) => w.__csp.push(`${e.violatedDirective} ${e.blockedURI}`));
  });

  await page.goto(PAGE);
  const response = page.waitForResponse((r) => r.url().endsWith(SAMPLE));
  await page.getByRole('link', { name: /Probá gratis el día 0/ }).first().click();
  const app = await response;
  await expect(page).toHaveURL(new RegExp(`${SAMPLE.replace(/\./g, '\\.')}$`));
  expect(await app.headerValue('content-security-policy')).toMatch(/^default-src 'none'; script-src 'sha256-/);

  // The app ran: its whole screen is drawn by its own script into an empty page.
  await expect(page.getByRole('button', { name: /Empezar el día 0/ })).toBeVisible();
  // Firefox asks for /favicon.ico on its own (the app declares no icon) and the
  // app's policy blocks it: harmless, nothing of the app is missing. Anything
  // else blocked is a real problem. (Asked of the product team for the next
  // regeneration: an icon in data:, so the request disappears.)
  const blocked = await page.evaluate(() => (window as unknown as { __csp: string[] }).__csp);
  expect(blocked.filter((v) => !/^img-src https:\/\/[^/]+\/favicon\.ico$/.test(v))).toEqual([]);

  // Beacons of the page: Chromium hands them to page.route; Firefox does not.
  if (browserName === 'chromium') {
    expect(beacons).toContainEqual(expect.objectContaining({ k: 'sample', p: PAGE }));
  }
});
