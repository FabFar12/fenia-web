/**
 * Single source of truth for site-wide content singletons.
 *
 * Edit here, not in components.
 * See: docs/ai-context/content-model.md
 *
 * For lists that vary in length (products, testimonials), use Astro Content
 * Collections in `src/content/` instead — schemas live in `src/content.config.ts`.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Site identity
// ─────────────────────────────────────────────────────────────────────────────

export const siteMeta = {
  name: 'FENIA',
  fullName: 'Formación Estratégica de Neuro-Inteligencia Aumentada',
  tagline: 'Neuro-Inteligencia Aumentada',
  description:
    'Formación Estratégica de Neuro-Inteligencia Aumentada. Consultoría, formación y productos digitales para profesionales, emprendedores y empresas.',
  locale: 'es-AR',
  /** Used for canonical URLs, sitemap, OG tags. Override in production via `PUBLIC_SITE_URL` if needed. */
  prodUrl: 'https://fenia.com.ar',
} as const;

// ─────────────────────────────────────────────────────────────────────────────
// WhatsApp — primary conversion channel
// ─────────────────────────────────────────────────────────────────────────────

const WHATSAPP_PHONE = '5493513559947';
const WHATSAPP_DEFAULT_MESSAGE = 'Hola FENIA, quiero consultar sobre sus servicios';

export const whatsapp = {
  /** E.164 without leading `+`, used by `wa.me`. */
  phone: WHATSAPP_PHONE,
  /** Human-readable form, used in copy. */
  displayPhone: '+54 351 355-9947',
  /** Default prefilled message when no message is provided. */
  defaultMessage: WHATSAPP_DEFAULT_MESSAGE,
  /** Build a `wa.me/<phone>?text=<encoded-message>` URL. */
  url(message?: string): string {
    const m = encodeURIComponent(message ?? WHATSAPP_DEFAULT_MESSAGE);
    return `https://wa.me/${WHATSAPP_PHONE}?text=${m}`;
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Social handles
// TODO: real URLs pending owner input (PENDING.md)
// ─────────────────────────────────────────────────────────────────────────────

export const socials = {
  instagram: {
    handle: '@fenia.ok',
    url: 'https://instagram.com/fenia.ok',
    label: 'Instagram',
  },
  linkedin: {
    handle: '@fenia.ok',
    /** TODO: real LinkedIn URL */
    url: null as string | null,
    label: 'LinkedIn',
  },
  youtube: {
    handle: '@fenia.ok',
    /** TODO: real YouTube URL — confirm if FENIA tiene canal */
    url: null as string | null,
    label: 'YouTube',
  },
} as const;

// ─────────────────────────────────────────────────────────────────────────────
// Navigation
// ─────────────────────────────────────────────────────────────────────────────

export type NavLink = {
  label: string;
  href: string;
  kind: 'anchor' | 'cta';
};

export const navLinks: readonly NavLink[] = [
  { label: 'Inicio', href: '#inicio', kind: 'anchor' },
  { label: 'Metodología', href: '#metodologia', kind: 'anchor' },
  { label: 'Servicios', href: '#servicios', kind: 'anchor' },
  { label: 'Productos', href: '#productos', kind: 'anchor' },
  { label: 'Contacto', href: '#contacto', kind: 'anchor' },
];

/**
 * Build an href for an in-page section anchor that works from ANY page.
 *
 * The home page sections (`#inicio`, `#servicios`, …) only exist on `/`.
 * From a sub-page (`/dejanos-tu-testimonio`, `/404`) a bare `#servicios`
 * would resolve to `/dejanos-tu-testimonio#servicios` and go nowhere.
 *
 * - On the home page → returns the bare `#anchor` (pure same-page scroll;
 *   keeps the Nav scroll-spy selector `[href^="#"]` matching).
 * - On any other page → prefixes `/` so the browser navigates home first,
 *   then scrolls to the section.
 */
export function sectionHref(anchor: string, pathname: string): string {
  return pathname === '/' ? anchor : `/${anchor}`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Hero stats
// TODO (PENDING.md #5): align values with reality or derive from real data.
// `productosCount` should ideally be derived from getCollection('products').filter(p => p.data.status === 'live').length
// ─────────────────────────────────────────────────────────────────────────────

export type HeroStat = { label: string; value: string };

// NOTE (PENDING.md #5): preserving current production values verbatim to keep
// this refactor visually identical. The "6" and "12+" are placeholder values
// that need to be reconciled with reality in a follow-up PR with owner approval.
// Proposed honest values (when approved):
//   - "4" / "Áreas de servicio"   (matches `services` length)
//   - "3" / "Audiencias"          (matches `audiences` length)
//   - "100%" / "Enfoque aplicado" (legacy claim, no source)
export const heroStats: readonly HeroStat[] = [
  { value: '6', label: 'Áreas de expertise' }, // TODO: align with reality
  { value: '12+', label: 'Productos digitales' }, // TODO: derive from getCollection('products').filter(live).length
  { value: '100%', label: 'Enfoque aplicado' }, // TODO: validate or replace
];

// ─────────────────────────────────────────────────────────────────────────────
// Audiences (Hero grid + Footer "Soluciones" column)
// ─────────────────────────────────────────────────────────────────────────────

export type AudienceKey = 'profesionales' | 'emprendedores' | 'empresas';

export type Audience = {
  key: AudienceKey;
  title: string;
  summary: string;
  /** Accent color for the top stripe of the card. */
  accent: 'cyan' | 'coral';
};

export const audiences: readonly Audience[] = [
  {
    key: 'profesionales',
    title: 'Profesionales',
    summary:
      'Herramientas, formación y acompañamiento para optimizar tu práctica profesional.',
    accent: 'cyan',
  },
  {
    key: 'emprendedores',
    title: 'Emprendedores',
    summary:
      'Estrategias para ordenar, escalar, decidir mejor y usar tecnología con inteligencia.',
    accent: 'coral',
  },
  {
    key: 'empresas',
    title: 'Empresas',
    summary:
      'Intervenciones para cultura, liderazgo, salud organizacional, talento e innovación.',
    accent: 'cyan',
  },
];

// ─────────────────────────────────────────────────────────────────────────────
// Methodology — the 4 pillars
// ─────────────────────────────────────────────────────────────────────────────

export type MethodologyPillar = {
  num: '01' | '02' | '03' | '04';
  title: string;
  body: string;
  /** When true, the pillar uses the cyan accent treatment. */
  highlight?: boolean;
};

export const methodologyPillars: readonly MethodologyPillar[] = [
  {
    num: '01',
    title: 'Pensamiento estratégico',
    body: 'Cada intervención parte de un diagnóstico profundo. No aplicamos fórmulas: diseñamos soluciones a medida desde el análisis sistémico de tu organización.',
  },
  {
    num: '02',
    title: 'Neuro-inteligencia aplicada',
    body: 'Integramos principios de neurociencias cognitivas y emocionales. Entendemos cómo funciona el cerebro para tomar mejores decisiones y gestionar el cambio.',
    highlight: true,
  },
  {
    num: '03',
    title: 'Inteligencia aumentada',
    body: 'Incorporamos herramientas de IA como potenciadores, no reemplazos. La tecnología amplifica tu capacidad analítica, creativa y operativa.',
  },
  {
    num: '04',
    title: 'Impacto real y escalable',
    body: 'Cada solución se diseña para generar resultados medibles y sostenibles. Trabajamos con indicadores concretos y metodologías escalables.',
  },
];

// ─────────────────────────────────────────────────────────────────────────────
// Services — the 4 service lines (Servicios.jsx tabs)
// ─────────────────────────────────────────────────────────────────────────────

export type Service = {
  slug: 'consultoria' | 'capacitacion' | 'ia-aplicada' | 'productos';
  icon: string;
  title: string;
  subtitle: string;
  body: string;
  bullets: readonly string[];
  /** Canonical audiences (typed). Used for filtering/analytics. */
  audiences: readonly AudienceKey[];
  /** Optional display-only labels. Can include non-canonical categories (e.g. "Directivos"). Falls back to titlecased audiences if absent. */
  audienceLabels?: readonly string[];
};

export const services: readonly Service[] = [
  {
    slug: 'consultoria',
    icon: '◇',
    title: 'Consultoría estratégica',
    subtitle: 'Diagnóstico, diseño e implementación',
    body: 'Trabajamos con líderes y equipos para analizar la situación actual, identificar oportunidades y diseñar intervenciones a medida.',
    bullets: [
      'Diagnóstico organizacional con enfoque neuro-estratégico',
      'Diseño de intervenciones para equipos de alto rendimiento',
      'Acompañamiento en procesos de cambio y transformación',
      'Optimización de toma de decisiones a nivel directivo',
    ],
    audiences: ['empresas'],
    audienceLabels: ['Empresas', 'Directivos'],
  },
  {
    slug: 'capacitacion',
    icon: '△',
    title: 'Capacitación y formación',
    subtitle: 'Liderazgo, gestión emocional y estrategia',
    body: 'Programas formativos que integran contenido científico con práctica aplicada. Cada programa se adapta al contexto y desafíos específicos.',
    bullets: [
      'Programas de liderazgo basados en neurociencias',
      'Gestión emocional y regulación para profesionales',
      'Pensamiento estratégico aplicado a decisiones reales',
      'Workshops intensivos y desarrollo continuo',
    ],
    audiences: ['profesionales', 'empresas', 'emprendedores'],
    audienceLabels: ['Profesionales', 'Empresas', 'Emprendedores'],
  },
  {
    slug: 'ia-aplicada',
    icon: '○',
    title: 'IA aplicada',
    subtitle: 'Integración inteligente de tecnología',
    body: 'Ayudamos a incorporar herramientas de inteligencia artificial de manera estratégica, identificando dónde la IA genera verdadero valor.',
    bullets: [
      'Mapeo de oportunidades de IA en tu operación',
      'Capacitación en herramientas de IA para equipos',
      'Diseño de flujos de trabajo aumentados con IA',
      'Estrategia de adopción gradual y medible',
    ],
    audiences: ['empresas', 'emprendedores', 'profesionales'],
    audienceLabels: ['Empresas', 'Emprendedores', 'Profesionales'],
  },
  {
    slug: 'productos',
    icon: '□',
    title: 'Productos digitales',
    subtitle: 'Recursos listos para aplicar',
    body: 'Guías, toolkits, plantillas y recursos digitales diseñados para aplicar los principios de FENIA de forma autónoma.',
    bullets: [
      'Guías de neuro-liderazgo para implementación directa',
      'Toolkits de IA para emprendedores y profesionales',
      'Plantillas de diagnóstico y planificación estratégica',
      'Módulos de bienestar organizacional',
    ],
    audiences: ['profesionales', 'emprendedores'],
    audienceLabels: ['Profesionales', 'Emprendedores'],
  },
];

// ─────────────────────────────────────────────────────────────────────────────
// Legal — privacy / terms (currently placeholder, see PENDING.md #8)
// ─────────────────────────────────────────────────────────────────────────────

export const legal = {
  /** When `null`, the link is hidden from the footer instead of pointing to `#`. */
  privacyUrl: null as string | null,
  termsUrl: null as string | null,
  copyrightYearStart: 2026,
};
