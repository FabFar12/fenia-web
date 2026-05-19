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
      // The 3D neural network island bundles three.js + postprocessing
      // (~240 KB gzipped). Raise the warning threshold so we don't get
      // noisy build output. See docs/adr/ADR-008 and ADR-011.
      chunkSizeWarningLimit: 1024,
      rollupOptions: {
        output: {
          // Split three.js + R3F + postprocessing into a stable vendor chunk
          // so changes to our own NeuralNetwork3D code don't invalidate the
          // ~240 KB three.js download on the next user visit. Better cache
          // hit rate across deploys.
          manualChunks(id) {
            if (
              id.includes('node_modules/three/') ||
              id.includes('node_modules/@react-three/') ||
              id.includes('node_modules/postprocessing/')
            ) {
              return 'vendor-three';
            }
            return undefined;
          },
        },
      },
    },
  },
});