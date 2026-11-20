# ADR-030 — Trainings: self-contained apps on the site, and "Conversar con IA" phase A

## Status
Accepted (2026-11-18, decisions of the dev on the plan for "Conversar con IA sin creerle todo"). Extends [ADR-027](./ADR-027-product-schema-and-lead-capture.md) (product schema) and [ADR-029](./ADR-029-private-metrics-and-owner-accounts.md) (metrics). Amends [ADR-023](./ADR-023-htaccess-hosting-config.md): a folder of apps gets its own `.htaccess`. Phase B (sales) is **not** decided here.

## Context

FENIA has a new product, separate from the kits: **Conversar con IA sin creerle todo**, a 6-week training with 5-minute daily sessions for the general public. It is not downloaded: it is used in the phone's browser. The product team delivers it as self-contained HTML files (style, script, font and icons inside) that keep the progress in the browser's `localStorage`, under the key `fenia_programa_conversar-con-ia_v1`.

The handoff (local folder `handoff-conversar-con-ia/`, never in git) brings three files and the security policy each one was tested with:

| File | What it is | Public? |
|---|---|---|
| `muestra.html` | Day 0, free, with an invitation to the sales page | Yes, linked from the sales page |
| `piloto.html` | Day 0 + week 1, for the pilot, with feedback by WhatsApp | Not linked, shared by hand |
| `programa.html` | The paid program (today: day 0 + week 1) | Only for buyers, from phase B |

Fixed constraints from the product team:
- **Same origin, same key.** `localStorage` is per origin, so the sample, the pilot and the program must be served from `https://fenia.com.ar` for day 0 to carry over after buying.
- **The files are not edited.** Each one has a Content-Security-Policy with the SHA-256 hash of its only script and its only style. If one byte changes, the browser blocks the app. Changes are asked for and the files are regenerated with new hashes.
- `Cache-Control: no-cache`, so every update reaches whoever already started.

## Decision

The dev approved the plan on 2026-11-18, with these decisions:

1. **Routes.** `muestra.html` and `piloto.html` are copied byte for byte to `public/app/conversar-con-ia/` and served at `/app/conversar-con-ia/`. The sales page is `/entrenamientos/conversar-con-ia/`, the address the sample already links to.
   - **`programa.html` stays out of everything public until phase B.** Whatever is in `public/` anyone can open with the address, even if nothing links it. In phase B it is served from the server's private folder, behind the buyers' login, **at the same address**.
   - `piloto.html` leaves `public/` when the pilot ends; the dev says when. The next deploy deletes it from the server. It stays in this public repo's history.
2. **Security per file.** `public/app/conversar-con-ia/.htaccess` sets, for each file, the exact policy it was tested with:

   ```
   default-src 'none'; script-src <script hash>; style-src <style hash>; font-src data:; img-src data: blob:; base-uri 'none'; form-action 'none'; frame-ancestors 'self'
   ```

   It replaces the site's policy for those files only (LiteSpeed applies a folder's `<Files>` blocks after the parent's, as with `api/.htaccess`). It also sets `no-cache`, `X-Robots-Tag: noindex` and UTF-8. No `mod_rewrite` in that folder: it would switch off the parent's HTTPS, `www` and staging rules for those files.
   - **Guarded in CI.** `scripts/verify-app-csp.mjs` recomputes the hashes of every file under `public/app/` and compares them with its `.htaccess` and with the product team's `csp-hashes.json`, kept as is in `scripts/app-csp-hashes/<name>.json` (since v0.4). It also checks that the build copied the files untouched, that no file without a policy lives there, and that no `programa.html` exists in `public/` or `dist/`. The CI guard also rejects any committed `programa.html`. `.gitattributes` marks `public/app/**/*.html` as `-text`, so Git never converts their line endings.
   - **Checked on the live site.** S-50…S-53 (security suite) compare the served bytes and headers with the repo, behind Hostinger's CDN.
3. **Product schema** (`src/content.config.ts`):
   - type **`Entrenamiento`**, shown in the same pill where the kits say "Caja de herramientas";
   - status **`sample`** ("muestra disponible"), which needs a `sampleUrl`;
   - optional `plannedPrice`, the launch price announced as planned. Nothing is charged with it; when sales open, the launch price goes in `promo`, with a real deadline (D23).
   - deliveryMode **`web-app`**.
   - **Fail closed:** the schema and U-31 refuse a `live` training until buyer access exists. The sales API only charges `live` products (C18), and S-54 checks it on the live site.
   - Trainings are not kits. They have no `/kits/<id>/` page and are left out of the kits grid.
   - **Featured card above the trilogy** in Productos (proposal approved 2026-11-18; the dev left the details to the AI). It shows the training's pill, a «Día 0 gratis» badge, its summary and three facts, and has **one button**, «Conocé el entrenamiento», to the sales page. The measurement the dev chose lives on the sales page (visits and «Probá gratis» clicks), so the card does not jump straight to the sample. The kits get the label «Kits para educación». The section intro no longer says «descargables», since a training is not downloaded. The card copy is in the training's YAML (`card`); without that block, no card.
4. **Sales page.** `/entrenamientos/[id].astro`, a template reusable for future trainings. The copy is in `src/content/trainings/<id>.yaml`, taken from the approved `pagina-de-venta.md`. Price, status and sample stay in the product's `.md`.
   - Phase A buttons: «Probá gratis el día 0» opens the sample, and «Avisame cuando esté completo» goes to `/recursos-gratuitos?producto=<id>`.
   - Visible price: «Precio de lanzamiento previsto: $15.000 (lista $30.000)». No purchase button.
   - Links to the sample force a full page load (`data-astro-reload`). Otherwise the site's client router would try to swap the app into an Astro page, under the site's policy.
   - Not published until defined: the bracketed items (access duration, cancellation-right text) and the testimonials (only real ones, with written permission, after the pilot).
5. **Email capture in phase A:** the existing `/recursos-gratuitos` page, with WhatsApp as the fallback. The Mailerlite form becomes a priority; Fab creates it, and the same button starts saving emails once `PUBLIC_MAILERLITE_FORM_URL` is set.
6. **Measurement (amends ADR-029):** nothing inside the apps. The sales page is measured instead:
   - its visits, with the normal counter;
   - clicks on «Probá gratis», a new event kind `sample` that the links marked `data-sample` send with the page the click came from.

   `/metricas/` shows a card per training: visitors of the page, clicks, and people who tried the sample (percent of visitors). The catalogue now says which page each product is sold on (`page`), which the purchase funnel also uses.
7. **Buyers' access (phase B):** option 1 of the handoff, "the server checks the purchase before serving the file". It is designed once for kits and trainings: login with the buyer's email, through a passwordless link. It is the "Mis kits" phase 2 of D25. **Not built yet.** It is decided before phase B, together with the cancellation button (Res. SCI 424/2020).

**Amendment 2026-11-20 (v0.4, approved by the dev).** The product team rebuilt sessions 1, 2 and 4: a random number, «¿qué supusiste de mí?» and a question about something the person knows, with `[brackets]` to fill in. The three HTML files, their hashes (the style's too) and the captures 04 to 07 of the sales page were replaced. The storage key did not change, so progress already made is kept. The package lives in the local, gitignored folder `actualizacion-conversar-con-ia-v0.4/`, and its `programa.html` stays there until phase B: no copy goes to the server before buyer access exists.

**Amendment 2026-11-20 (v0.4.1, dev's decision): the apps are edited here.** The FENIA development environment (this repo) changes and decides on the apps. The product environment (the conversation that wrote the brief) provides material and aesthetic decisions, and receives a summary of each change so its next version starts from it. That summary lives in the local folder `conversar-con-ia-v0.4.1/`, gitignored, together with `programa.html`.

v0.4.1 adds (phone UX, approved by the dev):
- an icon and a name on the home screen: the site's FENIA icons and `/conversar-con-ia.webmanifest`;
- inside Instagram, Facebook or TikTok browsers, a notice on the start screen to open it in the phone's browser («Abrir en Chrome» on Android, two steps on iPhone, «Copiar el link»);
- «Agregar a Google Calendar» next to the calendar file, first on Android;
- the bottom bar hides while the keyboard is open;
- the session 1 summary «No te dio un solo número.»

The policy now also allows images from the site itself (`img-src 'self'`) and the manifest (`manifest-src 'self'`).

The manifest uses `display: browser`, not full screen, on purpose. On iPhone a full-screen web app keeps its own storage apart from Safari's (finding H-12), and the progress must never look lost.

**Updating an app (procedure).**
1. Change the HTML in `public/app/<name>/` and the local `programa.html`.
2. Run `node scripts/update-app-csp.mjs <name> <local programa.html>`. It writes each file's policy in the folder's `.htaccess` and the hashes in `scripts/app-csp-hashes/<name>.json`.
3. Run `node scripts/verify-app-csp.mjs`.

CI refuses the deploy if the files, the `.htaccess` and the JSON do not agree. The policy is defined once, in `scripts/app-csp-policy.mjs`.

## Consequences

- The sample is public now, on the same origin as the future program, so day 0 progress carries over after buying.
- Every update of an app is a regenerated file plus its new hashes, in both the file and the `.htaccess`. CI refuses a mismatch before deploying.
- The training cannot be sold by mistake: the schema, U-31 and the API all refuse it while it is not `live`, and `live` is refused until phase B exists.
- On iPhone, an app added to the home screen keeps its data apart from Safari's, so day 0 progress made in Safari does not show up under the icon (finding H-12 in docs/ventas). It is iOS behaviour, not the site's. The progress code in Ajustes moves it; the product team is asked for an in-app notice in the next regeneration.
- The apps measure nothing themselves. The sample's real use (who finishes day 0) is unknown until the product team adds it in a future regeneration.
- The repo is public: `muestra.html` and `piloto.html` (day 0 and week 1) are readable on GitHub, also after the pilot. `programa.html` never enters it (CI guard).
- Tests: U-54 and U-55 (PHPUnit), S-50…S-55 (security suite), E2E-07 (sales page, 360 px, the sample under its policy, the counted click), and an axe check and a reference screenshot of the page.
