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

  const setActive = (id: string) => {
    navLinks.forEach((l) => l.classList.remove('nav-link-active'));
    const matching = document.querySelector(`.nav-link[href="#${id}"]`);
    matching?.classList.add('nav-link-active');
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

function initAll() {
  initScrollReveal();
  initStatCounters();
  initScrollSpy();
  initScrollProgress();
  initCtaGlow();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAll);
} else {
  initAll();
}

// Re-init when navigating between pages via Astro View Transitions
document.addEventListener('astro:page-load', initAll);
