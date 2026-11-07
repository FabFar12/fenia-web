// Live price and countdown for kits with a time-limited promo (docs/ventas D23).
//
// Any element with `data-offer='{"regular","promo","starts","ends","currency"}'`
// (built by offerFor() in src/lib/sales.ts) gets its children updated every
// second:
//   [data-offer-price]        price to pay now
//   [data-offer-promo]        everything that only exists while the promo runs
//                             (label, badge, crossed-out price, deadline, banner)
//   [data-offer-urgency]      "Termina en" / "¡Último día! Termina en"
//   [data-offer-lead]         "Quedan 7 días" / "¡Último día!"
//   [data-offer-rest]         "10 h 40 min 39 s"
//   [data-offer-unit=X]       one number per box; X = days | hours | minutes |
//                             seconds | totalHours (hours counting the days)
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

function parts(ms: number) {
  const total = Math.max(0, Math.floor(ms / 1000));
  return {
    days: Math.floor(total / 86400),
    hours: Math.floor((total % 86400) / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
    totalHours: Math.floor(total / 3600),
  };
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
    for (const price of el.querySelectorAll<HTMLElement>('[data-offer-price]')) {
      price.textContent = money.format(running ? data.promo : data.regular);
    }
    for (const part of el.querySelectorAll<HTMLElement>('[data-offer-promo]')) part.hidden = !running;
    if (!running) continue;

    const left = parts(data.ends - now);
    for (const urgency of el.querySelectorAll<HTMLElement>('[data-offer-urgency]')) {
      urgency.textContent = left.days > 0 ? 'Termina en' : '¡Último día! Termina en';
    }
    for (const lead of el.querySelectorAll<HTMLElement>('[data-offer-lead]')) {
      lead.textContent = left.days > 0 ? `Quedan ${left.days} ${left.days === 1 ? 'día' : 'días'}` : '¡Último día!';
    }
    for (const rest of el.querySelectorAll<HTMLElement>('[data-offer-rest]')) {
      rest.textContent = `${left.hours} h ${two(left.minutes)} min ${two(left.seconds)} s`;
    }
    for (const box of el.querySelectorAll<HTMLElement>('[data-offer-unit]')) {
      const unit = box.dataset.offerUnit as keyof ReturnType<typeof parts>;
      const value = left[unit];
      if (typeof value === 'number') box.textContent = unit === 'days' || unit === 'totalHours' ? String(value) : two(value);
    }
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
