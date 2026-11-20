// After changing an app under public/app/<name>/ (ADR-030), writes its hashes
// where the server and CI need them, in one go:
//  - the <Files> policy of each HTML file in public/app/<name>/.htaccess;
//  - scripts/app-csp-hashes/<name>.json (also listing files kept outside the
//    repo, such as the paid programa.html, when passed as extra arguments).
// Then run `node scripts/verify-app-csp.mjs` to check everything agrees.
//
//   node scripts/update-app-csp.mjs <name> [path/to/extra.html ...]
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { parts, policy, sha } from './app-csp-policy.mjs';

const [name, ...extra] = process.argv.slice(2);
if (!name || !/^[a-z0-9-]+$/.test(name)) {
  console.error('uso: node scripts/update-app-csp.mjs <nombre-de-la-app> [otro.html ...]');
  process.exit(1);
}
const dir = join('public/app', name);
const htaccessPath = join(dir, '.htaccess');
const jsonPath = join('scripts/app-csp-hashes', `${name}.json`);

const hashes = existsSync(jsonPath) ? JSON.parse(readFileSync(jsonPath, 'utf8')) : {};
let htaccess = readFileSync(htaccessPath, 'utf8');

const files = [...readdirSync(dir).filter((f) => f.endsWith('.html')).map((f) => join(dir, f)), ...extra];
for (const path of files) {
  const file = basename(path);
  const raw = readFileSync(path);
  if (raw.includes(13)) throw new Error(`${path}: has CR characters; save it with LF line endings`);
  const p = parts(raw.toString('utf8'));
  if (!p.script) throw new Error(`${path}: expected one <script> and one <style>, found ${p.scripts.length} and ${p.styles.length}`);
  hashes[file] = { script: sha(p.script), style: sha(p.style) };
  if (path.startsWith(dir)) {
    const block = new RegExp(`(<Files "${file.replace(/\./g, '\\.')}">\\s*Header always set Content-Security-Policy ")[^"]*(")`);
    if (!block.test(htaccess)) throw new Error(`${htaccessPath}: no <Files "${file}"> block; add one first`);
    htaccess = htaccess.replace(block, `$1${policy(hashes[file].script, hashes[file].style)}$2`);
  }
  console.log(`${file}: script ${hashes[file].script.slice(8, 20)}… style ${hashes[file].style.slice(8, 20)}…`);
}
writeFileSync(htaccessPath, htaccess);
writeFileSync(jsonPath, `${JSON.stringify(hashes, null, 2)}\n`);
console.log(`escrito: ${htaccessPath} y ${jsonPath}`);
