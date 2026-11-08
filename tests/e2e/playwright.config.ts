// End-to-end battery (docs/ventas/test-plan.md, level E2E). Runs a real
// browser against a deployed site — never against `npm run dev`.
//
//   npm run e2e          staging: site, launch promo, visuals, accessibility
//   npm run e2e:prod     production: only what buys nothing (see playwright.prod.config.ts)
//   npm run e2e:compra   staging: a full sandbox purchase + refund (compra/)
//   npm run e2e:update   refresh the reference screenshots after an approved visual change
//
// E2E_BASE_URL overrides the target. Browsers: Edge (installed on the dev's
// PC) and Playwright's Firefox (`npx playwright install firefox`, once).
import { defineConfig, devices } from '@playwright/test';

export const STAGING = 'https://staging.fenia.com.ar';
const channel = process.env.E2E_CHANNEL ?? 'msedge';

export default defineConfig({
  testDir: '.',
  testIgnore: ['compra/**'],
  timeout: 60_000,
  expect: { timeout: 10_000, toHaveScreenshot: { maxDiffPixelRatio: 0.01, animations: 'disabled' } },
  fullyParallel: true,
  retries: 1,
  reporter: [['list'], ['html', { outputFolder: 'report', open: 'never' }]],
  outputDir: 'results',
  snapshotPathTemplate: '{testDir}/fotos/{testFilePath}/{arg}-{projectName}{ext}',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? STAGING,
    contextOptions: { reducedMotion: 'reduce' },
    locale: 'es-AR',
    timezoneId: 'America/Argentina/Buenos_Aires',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'escritorio', use: { ...devices['Desktop Edge'], channel, viewport: { width: 1280, height: 900 } } },
    { name: 'celular', use: { ...devices['Pixel 7'], channel } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'], viewport: { width: 1280, height: 900 } } },
  ],
});
