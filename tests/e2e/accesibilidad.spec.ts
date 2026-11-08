// Accessibility (ADR-010, WCAG AA) of the pages that sell, with axe-core.
// Fails on any NEW "serious" or "critical" problem. Problems already known are
// listed in KNOWN: they change how the site looks, so fixing them waits for an
// approved design decision. Each run reports the known ones still present.
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { catalog } from './helpers';

/** Found by this battery on 2026-11-08 (docs/ventas, accessibility debt). */
const KNOWN: { rule: string; target: RegExp; why: string }[] = [
  {
    rule: 'color-contrast',
    target: /#buy-submit|#resend-submit|\.kit-offer-badge|\.promo-chip/,
    why: 'texto blanco sobre el coral de la marca (#E8573D): 3,6:1, AA pide 4,5:1. Es el color de todos los botones del sitio',
  },
  {
    rule: 'color-contrast',
    target: /^#productos > div:nth-child\(2\) > div:nth-child\(1\)/,
    why: 'etiqueta gris de sección ("PRODUCTOS DIGITALES") sobre fondo oscuro',
  },
  {
    rule: 'link-in-text-block',
    target: /no-me-llego|mailto:|target="_blank"/,
    why: 'links dentro de un párrafo que se distinguen solo por el color (sin subrayado) en /gracias y /descarga',
  },
];

const pages: [string, string, string[]][] = [
  ['home: franja y productos', '/', ['.promo-bar-wrap', '#productos']],
  ['página del kit', '', ['main']],
  ['/gracias', '/gracias/', ['main']],
  ['/descarga', '/descarga/', ['main']],
  ['No me llegó', '/no-me-llego/', ['main']],
];

for (const [name, path, include] of pages) {
  test(`accesibilidad: ${name}`, async ({ page }) => {
    let url = path;
    if (!url) {
      const kit = (await catalog(page)).find((k) => k.status === 'live');
      test.skip(!kit, 'no hay kits a la venta');
      url = `/kits/${kit!.id}/`;
    }
    await page.goto(url);
    await page.waitForTimeout(2500);
    let axe = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']);
    for (const selector of include) axe = axe.include(selector);
    const { violations } = await axe.analyze();

    const fresh: string[] = [];
    for (const v of violations.filter((x) => x.impact === 'serious' || x.impact === 'critical')) {
      for (const node of v.nodes) {
        const target = node.target.join(' ');
        const known = KNOWN.find((k) => k.rule === v.id && k.target.test(target));
        if (known) test.info().annotations.push({ type: 'conocido', description: `${v.id} → ${target}: ${known.why}` });
        else fresh.push(`${v.id} (${v.impact}): ${v.help} → ${target}`);
      }
    }
    expect(fresh, `Problemas de accesibilidad nuevos:\n${fresh.join('\n')}`).toEqual([]);
  });
}
