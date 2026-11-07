// Attacks on the sales API (ADR-028), black box against a deployed site.
// IDs refer to docs/ventas/test-plan.md and docs/ventas/threat-model.md.
//
// ✱ tests are safe on production: they only send requests the server must
// reject (nothing is bought, no mail is sent). They need a configured API
// (/api/health.php → ok) and skip otherwise. The rest need MODE=staging.
//
// Optional, staging only:
//   DOWNLOAD_TOKEN=<43 chars>  token of a sandbox purchase (after #t= in the mail)
//   SPEND_TOKEN=1              also spends its downloads to check the 6th (S-13)
//   RATE_TESTS=1               runs S-16, which blocks checkouts from your IP for an hour
import { describe, it, before } from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { request, findLeak, skipWithoutTarget, stagingOnly, BASE_URL } from './config.mjs';

const json = (path, body, headers = {}) =>
  request(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...headers },
    body: JSON.stringify(body),
  });

const form = (path, fields) =>
  request(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(fields).toString(),
  });

const randomToken = () => randomBytes(32).toString('base64url');
const randomRef = () => randomBytes(16).toString('hex');
const buyer = () => `test-${randomBytes(4).toString('hex')}@example.com`;

const checkoutBody = (overrides = {}) => {
  const email = buyer();
  return { kit: 'guia-evaluar-tiempos-ia', email, email_confirm: email, accept_terms: true, website: '', ...overrides };
};

/** Rejected and nothing created: never a checkout URL, never a 5xx except "unavailable". */
function assertNoCheckout(res) {
  assert.ok(res.status !== 200, `status ${res.status}`);
  assert.ok(![500, 502].includes(res.status), `status ${res.status}`);
  assert.doesNotMatch(res.body, /checkout_url/);
  assert.equal(findLeak(res.body), null, 'la respuesta filtra contenido sensible');
}

let apiReady = false;
let salesOn = false;
before(async () => {
  if (!BASE_URL) return;
  const res = await request('/api/health.php');
  apiReady = res.status === 200 && /"ok":true/.test(res.body);
  salesOn = /"sales":true/.test(res.body);
});
const needsApi = (t) => {
  if (!apiReady) t.skip('la API no está configurada en este entorno (/api/health.php)');
  return apiReady;
};

describe('Webhook', { skip: skipWithoutTarget }, () => {
  it('S-01 ✱ POST sin firma → 401 sin efectos', async (t) => {
    if (!needsApi(t)) return;
    const res = await json('/api/webhook.php?data.id=ORD01TEST&type=order', { type: 'order', data: { id: 'ORD01TEST' } });
    assert.equal(res.status, 401);
    assert.equal(findLeak(res.body), null);
  });

  it('S-02 ✱ POST con firma inventada → 401', async (t) => {
    if (!needsApi(t)) return;
    const res = await json(
      '/api/webhook.php?data.id=ORD01TEST&type=order',
      { type: 'order', data: { id: 'ORD01TEST' } },
      { 'x-signature': `ts=${Date.now()},v1=${'0'.repeat(64)}`, 'x-request-id': 'forged' },
    );
    assert.equal(res.status, 401);
  });

  it('S-03 firma válida + pago inexistente → 200 sin entrega', { todo: 'necesita la clave secreta: cubierto por ShopTest y por INT en staging' });
  it('S-04 el mismo aviso real 5 veces → una sola entrega', { todo: 'INT en staging: reenviar el aviso desde el panel de MP' });

  it('GET al webhook → 405', async (t) => {
    if (!needsApi(t)) return;
    assert.equal((await request('/api/webhook.php')).status, 405);
  });
});

describe('Estado de la orden y /gracias', { skip: skipWithoutTarget }, () => {
  it('S-05 ✱ código de orden inexistente o mal formado → sin link', async (t) => {
    if (!needsApi(t)) return;
    const unknown = await request(`/api/status.php?ref=${randomRef()}`);
    assert.equal(unknown.status, 404);
    assert.doesNotMatch(unknown.body, /download_url/);
    for (const ref of ['abc', `${randomRef()}0`, `${randomRef().toUpperCase()}`, '../../etc/passwd']) {
      const res = await request(`/api/status.php?ref=${encodeURIComponent(ref)}`);
      assert.equal(res.status, 400, ref);
      assert.doesNotMatch(res.body, /download_url/);
    }
  });

  it('S-06 ✱ /gracias?status=approved escrito a mano → sin link de descarga', async () => {
    const res = await request('/gracias/?status=approved&payment_id=123&ref=' + randomRef());
    assert.equal(res.status, 200);
    assert.doesNotMatch(res.body, /\/descarga\/#t=[A-Za-z0-9_-]{43}/);
  });
});

describe('Descarga', { skip: skipWithoutTarget }, () => {
  it('S-07/S-08 ✱ tokens al azar → todos rechazados igual, y la IP queda limitada', async (t) => {
    if (!needsApi(t)) return;
    const statuses = new Set();
    const bodies = new Set();
    for (let i = 0; i < 25; i++) {
      const res = await json('/api/download.php', { token: randomToken(), info: true });
      statuses.add(res.status);
      bodies.add(res.body);
    }
    assert.deepEqual([...statuses].filter((s) => ![404, 429].includes(s)), [], `estados: ${[...statuses]}`);
    assert.ok(statuses.has(429), 'después de 20 fallos la IP debería recibir 429');
    assert.deepEqual([...bodies].filter((b) => b !== '{"usable":false}' && !b.includes('rate_limited')), []);
  });

  it('S-10 ✱ path traversal → rechazado, nunca un archivo', async (t) => {
    if (!needsApi(t)) return;
    const tokens = ['../../config.php', '..%2F..%2Fconfig.php', '%252e%252e%252fconfig.php', '../_lib/Config.php', 'kit-ensenar-con-ia.zip'];
    for (const token of tokens) {
      const res = await form('/api/download.php', { token });
      assert.ok([400, 404, 429].includes(res.status), `${token}: ${res.status}`);
      assert.notEqual(res.headers.get('content-type'), 'application/zip');
      assert.equal(findLeak(res.body), null);
    }
    for (const path of ['/api/download.php?file=../_lib/Config.php', '/api/../api/_lib/Config.php', '/api/%2e%2e/api/_lib/Config.php']) {
      const res = await request(path);
      assert.ok([400, 403, 404, 405].includes(res.status), `${path}: ${res.status}`);
      assert.equal(findLeak(res.body), null);
    }
  });

  it('S-18 GET/HEAD al link del mail → contador intacto', { skip: stagingOnly }, async (t) => {
    const token = process.env.DOWNLOAD_TOKEN ?? '';
    if (!token) return t.skip('definí DOWNLOAD_TOKEN (de una compra de prueba)');
    const info = async () => JSON.parse((await json('/api/download.php', { token, info: true })).body);
    const before = await info();
    assert.equal(before.usable, true);
    for (const method of ['GET', 'HEAD']) {
      await request(`/descarga/#t=${token}`, { method });
      assert.equal((await request('/api/download.php', { method })).status, 405);
    }
    assert.equal((await info()).remaining, before.remaining);
  });

  it('S-13 sexta descarga → rechazada', { skip: stagingOnly }, async (t) => {
    const token = process.env.DOWNLOAD_TOKEN ?? '';
    if (!token || process.env.SPEND_TOKEN !== '1') return t.skip('definí DOWNLOAD_TOKEN y SPEND_TOKEN=1 (gasta las descargas)');
    const { remaining } = JSON.parse((await json('/api/download.php', { token, info: true })).body);
    for (let i = 0; i < remaining; i++) {
      const res = await form('/api/download.php', { token });
      assert.equal(res.status, 200);
      assert.equal(res.headers.get('content-type'), 'application/zip');
    }
    const extra = await form('/api/download.php', { token });
    assert.equal(extra.status, 410);
  });

  it('S-14 link vencido → rechazado', { todo: 'cubierto por ShopTest (reloj simulado): en staging habría que esperar 30 días' });
});

describe('Checkout', { skip: skipWithoutTarget }, () => {
  it('S-12 price/amount/unit_price en el POST → ignorados', { todo: 'cubierto por ShopTest; en staging se ve el monto en la pantalla de MP' });

  it('S-17 ✱ campo trampa completado → rechazado sin crear nada', async (t) => {
    if (!needsApi(t)) return;
    assertNoCheckout(await json('/api/checkout.php', checkoutBody({ website: 'https://spam.example' })));
  });

  it('S-19 ✱ kit inexistente o inválido → rechazado sin crear nada', async (t) => {
    if (!needsApi(t)) return;
    for (const kit of ['kit-inexistente', '../config', 'KIT-ENSENAR-CON-IA', '', null, ['guia-evaluar-tiempos-ia']]) {
      const res = await json('/api/checkout.php', checkoutBody({ kit }));
      assertNoCheckout(res);
      if (salesOn) assert.equal(res.status, 404, JSON.stringify(kit));
    }
  });

  it('S-21 ✱ email con \\r\\n → rechazado', async (t) => {
    if (!needsApi(t)) return;
    const email = 'ana@example.com\r\nBcc: victima@example.com';
    const res = await json('/api/checkout.php', checkoutBody({ email, email_confirm: email }));
    assertNoCheckout(res);
    if (salesOn) assert.equal(res.status, 400);
  });

  it('S-23 ✱ POST desde otro origen → 403', async (t) => {
    if (!needsApi(t)) return;
    for (const path of ['/api/checkout.php', '/api/resend.php', '/api/download.php']) {
      const res = await json(path, checkoutBody(), { Origin: 'https://evil.example' });
      assert.equal(res.status, 403, path);
      assert.doesNotMatch(res.body, /checkout_url/);
    }
  });

  it('GET al checkout → 405', async (t) => {
    if (!needsApi(t)) return;
    assert.equal((await request('/api/checkout.php')).status, 405);
  });

  it('checkout válido → link de Mercado Pago', { skip: stagingOnly }, async (t) => {
    if (!needsApi(t)) return;
    const res = await json('/api/checkout.php', checkoutBody());
    assert.equal(res.status, 200, res.body);
    const { checkout_url: url } = JSON.parse(res.body);
    assert.match(new URL(url).hostname, /(^|\.)mercadopago\.com(\.ar)?$/);
  });

  it('S-16 más de 10 checkouts por IP en una hora → 429', { skip: stagingOnly }, async (t) => {
    if (process.env.RATE_TESTS !== '1') return t.skip('definí RATE_TESTS=1 (bloquea tus compras de prueba por una hora)');
    let limited = false;
    for (let i = 0; i < 12 && !limited; i++) {
      limited = (await json('/api/checkout.php', checkoutBody())).status === 429;
    }
    assert.ok(limited, 'nunca respondió 429');
  });
});

describe('No me llegó', { skip: skipWithoutTarget }, () => {
  it('S-15 ✱ email sin compras → la misma respuesta de siempre', async (t) => {
    if (!needsApi(t)) return;
    const res = await json('/api/resend.php', { email: buyer(), website: '' });
    assert.ok([200, 429].includes(res.status), `status ${res.status}`);
    if (res.status === 200) assert.equal(res.body, '{"ok":true}');
  });
});

describe('Inyección', { skip: skipWithoutTarget }, () => {
  it('S-20 ✱ payloads SQL en todos los campos → rechazados, sin 500', async (t) => {
    if (!needsApi(t)) return;
    const sql = ["' OR '1'='1", "1; DROP TABLE orders; --", '" OR ""="', "' UNION SELECT token_hash FROM download_tokens --"];
    for (const payload of sql) {
      const responses = [
        await request(`/api/status.php?ref=${encodeURIComponent(payload)}`),
        await json('/api/checkout.php', checkoutBody({ kit: payload })),
        await json('/api/checkout.php', checkoutBody({ email: `a${payload}@example.com`, email_confirm: `a${payload}@example.com` })),
        await json('/api/download.php', { token: payload, info: true }),
        await json('/api/resend.php', { email: payload }),
      ];
      for (const res of responses) {
        assert.ok(res.status < 500 || res.status === 503, `status ${res.status} con ${payload}`);
        assert.doesNotMatch(res.body, /checkout_url|download_url|"usable":true|SQLSTATE/);
        assert.equal(findLeak(res.body), null);
      }
    }
  });
});
