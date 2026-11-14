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
      // 403: Hostinger's firewall stops `../` before PHP sees it.
      assert.ok([400, 403].includes(res.status), `${ref}: ${res.status}`);
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
      // 403: Hostinger's firewall stops `../` before PHP sees it.
      assert.ok([400, 403, 404, 429].includes(res.status), `${token}: ${res.status}`);
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

describe('Métricas internas (ADR-029)', { skip: skipWithoutTarget }, () => {
  it('S-40 ✱ los datos sin sesión → 401 y nada más', async (t) => {
    if (!needsApi(t)) return;
    for (const query of ['', '?rango=todo', '?rango=../../config']) {
      const res = await request(`/api/metricas.php${query}`);
      assert.equal(res.status, 401, query);
      assert.equal(res.body, '{"error":"login"}');
      assert.match(res.headers.get('cache-control') ?? '', /no-store/);
    }
  });

  it('S-41 ✱ una cookie de sesión inventada no abre nada', async (t) => {
    if (!needsApi(t)) return;
    for (const cookie of [`fenia_sesion=${randomToken()}`, 'fenia_sesion=1', `fenia_sesion=${'A'.repeat(43)}; fm_owner=1`]) {
      const res = await request('/api/metricas.php', { headers: { Cookie: cookie } });
      assert.equal(res.status, 401, cookie);
      assert.equal((await request('/api/sesion.php', { headers: { Cookie: cookie } })).status, 401);
    }
  });

  it('S-42 ✱ ingresar desde otro sitio → 403, sin sesión', async (t) => {
    if (!needsApi(t)) return;
    const res = await json('/api/sesion.php', { usuario: 'dev', clave: 'x' }, { Origin: 'https://evil.example' });
    assert.equal(res.status, 403);
    assert.equal(res.headers.get('set-cookie'), null);
  });

  it('S-43 ✱ clave equivocada y usuario inexistente → la misma respuesta', async (t) => {
    if (!needsApi(t)) return;
    const wrong = await json('/api/sesion.php', { usuario: 'dev', clave: `no-es-${randomRef()}` });
    const unknown = await json('/api/sesion.php', { usuario: `nadie${randomRef().slice(0, 6)}`, clave: 'x' });
    for (const res of [wrong, unknown]) {
      // 503 if this server has no owners yet; 429 once the IP used up its attempts.
      assert.ok([401, 429, 503].includes(res.status), `estado ${res.status}`);
      assert.equal(res.headers.get('set-cookie'), null);
    }
    assert.equal(wrong.body, unknown.body);
  });

  it('S-44 ✱ el contador de visitas responde 204 a todo y no pone cookies', async (t) => {
    if (!needsApi(t)) return;
    for (const body of ['{"p":"/","w":1200}', 'no es json', '{"p":"/api/kit.php"}', '{"k":"buy","p":"/"}', 'x'.repeat(5000)]) {
      const res = await request('/api/hit.php', { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body });
      assert.equal(res.status, 204);
      assert.equal(res.body, '');
      assert.equal(res.headers.get('set-cookie'), null);
    }
    assert.equal((await request('/api/hit.php')).status, 405);
  });

  it('S-45 ✱ la página de métricas no se indexa ni figura en el sitemap', async () => {
    const page = await request('/metricas/');
    assert.equal(page.status, 200);
    assert.match(page.body, /<meta name="robots" content="noindex, nofollow"/);
    assert.doesNotMatch(page.body, /fenia_sesion|\$2y\$/);
    const sitemap = await request('/sitemap-0.xml');
    assert.doesNotMatch(sitemap.body, /metricas/);
  });
});

describe('Tu kit (D25)', { skip: skipWithoutTarget }, () => {
  const KIT = 'kit-ensenar-con-ia';
  const forgedPass = `1.${Math.floor(Date.now() / 1000) + 86400}.${randomBytes(32).toString('base64url')}`;

  it('S-30 ✱ fichas e imprimibles sin pase de lectura → 403, con la CSP de las páginas del kit', async (t) => {
    if (!needsApi(t)) return;
    for (const r of ['fichas', 'imprimibles']) {
      const res = await request(`/api/kit.php?k=${KIT}&r=${r}`);
      assert.equal(res.status, 403, r);
      const csp = res.headers.get('content-security-policy') ?? '';
      assert.match(csp, /default-src 'none'/, 'la página del kit no puede pedir nada afuera');
      assert.match(csp, /connect-src blob: data:(;|$)/);
      assert.match(csp, /form-action 'none'/);
      assert.match(res.headers.get('cache-control') ?? '', /no-store/);
      assert.equal(findLeak(res.body), null);
    }
  });

  it('S-31 ✱ la guía sin pase → 403, y sin CSP (el visor de PDF del navegador tiene que funcionar)', async (t) => {
    if (!needsApi(t)) return;
    const res = await request(`/api/guia.php?k=${KIT}`);
    assert.equal(res.status, 403);
    assert.equal(res.headers.get('content-security-policy'), null);
    assert.doesNotMatch(res.headers.get('content-type') ?? '', /pdf/);
  });

  it('S-32 ✱ pase falsificado, de otro kit o de otra página → nunca el archivo', async (t) => {
    if (!needsApi(t)) return;
    const cases = [
      [`/api/kit.php?k=${KIT}&r=fichas`, `fk_${KIT}=${forgedPass}`],
      [`/api/guia.php?k=${KIT}`, `fk_${KIT}=${forgedPass}`],
      [`/api/kit.php?k=kit-aprender-con-ia&r=fichas`, `fk_${KIT}=${forgedPass}`],
      [`/api/kit.php?k=${KIT}&r=../config.php`, `fk_${KIT}=${forgedPass}`],
      [`/api/kit.php?k=../_lib&r=fichas`, `fk_${KIT}=${forgedPass}`],
    ];
    for (const [path, cookie] of cases) {
      const res = await request(path, { headers: { Cookie: cookie } });
      // 403 without a valid pass, 404 for what does not exist, 429 once the IP
      // used up its failed attempts (shared with S-07), 403 too from the firewall.
      assert.ok([403, 404, 429].includes(res.status), `${path}: ${res.status}`);
      assert.doesNotMatch(res.headers.get('content-type') ?? '', /pdf|zip/);
      assert.doesNotMatch(res.body, /<textarea|__bundler/);
      assert.equal(findLeak(res.body), null);
    }
  });

  it('S-33 ✱ abrir el kit con tokens al azar → rechazado igual que la descarga, sin pase', async (t) => {
    if (!needsApi(t)) return;
    for (let i = 0; i < 3; i++) {
      const res = await json('/api/kit.php', { token: randomToken() });
      assert.ok([404, 429].includes(res.status), `estado ${res.status}`);
      assert.equal(res.headers.get('set-cookie'), null, 'sin pase para un token inválido');
      assert.ok(res.body === '{"usable":false}' || res.body.includes('rate_limited'), res.body);
    }
  });

  it('S-34 ✱ abrir el kit desde otro sitio → 403 (CSRF, D4)', async (t) => {
    if (!needsApi(t)) return;
    const res = await json('/api/kit.php', { token: randomToken() }, { Origin: 'https://evil.example' });
    assert.equal(res.status, 403);
    assert.equal(res.headers.get('set-cookie'), null);
  });

  it('S-35 ✱ métodos que no corresponden → 405', async (t) => {
    if (!needsApi(t)) return;
    assert.equal((await request('/api/kit.php', { method: 'PUT' })).status, 405);
    assert.equal((await request('/api/guia.php', { method: 'POST' })).status, 405);
  });

  it('S-36 el pase de lectura abre el kit y no gasta descargas', { skip: stagingOnly }, async (t) => {
    const token = process.env.DOWNLOAD_TOKEN ?? '';
    if (!token) return t.skip('definí DOWNLOAD_TOKEN (de una compra de prueba)');
    const open = await json('/api/kit.php', { token });
    assert.equal(open.status, 200);
    const cookie = (open.headers.get('set-cookie') ?? '').split(';')[0];
    assert.match(open.headers.get('set-cookie') ?? '', /HttpOnly/i);
    assert.match(open.headers.get('set-cookie') ?? '', /SameSite=Strict/i);
    assert.match(open.headers.get('set-cookie') ?? '', /Path=\/api\//);
    const { kit, remaining } = JSON.parse(open.body);
    const fichas = await request(`/api/kit.php?k=${kit}&r=fichas`, { headers: { Cookie: cookie } });
    assert.equal(fichas.status, 200);
    assert.match(fichas.headers.get('content-type') ?? '', /text\/html/);
    const again = JSON.parse((await json('/api/kit.php', { token, refresh: true })).body);
    assert.equal(again.remaining, remaining);
  });
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
    // 404 while the kit is not on sale (checked first), 400 once it is.
    if (salesOn) assert.ok([400, 404].includes(res.status), `status ${res.status}`);
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
