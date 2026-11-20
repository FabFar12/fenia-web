// Self-contained apps (ADR-030): every HTML file under public/app/<name>/
// must run under the exact policy of its folder's .htaccess, which allows its
// one script and its one style by their SHA-256 hash. A changed byte (an
// editor, Git line endings, a hand fix) makes the browser block the app, so
// CI fails before deploying it. Three sources must agree for every published
// file: the hashes recomputed from its bytes, its policy in the .htaccess, and
// the csp-hashes.json the product team ships with each version (copied as is
// to scripts/app-csp-hashes/<name>.json). Also checks that the build copies
// the files untouched and that the paid program (programa.html) is never
// published. No dependencies.
//
//   node scripts/verify-app-csp.mjs [distDir]   (distDir checked only if it exists)
import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const APPS = 'public/app';
const HASHES = 'scripts/app-csp-hashes';
const dist = process.argv[2] ?? 'dist';
const errors = [];
let checked = 0;

const sha = (text) => `'sha256-${createHash('sha256').update(text, 'utf8').digest('base64')}'`;
/** The policy the apps were tested with (handoff requirements, point 4). */
const expectedPolicy = (script, style) =>
  `default-src 'none'; script-src ${script}; style-src ${style}; font-src data:; img-src data: blob:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'`;

for (const app of existsSync(APPS) ? readdirSync(APPS) : []) {
  const dir = join(APPS, app);
  const htaccessPath = join(dir, '.htaccess');
  if (!existsSync(htaccessPath)) {
    errors.push(`${dir}: no .htaccess, so its pages would run under the site's policy`);
    continue;
  }
  const htaccess = readFileSync(htaccessPath, 'utf8');
  const directives = htaccess.split(/\r?\n/).filter((line) => !/^\s*#/.test(line)).join('\n');
  if (/RewriteEngine|RewriteRule/i.test(directives)) {
    errors.push(`${htaccessPath}: mod_rewrite here switches off the parent's HTTPS, www and staging rules`);
  }
  if (!/X-Robots-Tag "noindex/.test(htaccess)) errors.push(`${htaccessPath}: missing X-Robots-Tag noindex`);

  const policies = new Map();
  for (const m of htaccess.matchAll(/<Files "([^"]+)">\s*Header always set Content-Security-Policy "([^"]+)"\s*<\/Files>/g)) {
    policies.set(m[1], m[2]);
  }

  // The hashes the product team says each file has. It also lists files that
  // are not published (programa.html): only the published ones are compared.
  const hashesPath = join(HASHES, `${app}.json`);
  let declared = null;
  try {
    declared = JSON.parse(readFileSync(hashesPath, 'utf8'));
  } catch (err) {
    errors.push(`${hashesPath}: missing or unreadable (${err.code ?? err.message}); copy the csp-hashes.json of the product team's version here`);
  }

  for (const file of readdirSync(dir)) {
    if (file === '.htaccess') continue;
    const path = join(dir, file);
    if (!policies.has(file)) {
      errors.push(`${path}: no <Files "${file}"> policy in ${htaccessPath} (only listed files may live here)`);
      continue;
    }
    const raw = readFileSync(path);
    const text = raw.toString('utf8');
    if (raw[0] === 0xef && raw[1] === 0xbb && raw[2] === 0xbf) errors.push(`${path}: starts with a BOM`);
    if (raw.includes(13)) errors.push(`${path}: has CR characters (line endings were converted)`);

    const scripts = [...text.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
    const styles = [...text.matchAll(/<style\b[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]);
    if (scripts.length !== 1 || styles.length !== 1) {
      errors.push(`${path}: expected one <script> and one <style>, found ${scripts.length} and ${styles.length}`);
      continue;
    }
    if (/<script\b[^>]*\bsrc\s*=/i.test(text) || /<link\b[^>]*rel=["']?stylesheet/i.test(text)) {
      errors.push(`${path}: loads a script or stylesheet from a file; the apps must be self-contained`);
    }
    const want = expectedPolicy(sha(scripts[0]), sha(styles[0]));
    if (policies.get(file) !== want) {
      errors.push(`${path}: policy in .htaccess does not match the file.\n    .htaccess: ${policies.get(file)}\n    expected:  ${want}`);
    }
    if (declared) {
      const entry = declared[file];
      if (!entry) errors.push(`${hashesPath}: no entry for ${file}`);
      else if (entry.script !== sha(scripts[0]) || entry.style !== sha(styles[0])) {
        errors.push(`${path}: the file is not the version in ${hashesPath} (script ${entry.script === sha(scripts[0]) ? 'ok' : 'differs'}, style ${entry.style === sha(styles[0]) ? 'ok' : 'differs'})`);
      }
    }

    const built = join(dist, 'app', app, file);
    if (existsSync(dist)) {
      if (!existsSync(built)) errors.push(`${built}: missing from the build`);
      else if (!readFileSync(built).equals(raw)) errors.push(`${built}: the build changed the file`);
    }
    checked++;
  }
  for (const file of policies.keys()) {
    if (!existsSync(join(dir, file))) errors.push(`${htaccessPath}: policy for ${file}, which is not in the folder`);
  }
}

// The paid program is served only to buyers, from outside the repo (ADR-030).
const published = (root) => (existsSync(root) ? readdirSync(root, { recursive: true }).map(String) : []);
for (const [root, list] of [['public', published('public')], [dist, published(dist)]]) {
  for (const rel of list) {
    if (/(^|[\\/])programa\.html$/.test(rel)) errors.push(`${join(root, rel)}: the paid program must never be published (ADR-030)`);
  }
}

if (errors.length) {
  console.error(`verify-app-csp FAIL (${errors.length}):\n- ${errors.join('\n- ')}`);
  process.exit(1);
}
console.log(`verify-app-csp OK: ${checked} app file(s) match their policy and the product team's hashes${existsSync(dist) ? ', and were copied untouched to the build' : ''}.`);
