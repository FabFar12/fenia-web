// Live price and countdown for kits with a time-limited promo (docs/ventas D23).
//
// Any element with `data-offer='{"regular","promo","starts","ends","firstDay","currency"}'`
// (built by offerFor() in src/lib/sales.ts) gets its children updated every
// second:
//   [data-offer-price]   price to pay now
//   [data-offer-promo]   everything that only exists while the promo runs
//   [data-offer-day]     "Día 1 de 10" (calendar days in Argentina)
//   [data-offer-seg=N]   progress bar segment N: data-state past | today | ''
//   [data-offer-label]   "Quedan" / "¡Último día! Quedan"
//   [data-offer-big]     "9 días" / on the last day "10 h 12 min 01 s"
//   [data-offer-small]   "y 10 h 12 min 01 s" / on the last day ""
// The offer's total length and the days already run are shown on purpose:
// the countdown is the same for everyone and never restarts. When the promo
// ends, the promo parts disappear and the regular price shows, at the same
// instant the backend starts charging it. Times come from the server's clock
// (Date header of a no-store request), so a wrong clock on the visitor's
// device does not move the deadline.

interface OfferData {
  regular: number;
  promo: number;
  starts: number;
  ends: number;
  firstDay: string;
  currency: 'ARS';
}

const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 });
const artDate = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Argentina/Buenos_Aires' });
const two = (n: number) => String(n).padStart(2, '0');
const daysBetween = (from: string, to: string) =>
  Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000);

const offers: { el: HTMLElement; data: OfferData }[] = [];
for (const el of document.querySelectorAll<HTMLElement>('[data-offer]')) {
  try {
    offers.push({ el, data: JSON.parse(el.dataset.offer ?? '') as OfferData });
  } catch {
    /* malformed: leave the build-time rendering */
  }
}

let offset = 0; // server clock minus this device's clock

function set(el: HTMLElement, selector: string, text: string): void {
  for (const node of el.querySelectorAll<HTMLElement>(selector)) node.textContent = text;
}

function render(): void {
  const now = Date.now() + offset;
  for (const { el, data } of offers) {
    const running = now >= data.starts && now < data.ends;
    set(el, '[data-offer-price]', money.format(running ? data.promo : data.regular));
    for (const part of el.querySelectorAll<HTMLElement>('[data-offer-promo]')) part.hidden = !running;
    if (!running) continue;

    const total = daysBetween(data.firstDay, artDate.format(data.ends)) + 1;
    const today = Math.min(total, daysBetween(data.firstDay, artDate.format(now)) + 1);
    set(el, '[data-offer-day]', `Día ${today} de ${total}`);
    for (const seg of el.querySelectorAll<HTMLElement>('[data-offer-seg]')) {
      const n = Number(seg.dataset.offerSeg);
      seg.dataset.state = n < today ? 'past' : n === today ? 'today' : '';
    }

    const secs = Math.max(0, Math.floor((data.ends - now) / 1000));
    const days = Math.floor(secs / 86400);
    const clock = `${Math.floor((secs % 86400) / 3600)} h ${two(Math.floor((secs % 3600) / 60))} min ${two(secs % 60)} s`;
    set(el, '[data-offer-label]', days > 0 ? 'Quedan' : '¡Último día! Quedan');
    set(el, '[data-offer-big]', days > 0 ? `${days} ${days === 1 ? 'día' : 'días'}` : clock);
    set(el, '[data-offer-small]', days > 0 ? `y ${clock}` : '');
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
