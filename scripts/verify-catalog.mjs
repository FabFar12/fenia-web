// Test U-31 (docs/ventas/test-plan.md): the built sales catalogue must match
// the product .md files exactly — same visible products, status, price,
// currency and launch promo (D23) — so the price on the card is the price
// charged (threat model C12).
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
  // Nested `promo:` block (indented `price`, `starts`, `ends`).
  const promoBlock = /^promo:[ \t]*\r?\n((?:[ \t]+\S.*(?:\r?\n|$))+)/m.exec(block[1]);
  const sub = (name) => {
    const m = promoBlock && new RegExp(`^[ \\t]+${name}:\\s*(.*)$`, 'm').exec(promoBlock[1]);
    return m ? m[1].trim().replace(/^["']|["']$/g, '') : undefined;
  };
  return {
    id: file.replace(/\.md$/, ''),
    title: field('title'),
    status: field('status'),
    price: price === undefined || price === 'null' || price === '' ? null : Number(price),
    currency: field('currency') ?? 'ARS',
    promo: promoBlock
      ? { price: Number(sub('price')), starts: new Date(sub('starts')).toISOString(), ends: new Date(sub('ends')).toISOString() }
      : null,
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
  const gotPromo = got.promo ? { price: got.promo.price, starts: got.promo.starts, ends: got.promo.ends } : null;
  if (JSON.stringify(gotPromo) !== JSON.stringify(want.promo)) {
    errors.push(`${want.id}.promo: catalogue=${JSON.stringify(gotPromo)} md=${JSON.stringify(want.promo)}`);
  }
  if (want.promo && !(want.promo.price > 0 && want.promo.price < want.price && want.promo.ends > want.promo.starts)) {
    errors.push(`${want.id}.promo: must be cheaper than the price and end after it starts`);
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
