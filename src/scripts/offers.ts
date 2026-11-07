// Live price and countdown for kits with a time-limited promo (docs/ventas D23).
//
// Any element with `data-offer='{"regular","promo","starts","ends","currency"}'`
// (built by offerFor() in src/lib/sales.ts) gets its children updated every
// second:
//   [data-offer-price]      price to pay now
//   [data-offer-promo]      everything that only exists while the promo runs
//                           (label, badge, crossed-out price, deadline)
//   [data-offer-countdown]  "Termina en 6 días 23:59:59"
// When the promo ends, the promo parts disappear and the regular price shows,
// at the same instant the backend starts charging it. The countdown is the
// same for everyone and never restarts. Times come from the server's clock
// (Date header of a no-store request), so a wrong clock on the visitor's
// device does not move the deadline.

interface OfferData {
  regular: number;
  promo: number;
  starts: number;
  ends: number;
  currency: 'ARS';
}

const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 });
const two = (n: number) => String(n).padStart(2, '0');

function countdown(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000));
  const days = Math.floor(total / 86400);
  const clock = `${two(Math.floor((total % 86400) / 3600))}:${two(Math.floor((total % 3600) / 60))}:${two(total % 60)}`;
  return days > 0 ? `${days} ${days === 1 ? 'día' : 'días'} ${clock}` : clock;
}

const offers: { el: HTMLElement; data: OfferData }[] = [];
for (const el of document.querySelectorAll<HTMLElement>('[data-offer]')) {
  try {
    offers.push({ el, data: JSON.parse(el.dataset.offer ?? '') as OfferData });
  } catch {
    /* malformed: leave the build-time rendering */
  }
}

let offset = 0; // server clock minus this device's clock

function render(): void {
  const now = Date.now() + offset;
  for (const { el, data } of offers) {
    const running = now >= data.starts && now < data.ends;
    const price = el.querySelector<HTMLElement>('[data-offer-price]');
    if (price) price.textContent = money.format(running ? data.promo : data.regular);
    for (const part of el.querySelectorAll<HTMLElement>('[data-offer-promo]')) part.hidden = !running;
    const clock = el.querySelector<HTMLElement>('[data-offer-countdown]');
    if (clock) clock.textContent = running ? `Termina en ${countdown(data.ends - now)}` : '';
  }
}

async function syncClock(): Promise<void> {
  try {
    const sent = Date.now();
    const res = await fetch('/api/catalog.json', { method: 'HEAD', cache: 'no-store' });
    const server = Date.parse(res.headers.get('date') ?? '');
    if (!Number.isNaN(server)) offset = server - (sent + Date.now()) / 2;
  } catch {
    /* offline: keep this device's clock */
  }
}

if (offers.length) {
  render();
  syncClock().then(render);
  setInterval(render, 1000);
}
