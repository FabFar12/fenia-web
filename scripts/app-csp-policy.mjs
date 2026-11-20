// The Content-Security-Policy of the self-contained apps (ADR-030), shared by
// scripts/update-app-csp.mjs (writes it) and scripts/verify-app-csp.mjs (checks
// it). Each app runs only its own script and style, by hash; nothing from the
// network. Images may also come from the site itself ('self') for the icons
// the app names in its <head>, and the web app manifest comes from the site.
import { createHash } from 'node:crypto';

export const sha = (text) => `'sha256-${createHash('sha256').update(text, 'utf8').digest('base64')}'`;

export const policy = (script, style) =>
  `default-src 'none'; script-src ${script}; style-src ${style}; font-src data:; img-src data: blob: 'self'; manifest-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'`;

/** The one inline <script> and <style> of an app file, or null if it has more or fewer. */
export function parts(text) {
  const scripts = [...text.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
  const styles = [...text.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]);
  return scripts.length === 1 && styles.length === 1 ? { script: scripts[0], style: styles[0], scripts, styles } : { scripts, styles };
}
