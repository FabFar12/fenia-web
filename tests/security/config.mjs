// Shared settings for the black-box security suite (docs/ventas/test-plan.md,
// level S). Runs against a deployed site, never against `npm run dev`.
//
//   PowerShell:  $env:BASE_URL="https://fenia.com.ar"; npm run test:ventas
//   bash:        BASE_URL=https://staging.fenia.com.ar MODE=staging npm run test:ventas
//
// MODE=production (default) runs only the ✱ tests: read-only requests or
// requests the server must reject, never purchases or emails.
// MODE=staging also runs the tests that create orders (sandbox credentials).

export const BASE_URL = process.env.BASE_URL?.replace(/\/+$/, '') ?? '';
export const MODE = process.env.MODE === 'staging' ? 'staging' : 'production';

export const skipWithoutTarget = BASE_URL
  ? false
  : 'definí BASE_URL (ej. $env:BASE_URL="https://fenia.com.ar")';

export const stagingOnly = MODE === 'staging' ? false : 'solo con MODE=staging (crea órdenes de prueba)';

/** fetch against BASE_URL with a timeout; never throws on HTTP errors. */
export async function request(path, init = {}) {
  const response = await fetch(`${BASE_URL}${path}`, {
    redirect: 'follow',
    signal: AbortSignal.timeout(20_000),
    ...init,
    // "bot": the private visit counter (ADR-029) must never count the suite as a visitor.
    headers: { 'User-Agent': 'fenia-security-suite/1.0 (bot)', ...(init.headers ?? {}) },
  });
  const body = await response.text();
  return { status: response.status, headers: response.headers, body };
}

/** Content that must never appear in any response (secrets, source, traces). */
export const LEAK_PATTERNS = [
  /<\?php/i,
  /APP_USR-\d{6,}/,
  /TEST-\d{6,}-/,
  /DO NOT DELETE THIS FILE/,
  /\[core\]\s*repositoryformatversion/,
  /(Fatal error|Parse error|Stack trace|Uncaught \w+Exception)/,
  /\bon line \d+\b/,
  /\/home\/u\d+\//,
];

export function findLeak(body) {
  return LEAK_PATTERNS.find((pattern) => pattern.test(body))?.toString() ?? null;
}
