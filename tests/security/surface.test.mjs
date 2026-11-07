// Attack surface that exists today: files that must not be reachable and
// headers that must be present. All ✱ (safe against production).
// IDs refer to docs/ventas/test-plan.md and docs/ventas/threat-model.md.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { request, findLeak, skipWithoutTarget } from './config.mjs';

const KIT_IDS = ['guia-evaluar-tiempos-ia', 'kit-ensenar-con-ia', 'kit-aprender-con-ia'];

describe('S-09 ✱ los ZIP de los kits no tienen URL pública', { skip: skipWithoutTarget }, () => {
  const paths = [
    ...KIT_IDS.flatMap((id) => [`/${id}.zip`, `/kits/${id}.zip`, `/api/kits/${id}.zip`, `/downloads/${id}.zip`]),
    '/kits/', '/storage/', '/files/', '/downloads/', '/fenia-private/', '/fenia-private/production/kits/',
  ];
  for (const path of paths) {
    it(path, async () => {
      const res = await request(path);
      assert.ok([403, 404].includes(res.status), `status ${res.status}`);
      assert.doesNotMatch(res.body, /Index of \//i, 'listado de carpeta expuesto');
      assert.notEqual(res.headers.get('content-type'), 'application/zip');
    });
  }
});

describe('S-11 ✱ secretos, código y logs no son accesibles', { skip: skipWithoutTarget }, () => {
  const paths = [
    '/.ftp-deploy-sync-state.json', // hallazgo H-01
    '/.git/config', '/.git/HEAD', '/.env', '/.env.production',
    '/composer.json', '/composer.lock', '/package.json', '/phpunit.xml.dist',
    '/api/_lib/autoload.php', '/api/_lib/MpSignature.php', '/api/_lib/OrderRules.php', '/api/_lib/.htaccess',
    '/api/_lib/config.example.php', '/api/_lib/Config.php', '/api/_lib/Db.php',
    '/api/_inbox/', '/api/_inbox/config.php', '/api/_inbox/guia-evaluar-tiempos-ia.zip', '/api/_inbox/.htaccess',
    '/config.php', '/api/config.php',
    '/error_log', '/api/error_log', '/api/_lib/error_log',
  ];
  for (const path of paths) {
    it(path, async () => {
      const res = await request(path);
      assert.ok([403, 404].includes(res.status), `status ${res.status} (debería ser 403/404)`);
      assert.equal(findLeak(res.body), null, 'la respuesta filtra contenido sensible');
    });
  }
});

describe('S-22 ✱ los parámetros de la URL no se reflejan como HTML', { skip: skipWithoutTarget }, () => {
  const payload = '<script>alert(1)</script>';
  const cases = [
    `/gracias/?status=approved&payment_id=${encodeURIComponent(payload)}`,
    `/recursos-gratuitos/?producto=${encodeURIComponent(payload)}&titulo=${encodeURIComponent(payload)}`,
  ];
  for (const path of cases) {
    it(path.split('?')[0], async () => {
      const res = await request(path);
      // Hostinger's firewall answers 403 to `<script>` in the query string;
      // either outcome is fine as long as the payload is never echoed back.
      assert.ok([200, 403].includes(res.status), `status ${res.status}`);
      assert.ok(!res.body.includes(payload), 'el payload aparece tal cual en el HTML');
    });
  }
});

describe('S-25 ✱ staging no responde bajo el dominio real (C20)', { skip: skipWithoutTarget }, () => {
  // Hostinger puts the subdomain folder at public_html/staging, which is also
  // reachable as fenia.com.ar/staging/ unless staging's .htaccess blocks it.
  for (const path of ['/staging/', '/staging/index.html', '/staging/api/catalog.json']) {
    it(path, async () => {
      const res = await request(path);
      assert.ok([403, 404].includes(res.status), `status ${res.status}`);
    });
  }
});

describe('S-24 ✱ encabezados de seguridad', { skip: skipWithoutTarget }, () => {
  it('/ trae HSTS, CSP, nosniff, anti-framing y Referrer-Policy', async () => {
    const { headers } = await request('/');
    const hsts = headers.get('strict-transport-security') ?? '';
    const maxAge = Number(/max-age=(\d+)/.exec(hsts)?.[1] ?? 0);
    assert.ok(maxAge >= 31_536_000, `HSTS max-age ${maxAge}`);
    const csp = headers.get('content-security-policy') ?? '';
    assert.match(csp, /frame-ancestors 'none'/);
    assert.match(csp, /object-src 'none'/);
    assert.match(csp, /form-action 'self'/);
    assert.equal(headers.get('x-content-type-options'), 'nosniff');
    assert.equal(headers.get('x-frame-options'), 'DENY');
    assert.ok(headers.get('referrer-policy'), 'falta Referrer-Policy');
  });

  for (const path of ['/api/health.php', '/api/status.php?ref=0', '/api/catalog.json']) {
    it(`${path} responde con Cache-Control: no-store (C21)`, async () => {
      const { status, headers } = await request(path);
      if (status === 404) return; // la API todavía no se publicó en este entorno
      assert.match(headers.get('cache-control') ?? '', /no-store/);
    });
  }

  it('la página de descarga pide no mandar Referer y no indexarse', async () => {
    const { body } = await request('/descarga/');
    assert.match(body, /<meta name="referrer" content="no-referrer"/);
    assert.match(body, /<meta name="robots" content="noindex/);
  });
});
