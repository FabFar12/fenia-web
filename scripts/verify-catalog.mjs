// Test U-31 (docs/ventas/test-plan.md): the built sales catalogue must match
// the product .md files exactly — same visible products, status, price and
// currency — so the price on the card is the price charged (threat model C12).
// Runs in CI after `npm run build`. No dependencies.
//
//   node scripts/verify-catalog.mjs [path/to/catalog.json]
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const catalogPath = process.argv[2] ?? 'dist/api/catalog.json';
const productsDir = 'src/content/products';
const errors = [];

/** Minimal frontmatter reader for the flat scalar fields we compare. */
function frontmatter(file) {
  const text = readFileSync(join(productsDir, file), 'utf8');
  const block = /^---\r?\n([\s\S]*?)\r?\n---/.exec(text);
  if (!block) throw new Error(`${file}: no frontmatter`);
  const field = (name) => {
    const m = new RegExp(`^${name}:\\s*(.*)$`, 'm').exec(block[1]);
    return m ? m[1].trim().replace(/^["']|["']$/g, '') : undefined;
  };
  const price = field('price');
  return {
    id: file.replace(/\.md$/, ''),
    title: field('title'),
    status: field('status'),
    price: price === undefined || price === 'null' || price === '' ? null : Number(price),
    currency: field('currency') ?? 'ARS',
  };
}

const expected = readdirSync(productsDir)
  .filter((f) => f.endsWith('.md'))
  .map(frontmatter)
  .filter((p) => p.status !== 'hidden');

let catalog;
try {
  catalog = JSON.parse(readFileSync(catalogPath, 'utf8'));
} catch (err) {
  console.error(`U-31 FAIL: cannot read ${catalogPath}: ${err.message}`);
  process.exit(1);
}

const actual = new Map((catalog.products ?? []).map((p) => [p.id, p]));
for (const want of expected) {
  const got = actual.get(want.id);
  if (!got) {
    errors.push(`${want.id}: missing from catalogue`);
    continue;
  }
  for (const key of ['title', 'status', 'price', 'currency']) {
    if (got[key] !== want[key]) errors.push(`${want.id}.${key}: catalogue=${JSON.stringify(got[key])} md=${JSON.stringify(want[key])}`);
  }
  if (got.status === 'live' && !(Number.isInteger(got.price) && got.price > 0)) {
    errors.push(`${want.id}: live product needs a positive integer price`);
  }
  actual.delete(want.id);
}
for (const extra of actual.keys()) errors.push(`${extra}: in catalogue but hidden or not a product`);

if (errors.length) {
  console.error(`U-31 FAIL (${errors.length}):\n  - ${errors.join('\n  - ')}`);
  process.exit(1);
}
console.log(`U-31 OK: ${expected.length} visible products match ${catalogPath}`);
