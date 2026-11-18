// "Conversar con IA sin creerle todo" (ADR-030): the self-contained apps and
// the training's sales page, black box against a deployed site. All ✱: only
// reads, nothing is bought or sent. IDs refer to docs/ventas/test-plan.md.
//
// The expected policy of each app is read from the repo's .htaccess, so this
// also proves that the server applies it and that nothing between the server
// and the browser (Hostinger's CDN included) changes a byte of the files.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { request, findLeak, skipWithoutTarget, BASE_URL } from './config.mjs';

const APP_DIR = new URL('../../public/app/conversar-con-ia/', import.meta.url);
const PAGE = '/entrenamientos/conversar-con-ia/';
const htaccess = readFileSync(new URL('.htaccess', APP_DIR), 'utf8');
const POLICIES = new Map(
  [...htaccess.matchAll(/<Files "([^"]+)">\s*Header always set Content-Security-Policy "([^"]+)"/g)].map((m) => [m[1], m[2]]),
);
const sha = (text) => `'sha256-${createHash('sha256').update(text, 'utf8').digest('base64')}'`;

/** The raw bytes, as the browser gets them (fetch undoes gzip/brotli, nothing else). */
async function bytes(path) {
  const res = await fetch(`${BASE_URL}${path}`, {
    signal: AbortSignal.timeout(20_000),
    headers: { 'User-Agent': 'fenia-security-suite/1.0 (bot)' },
  });
  return { status: res.status, headers: res.headers, body: Buffer.from(await res.arrayBuffer()) };
}

describe('Conversar con IA: apps y página de venta (ADR-030)', { skip: skipWithoutTarget }, () => {
  it('el .htaccess de la carpeta tiene la política de muestra y piloto', () => {
    assert.deepEqual([...POLICIES.keys()].sort(), ['muestra.html', 'piloto.html']);
  });

  for (const file of POLICIES.keys()) {
    const path = `/app/conversar-con-ia/${file}`;

    it(`S-50 ✱ ${file} llega con su política exacta, y sus huellas son las del archivo servido`, async () => {
      const { status, headers, body } = await bytes(path);
      assert.equal(status, 200);
      const csp = headers.get('content-security-policy') ?? '';
      assert.equal(csp, POLICIES.get(file), 'la CSP servida no es la del .htaccess');
      const html = body.toString('utf8');
      const script = /<script\b[^>]*>([\s\S]*?)<\/script>/.exec(html)?.[1];
      const style = /<style\b[^>]*>([\s\S]*?)<\/style>/.exec(html)?.[1];
      assert.ok(script !== undefined && style !== undefined, 'falta el script o el estilo');
      assert.ok(csp.includes(`script-src ${sha(script)};`), 'el hash del script servido no está en la CSP');
      assert.ok(csp.includes(`style-src ${sha(style)};`), 'el hash del estilo servido no está en la CSP');
    });

    it(`S-51 ✱ ${file} llega idéntico al del repo, byte por byte`, async () => {
      const { body } = await bytes(path);
      const local = readFileSync(new URL(file, APP_DIR));
      assert.equal(createHash('sha256').update(body).digest('hex'), createHash('sha256').update(local).digest('hex'));
    });

    it(`S-52 ✱ ${file}: sin caché vieja, sin indexar, HTML en UTF-8`, async () => {
      const { headers } = await bytes(path);
      assert.equal(headers.get('cache-control'), 'no-cache');
      assert.match(headers.get('x-robots-tag') ?? '', /noindex/);
      assert.match(headers.get('content-type') ?? '', /^text\/html;\s*charset=utf-8$/i);
      assert.equal(headers.get('x-content-type-options'), 'nosniff');
    });
  }

  it('S-53 ✱ el programa completo no está publicado ni enlazado', async () => {
    for (const path of ['/app/conversar-con-ia/programa.html', '/app/conversar-con-ia/']) {
      const res = await request(path);
      assert.ok([403, 404].includes(res.status), `${path}: ${res.status}`);
      assert.doesNotMatch(res.body, /fenia_programa_conversar-con-ia_v1/);
      assert.equal(findLeak(res.body), null);
    }
    const page = await request(PAGE);
    assert.doesNotMatch(page.body, /programa\.html|piloto\.html/);
  });

  it('S-55 ✱ la página de venta no tiene compra, abre la muestra con carga completa y se indexa', async () => {
    const { status, body } = await request(PAGE);
    assert.equal(status, 200);
    assert.doesNotMatch(body, /buy-form|\/api\/checkout|Pagar con Mercado Pago/);
    const samples = [...body.matchAll(/<a\b[^>]*href="\/app\/conversar-con-ia\/muestra\.html"[^>]*>/g)].map((m) => m[0]);
    assert.ok(samples.length > 0, 'no hay botón a la muestra');
    for (const link of samples) {
      assert.match(link, /data-astro-reload/, 'sin carga completa, el router del sitio intentaría meter la app en la página');
      assert.match(link, /data-sample/, 'sin data-sample, el clic no se cuenta (ADR-029)');
    }
    assert.doesNotMatch(body, /<meta name="robots" content="noindex/);
    assert.doesNotMatch(body, /\[(A DEFINIR|A CONFIRMAR|COMPLETAR)/, 'se publicó un pendiente entre corchetes');
    const sitemap = await request('/sitemap-0.xml');
    assert.match(sitemap.body, /\/entrenamientos\/conversar-con-ia\//);
  });
});
