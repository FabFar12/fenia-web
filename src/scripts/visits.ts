// Private visit counter (ADR-029). One small beacon per page view, and one per
// click on a WhatsApp link, to our own API: nothing goes to third parties.
// No cookies are set and nothing personal is sent: the page's path, where the
// visitor came from (referrer and utm_* of the first page), and the window
// width (phone or computer). Skipped for automated browsers (our tests) and
// for the owners while logged in to the metrics (cookie fm_owner, no secret).

const OWNER = /(?:^|;\s*)fm_owner=1(?:;|$)/;
let first = true;

function send(data: Record<string, string | number>): void {
  if (navigator.webdriver || OWNER.test(document.cookie) || typeof navigator.sendBeacon !== 'function') return;
  try {
    navigator.sendBeacon('/api/hit.php', new Blob([JSON.stringify(data)], { type: 'text/plain' }));
  } catch {
    // Counting must never break the page.
  }
}

// Fires on the first load and after every client-side navigation (ClientRouter).
document.addEventListener('astro:page-load', () => {
  const url = new URL(window.location.href);
  const utm = (key: string) => (url.searchParams.get(key) ?? '').slice(0, 100);
  send(
    first
      ? { p: url.pathname, r: document.referrer.slice(0, 300), s: utm('utm_source'), m: utm('utm_medium'), c: utm('utm_campaign'), w: window.innerWidth }
      : { p: url.pathname, n: 1, w: window.innerWidth },
  );
  first = false;
});

document.addEventListener('click', (event) => {
  const link = (event.target as Element | null)?.closest?.('a[href*="wa.me/"], a[href*="whatsapp.com/"]');
  if (link) send({ k: 'whatsapp', p: window.location.pathname, n: 1, w: window.innerWidth });
});
