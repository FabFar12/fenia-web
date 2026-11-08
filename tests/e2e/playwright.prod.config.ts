// Same battery against production, minus anything tagged @staging (reference
// screenshots and tests that rely on the staging preview build).
import { defineConfig } from '@playwright/test';
import base from './playwright.config';

export default defineConfig({
  ...base,
  grepInvert: /@staging/,
  use: { ...base.use, baseURL: process.env.E2E_BASE_URL ?? 'https://fenia.com.ar' },
});
