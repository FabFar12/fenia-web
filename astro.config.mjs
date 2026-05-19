import { defineConfig } from 'astro/config';
import react from '@astrojs/react';
import sitemap from '@astrojs/sitemap';
import tailwindcss from '@tailwindcss/vite';

// Canonical production URL — used by sitemap, RSS, canonical tags.
// Override only when copying this site to another domain.
export default defineConfig({
  site: 'https://fenia.com.ar',
  integrations: [react(), sitemap()],
  vite: {
    plugins: [tailwindcss()],
  },
});