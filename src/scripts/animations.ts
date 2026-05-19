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

function initScrollProgress() {
  const bar = document.getElementById('scroll-progress');
  if (!bar) return;
  let ticking = false;
  const update = () => {
    const scrolled = window.scrollY;
    const max = document.documentElement.scrollHeight - window.innerHeight;
    const pct = max > 0 ? Math.min((scrolled / max) * 100, 100) : 0;
    bar.style.width = `${pct}%`;
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

function initCtaGlow() {
  const ctas = document.querySelectorAll<HTMLElement>('.cta-glow');
  if (ctas.length === 0 || REDUCED) return;
  const radius = 260;
  const onMove = (e: MouseEvent) => {
    ctas.forEach((cta) => {
      const rect = cta.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top + rect.height / 2;
      const dx = e.clientX - cx;
      const dy = e.clientY - cy;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const intensity = Math.max(0, 1 - dist / radius);
      cta.style.setProperty('--glow', String(intensity));
    });
  };
  window.addEventListener('mousemove', onMove, { passive: true });
}

// v2.4 — Magnetic CTAs.
// Primary buttons translate up to MAX_OFFSET px toward the cursor when it
// enters their proximity radius. Stripe / Linear use this for tactile feel.
function initMagneticCtas() {
  const ctas = document.querySelectorAll<HTMLElement>('.cta-magnetic');
  if (ctas.length === 0 || REDUCED) return;
  const RADIUS = 110;
  const MAX_OFFSET = 7;
  // Reset transforms tracked per element so we can blend smoothly.
  const onMove = (e: MouseEvent) => {
    ctas.forEach((cta) => {
      const rect = cta.getBoundingClientRect();
      const cx = rect.left + rect.width / 2;
      const cy = rect.top + rect.height / 2;
      const dx = e.clientX - cx;
      const dy = e.clientY - cy;
      const dist = Math.sqrt(dx * dx + dy * dy);
      if (dist < RADIUS) {
        const factor = (1 - dist / RADIUS) * (MAX_OFFSET / RADIUS);
        cta.style.setProperty('--mx', `${dx * factor}px`);
        cta.style.setProperty('--my', `${dy * factor}px`);
      } else {
        cta.style.setProperty('--mx', '0px');
        cta.style.setProperty('--my', '0px');
      }
    });
  };
  window.addEventListener('mousemove', onMove, { passive: true });
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

function initAll() {
  initScrollReveal();
  initStatCounters();
  initScrollSpy();
  initScrollProgress();
  initCtaGlow();
  initMagneticCtas();
  initTiltCards();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAll);
} else {
  initAll();
}

// Re-init when navigating between pages via Astro View Transitions
document.addEventListener('astro:page-load', initAll);
