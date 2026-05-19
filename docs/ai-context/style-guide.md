# Style Guide — Visual System

> Last updated: 2026-05-19.
> Authoritative for: colors, typography, spacing, components patterns.
> See [ADR-004](../adr/ADR-004-styling-strategy.md) for the migration strategy from inline styles to Tailwind.

## Brand identity

| Token | Hex | Role |
|---|---|---|
| `navy` | `#0B1A2E` | Default page background, hero, productos, contacto |
| `navy.light` | `#0F2138` | Servicios section background (subtle separation) |
| `navy.mid` | `#132847` | Confianza section background |
| `navy.dark` | `#060E1A` | Footer background |
| `cyan` | `#00B4D8` | Primary accent — value-prop highlights, primary stats, "neuro" |
| `coral` | `#E8573D` | Secondary accent — CTAs, "Solicitar un turno" buttons, emprendedores audience |
| `white` | `#FFFFFF` | Headings, primary text |
| `white/65` | `rgba(255,255,255,0.65)` | Body text (post-WCAG fix; old code uses 0.3–0.45) |
| `white/30` | `rgba(255,255,255,0.30)` | Labels, captions, decorative text |

These tokens are defined in [`tailwind.config.mjs`](../../tailwind.config.mjs). **Always reference tokens, never inline hex.**

## Typography

- **Font family**: Plus Jakarta Sans (self-hosted via `@fontsource`).
- **Weights used**: 400, 500, 600, 700, 800. (Will reduce to 400, 600, 800 in Fase 1.6 to save ~100 KB of fonts.)
- **Letter-spacing**: `-0.02em` to `-0.03em` on large headings (h1, h2). Default elsewhere.
- **Line-height**: `1.1` on large headings, `1.5–1.7` on body.

### Type scale (approximate, post-migration target)

| Use | Size | Weight | Letter-spacing |
|---|---|---|---|
| Hero H1 | 50px (mobile: 34px) | 800 | -0.03em |
| Section H2 | 38–40px (mobile: 26–28px) | 800 | -0.03em |
| Card H3 | 17–22px | 700 | -0.02em |
| Subheading / lead | 16–18px | 600 | -0.01em |
| Body | 14–16px | 400/500 | normal |
| Caption / label | 11–13px | 600 (often uppercase, letter-spacing 0.06–0.1em) | varies |

## Color contrast — WCAG AA

⚠️ **Critical** (Plan Maestro Fase 1.4): The existing code uses `rgba(255,255,255,0.3)` and `rgba(255,255,255,0.4)` for body text on `#0B1A2E`. These fail WCAG AA contrast for normal text. **Always use `white/65` or higher for body text.**

Decorative captions (uppercase labels, footer fine-print) can stay below WCAG AA on the basis of being non-essential — but never primary content.

## Layout primitives

### Section container

```html
<section id="..." class="bg-navy relative overflow-hidden">
  <div class="max-w-[1200px] mx-auto px-12 py-24 relative z-10">
    <!-- content -->
  </div>
</section>
```

- Max width: **1200px** consistently across sections.
- Vertical padding: **100px desktop / 60px mobile** for marketing sections; **40px / 60px** for less prominent ones.
- Horizontal padding: **48px desktop / 20px mobile**.

### Badge (used as section eyebrow)

```html
<div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full border border-white/10">
  <span class="w-1.5 h-1.5 rounded-full bg-cyan"></span>
  <span class="text-[11px] font-semibold uppercase tracking-widest text-white/40">
    Nuestro enfoque
  </span>
</div>
```

Use cyan dot for "Inicio / Metodología / Servicios / Confianza" eyebrows, coral dot for "Productos / Contacto" eyebrows. Pattern is consistent throughout.

### Card

- Background: `bg-white/[0.02]` (subtle elevation).
- Border: `border border-white/10`.
- Border radius: `rounded-2xl` (16px) for content cards, `rounded-xl` (12px) for small cards.
- Padding: `p-8` desktop, `p-6` mobile.
- Hover state: brighter border (`hover:border-white/20`) — not yet implemented; queued.

### CTA buttons

| Variant | Background | Text | Use |
|---|---|---|---|
| Primary | `bg-coral` | `text-white` | "Solicitar un turno", "Hablemos", "Consultar →" |
| Secondary outline | `bg-white/[0.03]` `border-white/15` | `text-white` | "Comprar productos digitales", "Ver soluciones →" |
| Subtle / inline | none, just colored text | `text-cyan` or `text-coral` | "Ver soluciones →" link inside cards |

Always include `box-shadow` for primary CTAs: `shadow-[0_4px_20px_rgba(232,87,61,0.3)]`.

## Responsive breakpoints

Tailwind defaults — use them, don't introduce custom ones:

- `sm:` 640px
- `md:` 768px (current mobile/desktop boundary)
- `lg:` 1024px
- `xl:` 1280px

**Mobile-first only**. The legacy `global.css` does desktop-first with `@media (max-width: 768px)` + `!important`. As components are migrated to Tailwind, the corresponding override blocks in `global.css` must be deleted.

## Decorative SVG patterns

- **Dot grid background**: `<pattern id="..." width="40" height="40"><circle r="0.6" fill="rgba(255,255,255,0.04)"/></pattern>` — used in Hero and Metodología for ambient texture.
- **Neural network**: handcrafted set of `<circle>` + `<line>` elements in Hero. **Will be extracted** to `<NeuralNetwork.astro>` component for reuse (Plan Maestro Fase 2.5).
- **Convergence diagram**: in Metodología's "integration block" — small `<svg width="140">` with cyan/coral nodes around a center.

## Animation principles

Today the site is essentially static (no scroll animation, no hover transitions on most elements, no microinteractions).

**Target** (proposal — needs owner approval per AGENTS.md):

- Subtle fade-in-up on section reveal (IntersectionObserver-driven, ≤300ms).
- Hover lift on cards (translateY(-2px) + shadow intensification).
- Active nav link tracking via scroll spy.
- Smooth scroll already enabled in `global.css` (`html { scroll-behavior: smooth; }`).

Never use animation that:
- Auto-plays sound (we never auto-play sound).
- Is purely decorative on mobile (battery cost).
- Exceeds 400ms total duration.
- Locks the user out of content (the IntroLoader is the current offender — Fase 1.3 fix in progress).

## Forbidden patterns

1. ❌ **Inline `style="background: #00B4D8"`.** Use `class="bg-cyan"`.
2. ❌ **New hex values not declared in `tailwind.config.mjs`.** Add the token, then use it.
3. ❌ **Descendant positional selectors** like `#hero > div:first-child > div:last-child { display: none }`. Use mobile-first utilities (`hidden md:block`).
4. ❌ **`!important`** in any CSS rule. If you need it, your selector specificity is wrong.
5. ❌ **Multiple `<style>` blocks with overlapping selectors.** Either Tailwind utilities or `@layer components` in `global.css` — never both for the same concern.

## Reference order when in doubt

1. [`tailwind.config.mjs`](../../tailwind.config.mjs) — the source of truth for design tokens.
2. This file — for patterns and rationale.
3. [`docs/adr/ADR-004-styling-strategy.md`](../adr/ADR-004-styling-strategy.md) — for migration tactics.
4. Then existing code — but treat with caution; much of it is the legacy you're refactoring away from.
