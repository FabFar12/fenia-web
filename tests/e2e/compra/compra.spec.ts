// A full purchase in the Mercado Pago sandbox, on staging (npm run e2e:compra):
// kit page → MP test card → back to /gracias with the link → download the ZIP
// → refund through MP's API → the link stops working. The delivery mail goes
// to E2E_EMAIL (default kits@fenia.com.ar, a mailbox we own).
// Needs the TEST access token in ~/fenia-secrets/mp-test-token.txt (or
// FENIA_SECRETS_DIR); it is read here and never printed.
import { test, expect, type Page } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { catalog } from '../helpers';

const tokenFile = join(process.env.FENIA_SECRETS_DIR ?? join(homedir(), 'fenia-secrets'), 'mp-test-token.txt');
const email = process.env.E2E_EMAIL ?? 'kits@fenia.com.ar';
// MP's checkout refuses browsers that announce themselves as headless.
test.use({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36 Edg/149.0.0.0' });

/** Types into one of MP's secure card fields (each lives in its own iframe). */
async function secureField(page: Page, name: string, placeholder: string, value: string) {
  for (const frame of page.frames()) {
    const input = await frame.$(`input[name=${name}][placeholder="${placeholder}"]`);
    if (input) {
      await input.focus();
      await page.keyboard.type(value, { delay: 70 });
      return;
    }
  }
  throw new Error(`MP field ${name} not found`);
}

test('compra completa en el sandbox: pago → entrega → descarga → reembolso', async ({ page, baseURL }) => {
  test.skip(!existsSync(tokenFile), `falta el token de prueba (${tokenFile})`);
  const token = readFileSync(tokenFile, 'utf8').trim();
  const kit = (await catalog(page)).find((k) => k.status === 'live');
  test.skip(!kit, 'no hay kits a la venta en staging');

  let orderId = '';
  page.on('request', (r) => {
    const m = /order_id=(ORD[A-Z0-9]+)/.exec(r.url());
    if (m && !orderId) orderId = m[1];
  });

  // 1. Our checkout.
  await page.goto(`/kits/${kit!.id}/`);
  await page.fill('#buy-email', email);
  await page.fill('#buy-email2', email);
  await page.check('#buy-terms');
  await Promise.all([page.waitForURL(/mercadopago\.com/, { timeout: 30_000 }), page.click('#buy-submit')]);

  // 2. Mercado Pago, as a guest with the approved test card (holder APRO).
  await page.waitForTimeout(5000);
  await page.getByText('Tarjeta', { exact: true }).click();
  await page.getByRole('button', { name: 'Continuar' }).click();
  await page.waitForTimeout(6000);
  await secureField(page, 'cardNumber', '0000 0000 0000 0000', '5031755734530604');
  await secureField(page, 'expirationDate', 'MM/AA', '1130');
  await secureField(page, 'securityCode', '000', '123');
  await page.fill('#cardholderName', 'APRO');
  await page.fill('#cardholderIdentificationNumber', '12345678');
  for (let i = 0; i < 8 && !/approved|rejected|staging\.fenia/.test(page.url()); i++) {
    if (page.url().includes('/installments')) {
      const one = page.getByText(/^1x/).first();
      if (await one.count()) await one.click({ force: true }).catch(() => {});
      await page.waitForTimeout(1500);
    }
    const next = page.getByRole('button', { name: /^(Continuar|Pagar|Confirmar|Pagar \$.*)$/ }).first();
    if (await next.count()) await next.click({ force: true }).catch(() => {});
    await page.waitForTimeout(4500);
  }
  expect(page.url(), 'el pago no se aprobó').not.toMatch(/rejected/);
  expect(orderId, 'no se vio el número de orden de MP').not.toBe('');

  // 3. Back on /gracias (auto return), the download link appears.
  await page.waitForURL(/staging\.fenia\.com\.ar\/gracias/, { timeout: 60_000 });
  await expect(page.locator('#state-paid')).toBeVisible({ timeout: 70_000 });
  const href = await page.locator('#download-link').getAttribute('href');
  expect(href).toMatch(/^\/descarga\/#t=[A-Za-z0-9_-]{43}$/);
  const dlToken = href!.split('#t=')[1];

  // 4. Download: the guide PDF on its own (when the kit has one), then the
  //    whole ZIP. Real files, one download counted each.
  await page.goto(href!);
  const meta = page.locator('#dl-meta');
  await expect(meta).toContainText('Te quedan 5 de 5');
  let left = 5;
  if (await page.locator('#dl-guide').isVisible()) {
    const [guide] = await Promise.all([page.waitForEvent('download'), page.click('#dl-guide')]);
    expect(readFileSync((await guide.path())!).subarray(0, 4).toString()).toBe('%PDF');
    await expect(meta).toContainText(`Te quedan ${--left} de 5`);
  }
  const [download] = await Promise.all([page.waitForEvent('download'), page.click('#dl-zip')]);
  expect(readFileSync((await download.path())!).subarray(0, 2).toString()).toBe('PK');
  await expect(meta).toContainText(`Te quedan ${--left} de 5`);

  // 5. Refund through MP's API → the webhook revokes the link on its own.
  // Right after paying, MP can still answer 422 while the payment settles:
  // retry for a while with the same idempotency key.
  const key = randomUUID();
  let refund = { status: 0, body: '' };
  for (let attempt = 0; attempt < 12; attempt++) {
    const res = await page.request.post(`https://api.mercadopago.com/v1/orders/${orderId}/refund`, {
      headers: { Authorization: `Bearer ${token}`, 'X-Idempotency-Key': key, 'Content-Type': 'application/json' },
    });
    refund = { status: res.status(), body: (await res.text()).slice(0, 300) };
    if (refund.status < 300) break;
    await page.waitForTimeout(10_000);
  }
  expect(refund.status, `MP no aceptó el reembolso: ${refund.body}`).toBeLessThan(300);
  await expect
    .poll(async () => {
      const r = await page.request.post('/api/download.php', { data: { token: dlToken, info: true }, headers: { Origin: baseURL! } });
      return ((await r.json()) as { usable: boolean }).usable;
    }, { timeout: 60_000, intervals: [3000] })
    .toBe(false);
  await page.goto('about:blank');
  await page.goto(href!);
  await expect(page.locator('#dl-error')).toContainText('venció o ya no tiene descargas');
});
