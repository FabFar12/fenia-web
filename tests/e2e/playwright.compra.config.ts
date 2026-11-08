// A full purchase in the Mercado Pago sandbox, on staging only. It creates a
// real sandbox order, pays it with MP's test card and refunds it at the end.
// Needs the test access token in ~/fenia-secrets/mp-test-token.txt (or
// FENIA_SECRETS_DIR); it is read, never printed.
import { defineConfig, devices } from '@playwright/test';
import base, { STAGING } from './playwright.config';

export default defineConfig({
  ...base,
  testIgnore: [],
  testDir: './compra',
  timeout: 240_000,
  retries: 0,
  fullyParallel: false,
  use: { ...base.use, baseURL: STAGING },
  projects: [{ name: 'compra', use: { ...devices['Desktop Edge'], channel: process.env.E2E_CHANNEL ?? 'msedge', viewport: { width: 1280, height: 900 } } }],
});
