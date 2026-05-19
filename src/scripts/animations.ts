/**
 * Pack 1 + Pack 3 — Vanilla TS micro-animation runtime.
 *
 * Initializes:
 *  - Scroll reveal (.reveal / .reveal-stagger via IntersectionObserver)
 *  - Stat counter ([data-counter] elements animate from 0 to target)
 *  - Scroll spy for `.nav-link[href^="#"]` items
 *  - Scroll progress bar (#scroll-progress)
 *  - CTA proximity glow (.cta-glow via mousemove)
 *
 * Honors `prefers-reduced-motion: reduce`: animations short-circuit to
 * the final state, no motion.
 *
 * Re-init on Astro View Transitions: listens to `astro:page-load`.
 * See ADR-008.
 */

const REDUCED = typeof window !== 'undefined'
  && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

function initScrollReveal() {
  const targets = document.querySelectorAll<HTMLElement>('.reveal, .reveal-stagger');
  if (targets.length === 0) return;

  if (REDUCED || !('IntersectionObserver' in window)) {
    targets.forEach((el) => el.classList.add('is-visible'));
    return;
  }

  const io = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
  );
  targets.forEach((el) => io.observe(el));
}

function initStatCounters() {
  const counters = document.querySelectorAll<HTMLElement>('[data-counter]');
  if (counters.length === 0) return;

  const animate = (el: HTMLElement) => {
    const target = el.dataset.counter ?? '';
    const m = target.match(/^(\d+)(.*)$/);
    if (!m) {
      el.textContent = target;
      return;
    }
    const num = parseInt(m[1], 10);
    const suffix = m[2];
    if (REDUCED) {
      el.textContent = target;
      return;
    }
    const duration = 1200;
    const start = performance.now();
    const step = (now: number) => {
      const t = Math.min((now - start) / duration, 1);
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = `${Math.round(num * eased)}${suffix}`;
      if (t < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  if (!('IntersectionObserver' in window)) {
    counters.forEach(animate);
    return;
  }

  const io = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          animate(entry.target as HTMLElement);
          io.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.5 },
  );
  counters.forEach((el) => io.observe(el));
}

// v2.6 — Section labels for the floating sticky label chip.
const SECTION_LABELS: Record<string, string> = {
  inicio: 'Inicio',
  metodologia: 'Metodología',
  servicios: 'Servicios',
  productos: 'Productos',
  contacto: 'Contacto',
};

function initScrollSpy() {
  const navLinks = Array.from(
    document.querySelectorAll<HTMLAnchorElement>('.nav-link[href^="#"]'),
  );
  const sections = navLinks
    .map((link) => {
      const id = link.getAttribute('href')?.slice(1);
      return id ? document.getElementById(id) : null;
    })
    .filter((s): s is HTMLElement => s !== null);

  if (sections.length === 0 || !('IntersectionObserver' in window)) return;

  const sectionLabel = document.getElementById('section-label');

  const setActive = (id: string) => {
    navLinks.forEach((l) => {
      l.classList.remove('nav-link-active');
      l.removeAttribute('aria-current');
    });
    const matching = document.querySelector(`.nav-link[href="#${id}"]`);
    if (matching) {
      matching.classList.add('nav-link-active');
      matching.setAttribute('aria-current', 'location');
    }

    // v2.6 — Update floating section label. Hide while on Hero (`inicio`).
    if (sectionLabel) {
      const label = SECTION_LABELS[id];
      const idx = sections.findIndex((s) => s.id === id);
      const numbered = idx >= 0 ? String(idx + 1).padStart(2, '0') : '';
      if (label && id !== 'inicio') {
        sectionLabel.textContent = numbered ? `${numbered} — ${label}` : label;
        sectionLabel.classList.add('is-visible');
      } else {
        sectionLabel.classList.remove('is-visible');
      }
    }
  };

  const spy = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((e) => e.isIntersecting)
        .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (visible) setActive(visible.target.id);
    },
    { rootMargin: '-25% 0px -60% 0px', threshold: [0, 0.25, 0.5] },
  );
  sections.forEach((s) => spy.observe(s));
}

// Uses transform: scaleX(fraction) instead of width: %% to avoid layout
// reflows during scroll. Resolves the scroll-lag bug reported 2026-05-19.
function initScrollProgress() {
  const bar = document.getElementById('scroll-progress');
  if (!bar) return;
  let ticking = false;
  const update = () => {
    const scrolled = window.scrollY;
    const max = document.documentElement.scrollHeight - window.innerHeight;
    const frac = max > 0 ? Math.min(scrolled / max, 1) : 0;
    bar.style.transform = `scaleX(${frac})`;
    ticking = false;
  };
  const onScroll = () => {
    if (!ticking) {
      requestAnimationFrame(update);
      ticking = true;
    }
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  update();
}

// Shared mousemove handler for both `.cta-glow` (proximity halo intensity)
// and `.cta-magnetic` (proximity translate). Single rAF-throttled pump
// avoids running heavy DOM reads twice per mousemove frame and keeps the
// main thread free during scroll — see ADR-012 perf section.
function initCtaInteractions() {
  const glowCtas = document.querySelectorAll<HTMLElement>('.cta-glow');
  const magneticCtas = document.querySelectorAll<HTMLElement>('.cta-magnetic');
  if (glowCtas.length === 0 && magneticCtas.length === 0) return;
  if (REDUCED) return;

  const GLOW_RADIUS = 260;
  const MAG_RADIUS = 110;
  const MAG_MAX = 7;

  let pendingX = 0;
  let pendingY = 0;
  let queued = false;

  const apply = () => {
    queued = false;
    // .cta-glow — intensity custom prop
    glowCtas.forEach((cta) => {
      const rect = cta.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top + rect.height / 2;
      const dx = pendingX - cx;
      const dy = pendingY - cy;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const intensity = Math.max(0, 1 - dist / GLOW_RADIUS);
      cta.style.setProperty('--glow', String(intensity));
    });
    // .cta-magnetic — translate custom props
    magneticCtas.forEach((cta) => {
      const rect = cta.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top + rect.height / 2;
      const dx = pendingX - cx;
      const dy = pendingY - cy;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < MAG_RADIUS) {
        const factor = (1 - dist / MAG_RADIUS) * (MAG_MAX / MAG_RADIUS);
        cta.style.setProperty('--mx', `${dx * factor}px`);
        cta.style.setProperty('--my', `${dy * factor}px`);
      } else {
        cta.style.setProperty('--mx', '0px');
        cta.style.setProperty('--my', '0px');
      }
    });
  };

  const onMove = (e: MouseEvent) => {
    pendingX = e.clientX;
    pendingY = e.clientY;
    if (!queued) {
      queued = true;
      requestAnimationFrame(apply);
    }
  };
  window.addEventListener('mousemove', onMove, { passive: true });
}

// ADR-012 — Auto-cycle highlight for a list of items.
// One item at a time gets `.is-active`; cycle pauses while pointer is over
// the container so users have time to read. Visible only when the container
// is in viewport (saves CPU + avoids running while user is off-section).
function initAutoCycle(
  containerSelector: string,
  itemSelector: string,
  intervalMs: number,
) {
  const container = document.querySelector<HTMLElement>(containerSelector);
  if (!container) return;
  const items = Array.from(container.querySelectorAll<HTMLElement>(itemSelector));
  if (items.length === 0) return;

  const setActive = (idx: number) => {
    items.forEach((el, i) => el.classList.toggle('is-active', i === idx));
  };

  if (REDUCED) {
    setActive(0);
    return;
  }

  let active = 0;
  let paused = false;
  let inView = false;
  let timerId: number | null = null;

  const tick = () => {
    if (paused || !inView) return;
    active = (active + 1) % items.length;
    setActive(active);
  };
  const start = () => {
    if (timerId !== null) return;
    timerId = window.setInterval(tick, intervalMs);
  };
  const stop = () => {
    if (timerId === null) return;
    window.clearInterval(timerId);
    timerId = null;
  };

  container.addEventListener('mouseenter', () => { paused = true; });
  container.addEventListener('mouseleave', () => { paused = false; });

  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver(
      ([entry]) => {
        inView = entry.isIntersecting;
        if (inView) start();
        else stop();
      },
      { threshold: 0.2 },
    );
    io.observe(container);
  } else {
    inView = true;
    start();
  }

  setActive(active);
}

// ADR-015 — Convergence link: the 4 differentiators and the 4 atom
// electrons share ONE active index. Auto-cycles; hovering either a
// differentiator OR an electron pins the active index to that pair.
// `.is-active` lands on both `.conv-diff` and the matching `.orbit`.
function initConvergenceLink(intervalMs: number) {
  const diffs = Array.from(document.querySelectorAll<HTMLElement>('.conv-diff'));
  const orbits = Array.from(document.querySelectorAll<SVGElement>('.orbit'));
  const electrons = Array.from(document.querySelectorAll<SVGElement>('.electron-core'));
  if (diffs.length === 0 || orbits.length === 0) return;

  const count = Math.min(diffs.length, orbits.length);

  const setActive = (idx: number) => {
    diffs.forEach((el, i) => el.classList.toggle('is-active', i === idx));
    orbits.forEach((el, i) => el.classList.toggle('is-active', i === idx));
  };

  if (REDUCED) {
    setActive(0);
    return;
  }

  let active = 0;
  let paused = false;
  let inView = false;
  let timerId: number | null = null;

  const tick = () => {
    if (paused || !inView) return;
    active = (active + 1) % count;
    setActive(active);
  };
  const start = () => {
    if (timerId !== null) return;
    timerId = window.setInterval(tick, intervalMs);
  };
  const stop = () => {
    if (timerId === null) return;
    window.clearInterval(timerId);
    timerId = null;
  };

  // Hover a differentiator → pin to its index
  diffs.forEach((el, i) => {
    el.addEventListener('mouseenter', () => {
      paused = true;
      active = i;
      setActive(i);
    });
    el.addEventListener('mouseleave', () => { paused = false; });
  });

  // Hover an electron → pin to its index (read from data-orbit-idx)
  electrons.forEach((el) => {
    const idxAttr = el.getAttribute('data-orbit-idx');
    const i = idxAttr ? parseInt(idxAttr, 10) : NaN;
    if (Number.isNaN(i)) return;
    el.addEventListener('mouseenter', () => {
      paused = true;
      active = i;
      setActive(i);
    });
    el.addEventListener('mouseleave', () => { paused = false; });
  });

  // Visibility-gate the cycle: observe whichever container exists.
  const obsTarget = document.querySelector('.conv-differentiators')
    ?? document.querySelector('.conv-diagram');
  if (obsTarget && 'IntersectionObserver' in window) {
    const io = new IntersectionObserver(
      ([entry]) => {
        inView = entry.isIntersecting;
        if (inView) start();
        else stop();
      },
      { threshold: 0.2 },
    );
    io.observe(obsTarget);
  } else {
    inView = true;
    start();
  }

  setActive(active);
}

// v2.7 — Tilt cards.
// Cards with `.tilt-card` rotate up to ±MAX_DEG degrees based on cursor
// position over the card. Returns to neutral on mouseleave.
function initTiltCards() {
  const cards = document.querySelectorAll<HTMLElement>('.tilt-card');
  if (cards.length === 0 || REDUCED) return;
  const MAX_DEG = 5;
  cards.forEach((card) => {
    const onMove = (e: MouseEvent) => {
      const rect = card.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width;
      const y = (e.clientY - rect.top) / rect.height;
      const rotY = (x - 0.5) * MAX_DEG * 2;
      const rotX = -(y - 0.5) * MAX_DEG * 2;
      card.style.setProperty('--tilt-x', `${rotX}deg`);
      card.style.setProperty('--tilt-y', `${rotY}deg`);
    };
    const onLeave = () => {
      card.style.setProperty('--tilt-x', '0deg');
      card.style.setProperty('--tilt-y', '0deg');
    };
    card.addEventListener('mousemove', onMove);
    card.addEventListener('mouseleave', onLeave);
  });
}

// Close the mobile nav menu when the user clicks a nav link (the menu is a
// pure-CSS checkbox toggle, so without this it stays open after navigation).
function initMobileNavClose() {
  const toggle = document.getElementById('nav-toggle');
  if (!(toggle instanceof HTMLInputElement)) return;
  const links = document.querySelectorAll<HTMLAnchorElement>(
    '.nav-link, .nav-cta',
  );
  links.forEach((link) => {
    link.addEventListener('click', () => {
      toggle.checked = false;
    });
  });
}

function initAll() {
  initScrollReveal();
  initStatCounters();
  initScrollSpy();
  initScrollProgress();
  // Single shared mousemove pump for glow + magnetic (replaces the two
  // separate handlers that fought over the main thread during scroll).
  initCtaInteractions();
  initTiltCards();
  initMobileNavClose();
  // ADR-012 — Auto-cycle highlight (methodology pillars)
  initAutoCycle('.meth-pillars', '.meth-pillar', 4200);
  // ADR-015 — Differentiators ⇄ atom electrons share one active index
  initConvergenceLink(3200);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAll);
} else {
  initAll();
}

// Re-init when navigating between pages via Astro View Transitions
document.addEventListener('astro:page-load', initAll);
