// scripts/inspect-hero.mjs — diagnostic screenshot + computed style dump.
//
// Usage: `node scripts/inspect-hero.mjs`
// Prerequisite: dev server running at http://localhost:4321/ (or override URL below).

import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';

const URL = process.env.URL || 'http://localhost:4321/';
const OUT_DIR = 'tmp';

await mkdir(OUT_DIR, { recursive: true });

console.log(`→ Launching headless Chromium`);
const browser = await chromium.launch({ headless: true });

try {
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
    serviceWorkers: 'block',
  });
  const page = await ctx.newPage();

  // Forward console + page errors so we see what the page is screaming about.
  page.on('console', (msg) => console.log(`  [page console.${msg.type()}] ${msg.text()}`));
  page.on('pageerror', (err) => console.log(`  [page error] ${err.message}`));
  page.on('requestfailed', (req) => console.log(`  [page request failed] ${req.url()} -- ${req.failure()?.errorText}`));

  console.log(`→ Navigating to ${URL}`);
  await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 30_000 });

  // Take an immediate screenshot of whatever was rendered.
  await page.screenshot({ path: `${OUT_DIR}/00-after-domcontentloaded.png`, fullPage: false });
  console.log(`→ Saved: ${OUT_DIR}/00-after-domcontentloaded.png`);

  // Let JS settle.
  await page.waitForTimeout(2000);

  await page.screenshot({ path: `${OUT_DIR}/01-after-2s.png`, fullPage: true });
  console.log(`→ Saved: ${OUT_DIR}/01-after-2s.png (full page)`);

  // Dump diagnostic info.
  const diag = await page.evaluate(() => {
    const heroSection = document.querySelector('section.hero-video');
    const heroFrame = document.querySelector('.hero-video-frame');
    const heroImg = document.querySelector('.hero-video-asset');
    const navBrand = document.querySelector('.nav-brand');

    const summarize = (el) => {
      if (!el) return null;
      const cs = window.getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      return {
        tag: el.tagName,
        classes: el.className,
        dataAstroCid:
          Array.from(el.attributes).find((a) => a.name.startsWith('data-astro-cid'))?.name ?? null,
        display: cs.display,
        visibility: cs.visibility,
        opacity: cs.opacity,
        padding: `${cs.paddingTop} ${cs.paddingRight} ${cs.paddingBottom} ${cs.paddingLeft}`,
        borderRadius: cs.borderTopLeftRadius,
        background: cs.backgroundImage,
        boxShadow: cs.boxShadow,
        width: Math.round(rect.width) + 'px',
        height: Math.round(rect.height) + 'px',
        x: Math.round(rect.x) + 'px',
        y: Math.round(rect.y) + 'px',
      };
    };

    return {
      url: location.href,
      title: document.title,
      htmlBytes: document.documentElement.outerHTML.length,
      heroSection: summarize(heroSection),
      heroFrame: summarize(heroFrame),
      heroImg: summarize(heroImg),
      navBrand: summarize(navBrand),
      heroVideoFrameSelector: document.querySelectorAll('.hero-video-frame').length,
      heroAssetSelector: document.querySelectorAll('.hero-video-asset').length,
      navBrandSelector: document.querySelectorAll('.nav-brand').length,
    };
  });

  console.log('\n=== Diagnostic dump ===');
  console.log(JSON.stringify(diag, null, 2));

  await writeFile(`${OUT_DIR}/diag.json`, JSON.stringify(diag, null, 2));
  console.log(`→ Saved: ${OUT_DIR}/diag.json`);

  // If we found the frame, screenshot it specifically.
  if (diag.heroFrame) {
    try {
      await page.locator('.hero-video-frame').first().screenshot({ path: `${OUT_DIR}/hero-frame.png` });
      console.log(`→ Saved: ${OUT_DIR}/hero-frame.png`);
    } catch (e) {
      console.log(`  Could not screenshot .hero-video-frame: ${e.message}`);
    }
  }
  if (diag.navBrand) {
    try {
      await page.locator('.nav-brand').first().screenshot({ path: `${OUT_DIR}/nav-brand.png` });
      console.log(`→ Saved: ${OUT_DIR}/nav-brand.png`);
    } catch (e) {
      console.log(`  Could not screenshot .nav-brand: ${e.message}`);
    }
  }
} finally {
  await browser.close();
}

console.log('\n✓ Done.');
