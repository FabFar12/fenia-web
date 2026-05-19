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
    build: {
      // The 3D neural network island bundles three.js (~220 KB gzipped).
      // Raise the warning threshold so we don't get noisy build output.
      // See docs/adr/ADR-008-animations-and-3d-neural-network.md
      chunkSizeWarningLimit: 1024,
    },
  },
});