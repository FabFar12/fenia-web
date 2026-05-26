# PENDING — Tasks the AI cannot complete alone

> This file tracks **what the human owner / collaborators need to provide or decide** so the AI can continue. Keep it short and dated. When an item is resolved, delete it from here and write the resolution into the relevant ADR or context file.

> Last sync: 2026-05-26 — Hostinger cutover **technically complete**, three formal-close items remain. Full session snapshot: [docs/audit/2026-05-26-cutover-status.md](./docs/audit/2026-05-26-cutover-status.md). **The next AI / operator should read that audit doc first.**

---

## 🔴 Blockers (needed before specific work can proceed)

### 1. Visual confirmation by dev that Hostinger is live in their browser — 2026-05-26
**Why it matters:** Independent verification (`curl --resolve` from outside) confirms Hostinger is serving correctly with all security headers. But Matías's own browser still had cached the old Vercel IP at session close. Until the dev sees `Server: LiteSpeed` + `Remote Address: 147.79.84.112` in their own DevTools, the cutover is not formally closed.
**Dev action (Matías):**
  1. `ipconfig /flushdns` in CMD as admin.
  2. `chrome://net-internals/#dns` → Clear host cache.
  3. `chrome://net-internals/#sockets` → Flush socket pools.
  4. Close all tabs of fenia.com.ar; open a fresh incognito window; visit `https://fenia.com.ar/`.
  5. F12 → Network → reload → check Response Headers: must show `Server: LiteSpeed`, `platform: hostinger`, plus the 6 security headers (HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy).
**Acceptance:** screenshot of the headers confirming Hostinger. Then mark resolved.

### 2. Pause the Vercel project — 2026-05-26
**Why it matters:** Hostinger is serving the live domain. Vercel is still receiving auto-deploys on every push to `main` (the integration is still connected). Until paused, it's wasted CI minutes and a risk of confusion. **DO NOT DELETE** for one week — keep as a rollback safety net.
**Owner action (Fab) — pick one:**
  - Invite the dev (`matiasslpknt08@gmail.com`) to the Vercel project as collaborator. Dev then pauses.
  - OR pause it directly: Vercel dashboard → `fenia-web` project → Settings → Git → Disconnect / Pause auto-deploys. Then confirm to dev.
**After 7 stable days:** delete the Vercel project; if on a paid plan, cancel the subscription.

### 3. Hostinger plan tier confirmation — carried over
**Why it matters:** We know the plan was prepaid for 4 years at ARS 200K. We don't know the exact tier (Premium / Business / Cloud Startup / …). The tier affects:
  - Whether SSH/SFTP is available (would let us switch the deploy from FTPS to SFTP).
  - Daily backup window and retention.
  - Multi-site/subdomain capabilities (for a future `staging.fenia.com.ar`).
**Owner action:** ask the friend who set it up, then document in [docs/ai-context/deployment.md](./docs/ai-context/deployment.md).

### 4. Observability baseline ([ADR-024](./docs/adr/ADR-024-observability-on-hostinger.md)) — 2026-05-26
**Why it matters:** Today the site is in production with zero monitoring. A JS error breaks for users silently; a site outage is only noticed when someone happens to visit. Required before declaring the migration "done".
**Dev action — three pieces, all free or near-free:**
  - **UptimeRobot** (free): monitor `HEAD https://fenia.com.ar/` every 5 min, alert dev email on 2 consecutive failures. 10 min to set up.
  - **Sentry browser SDK** (free tier 5K events/mo): create project, copy DSN to GitHub Secret `PUBLIC_SENTRY_DSN`, initialise in `src/layouts/Layout.astro` (lazy-load). Update CSP in `public/.htaccess`: add `https://*.ingest.sentry.io` to `connect-src`.
  - **Plausible Cloud** (USD 9/mo): create site, copy script, gate behind `PUBLIC_PLAUSIBLE_DOMAIN`. Update CSP: add `https://plausible.io` to `script-src` and `connect-src`.
**Acceptance:** UptimeRobot reporting green, Sentry receiving events from a deliberate test, Plausible counting page views.

### 5. Dependabot PRs from the first run — 2026-05-26
**Why it matters:** When `.github/dependabot.yml` first landed, Dependabot opened 5 automatic PRs. They were not reviewed during the cutover session; they're sitting open.
**Dev action:** at `https://github.com/FabFar12/fenia-web/pulls`:
  - `chore(deps): Bump the minor-and-patch group with 8 updates` — review diff, run CI, merge to `dev` if green.
  - `chore(deps): Bump SamKirkland/FTP-Deploy-Action 4.3.5 → 4.4.0` — minor bump; merge if release notes don't break anything.
  - `chore(deps): Bump actions/checkout 4 → 6` — major; verify release notes for breaking changes.
  - `chore(deps): Bump actions/setup-node 4 → 6` — major; verify release notes.
  - `chore(deps-dev): Bump typescript 5.9.3 → 6.0.3` — major; verify nothing in the Astro toolchain breaks under TS 6.

### 6. Verify Vercel plan tier for clean shutdown — carried over from 2026-05-19
**Why it matters:** When pausing/deleting the Vercel project, we want to know if there's a paid subscription to cancel (avoid surprise billing).
**Owner action:** check the Vercel account billing or send a screenshot of the project's plan tier to the dev.


### 7. Catálogo real de productos — carried over from 2026-05-19
**Why it matters:** Los 3 productos que aparecen hoy en producción (Guía de Neuro-Liderazgo, Toolkit de IA para Emprendedores, Método de Bienestar Organizacional) **fueron inventados por una IA**. No existen. Mostrarlos como reales es riesgo reputacional.
**Owner action:** Tu amigo tiene que decidir:
  - ¿Cuántos productos digitales reales planea tener en los próximos 3 meses?
  - Para cada uno: título, formato (PDF / curso video / Notion / etc.), precio (o "consultar"), público objetivo, status (en desarrollo / próximamente / disponible).
  - Mientras tanto, los 3 productos actuales quedan marcados con badge **"Próximamente"** (decisión confirmada 2026-05-19).
**Cómo se agregan:** Crear un archivo nuevo en `src/content/products/{slug}.md` con el frontmatter documentado en [docs/ai-context/content-model.md](./docs/ai-context/content-model.md).

### 8. Testimonios reales — carried over from 2026-05-19
**Why it matters:** Los 3 testimonios anónimos actuales eran placeholder y se han retirado de producción. La decisión confirmada (2026-05-19) es usar **Nivel A — Manual asistido con Tally + curación manual**.
**Estado:** La sección Confianza ya **NO se renderiza** en la home (commit en `dev`). La página pública `/dejanos-tu-testimonio` ya existe y enlaza desde la sección Contacto. Sin el form de Tally, esa página muestra un fallback elegante que invita a enviar el testimonio por WhatsApp.
**Owner action — siguientes pasos:**
  1. Crear un formulario en Tally (`https://tally.so`) con campos:
     - Nombre completo (requerido)
     - Cargo y empresa/proyecto (requerido)
     - LinkedIn (opcional)
     - Foto (opcional)
     - Texto del testimonio (recomendado <280 chars)
     - Checkbox de consentimiento explícito de publicación (requerido)
  2. Copiar el link del formulario embebido (URL tipo `https://tally.so/embed/...` o `https://tally.so/r/...`).
  3. Configurar **en el proyecto de Cloudflare Pages** (Settings → Environment Variables) la variable **`PUBLIC_TALLY_TESTIMONIAL_FORM_URL`** con ese link, para **Production** y **Preview**. Cuando exista, la página `/dejanos-tu-testimonio` automáticamente embebe el formulario.
  4. Empezar a pedir testimonios por WhatsApp a clientes reales tras cada engagement.
  5. Cuando llegue un testimonio aprobado y con consentimiento explícito → el dev lo agrega como `.md` en `src/content/testimonials/`. Una vez haya al menos 1, restaurar el render de `<Confianza />` en `src/pages/index.astro` (línea comentada).

### 9. KPIs reales del Hero y de la sección Confianza — carried over from 2026-05-19
**Why it matters:** "6 áreas de expertise", "12+ productos digitales", "100% enfoque aplicado", "95% satisfacción en formaciones", "100% enfoque basado en evidencia" son cifras sin fuente documentable.
**Owner action:** Definir con tu amigo:
  - ¿Cuántas áreas reales? (probablemente 4: consultoría, capacitación, IA aplicada, productos digitales).
  - ¿Cuántos productos digitales habrá? (ver item #4).
  - ¿Hay un dato de satisfacción real medido (ej. encuesta de fin de formación)? Si no, sacarlo.
  - ¿Cuántas audiencias atendemos? (3: profesionales, emprendedores, empresas — esto sí es real).
**Mientras tanto:** Las cifras quedan documentadas como placeholder en `src/data/site.ts` con comentarios `// TODO: validar con dueño` para que sea trivial cambiarlas.

---

## 🟡 Decisiones de producto pendientes

### 10. Activación del primer producto real (Guía para evaluar en tiempos de IA) — 2026-05-26
**Estado:** decisión arquitectónica cerrada en [ADR-025](./docs/adr/ADR-025-product-sales-via-mercadopago.md). Infraestructura del sitio **lista para vender**:

- ✅ Producto creado en `src/content/products/guia-evaluar-tiempos-ia.md` con `status: "coming-soon"` (esperando datos de Fab para pasar a `live`).
- ✅ Los 3 productos placeholder anteriores pasaron a `status: "draft"` (ocultos del sitio).
- ✅ Página `/gracias` creada en `src/pages/gracias.astro` (mensaje post-compra + WhatsApp de soporte si el email no llega).
- ✅ Guía paso-a-paso para Fab en [`docs/fab-mercadopago-setup.md`](./docs/fab-mercadopago-setup.md) — pasalá tal cual y volvé con los 5 datos del final.

**Bloqueantes — Fab debe proveer (seguir `docs/fab-mercadopago-setup.md`):**
1. **Cuenta Mercado Pago Vendedor activa** (o confirmar que usará la existente de la consultoría).
2. **Crear el cobro en Mercado Pago** con:
   - PDF como entrega automática.
   - URL de retorno: `https://fenia.com.ar/gracias` (ya creada).
   - Cuotas sin interés según precio (ver guía).
3. **Link de cobro** generado (formato `https://link.mercadopago.com.ar/<id>` o `https://mpago.la/<id>`).
4. **Metadatos**: título oficial, descripción larga, precio en ARS, imagen 1200×630 (opcional).
5. **Test end-to-end** propio antes de pasar el link (Fab compra a sí mismo, valida que llega el email).

**Dev actions una vez recibidos los 5 datos:**
1. Editar `src/content/products/guia-evaluar-tiempos-ia.md`:
   - `status: "live"`.
   - `cta.label: "Comprar ahora"`.
   - `cta.href: <link MP>`.
   - `price: <número>`.
   - Reemplazar el cuerpo del `.md` con la descripción real.
2. (Opcional) Mejorar `Productos.astro` para mostrar precio formateado en cards `live` con price ≠ null. Cambio visual → PROPOSE-THEN-EXECUTE primero.
3. Push a `dev` → merge a `main` → deploy automático a Hostinger.
4. Test end-to-end por Fab con compra real.

### (Cerrado) ¿Sección Productos debe vender o solo informar? — 2026-05-19 → resuelto 2026-05-26
Decisión tomada en [ADR-025](./docs/adr/ADR-025-product-sales-via-mercadopago.md): **Productos vende**, vía Mercado Pago Link de Pago. Tier-B (checkout embebido + backend PHP) diferido hasta validar con volumen real (≥20 ventas/mes o ≥3 productos live).

### 11. ¿Quién aparece en la sección "Quién está detrás"? — carried over from 2026-05-19
Hoy no existe. Una consultoría humana sin caras pierde credibilidad. ¿Tu amigo trabaja solo? ¿Tiene socios? ¿Quiere mostrar bio + foto + LinkedIn?

### 12. Política de privacidad y términos legales — carried over from 2026-05-19
Los links del footer (`href="#"`) están muertos. Para cumplir Ley 25.326 (Argentina) — si en algún momento se captura un email/teléfono en un formulario propio — hace falta:
  - Política de privacidad real (puede generarse con templates legales argentinos).
  - Términos de servicio si vendemos productos digitales.
**Owner action:** Hablar con un abogado o usar un template estándar argentino.

---

## ⚪ Activos de marca pendientes

### 13. Favicon propio — carried over from 2026-05-19
Hoy se usa el de Astro (`public/favicon.svg`). Tu amigo tiene un logo F→FENIA (visible en Nav.astro), pero falta un favicon optimizado (16/32/180/192/512 px).
**Owner action:** Exportar el logo F + nodos como favicon set, o pedir al diseñador.

### 14. Open Graph image — carried over from 2026-05-19
No hay imagen para compartir en WhatsApp / LinkedIn / Twitter cuando se pega el link. Hace falta un `og-image.jpg` 1200×630 px en `public/og-image.jpg`. El Layout ya está listo para enchufarla apenas exista.

---

## ✅ Resueltos (referencia histórica)

Tareas que sí estaban pendientes en algún momento y ya están cerradas. Se quedan acá brevemente para que el dueño pueda rastrear progreso.

- **Imagen `logo-fenia.png.png` (doble extensión, sin uso)** — eliminada en commit `9843430` (Fase 0).
- **`LogoBanner` slim arriba (video chiquito)** — reemplazado por `HeroVideo` centrado en el body en commit `db163df` ([ADR-007 v2](docs/adr/ADR-007-intro-video-as-logo-banner.md)).
- **Sección Confianza con testimonios placeholder** — oculta de producción en commit `0d1a70c`. Reactivable con 1-line uncomment apenas haya 1 testimonio real recolectado por el flujo de [ADR-005](docs/adr/ADR-005-testimonial-collection.md).
- **Variable font + sitemap + robots.txt** — todo en commit `8b54e56` (Fase 2).
- **WCAG AA contrast pass body text** — commit `e65ca13` ([ADR-010](docs/adr/ADR-010-accessibility-pass.md)).
- **Pack visual v1 (animaciones + 3D)** — commit `7fb7d80` ([ADR-008](docs/adr/ADR-008-animations-and-3d-neural-network.md)).
- **Pack visual v2 (Bloom, magnetic CTAs, tilt cards, sticky label, slide transitions)** — commit `364249d` ([ADR-011](docs/adr/ADR-011-pack-v2-premium-polish.md)).
- **SEO baseline (JSON-LD, OG, canonical, sitemap, 404)** — commits `3eb645a` + `8b54e56` ([ADR-009](docs/adr/ADR-009-seo-structured-data.md)).
- **Hostinger investigation (original item #2)** — resolved 2026-05-26: Hostinger plan is prepaid 4 years (ARS 200K); will be used for the full stack (web + DNS + domain + email) after the owner's same-day pivot from Cloudflare Pages. See [ADR-022](docs/adr/ADR-022-pivot-to-hostinger-only.md). *(Exact plan tier still pending — new item #3 above.)*
- **Black-box Vercel config (original item #1)** — resolved 2026-05-26: project moves to Hostinger with hosting config versioned in `public/.htaccess` ([ADR-023](docs/adr/ADR-023-htaccess-hosting-config.md)). The interim plan to land at Cloudflare Pages ([ADR-018](docs/adr/ADR-018-migrate-to-cloudflare-pages.md), [ADR-019](docs/adr/ADR-019-version-hosting-config.md)) was superseded same day by the owner pivot.
- **FTPS credentials + setup** — resolved 2026-05-26: Fab provided FTPS account, 5 GitHub Secrets configured (`HOSTINGER_FTP_*`), deploy pipeline functional. Documented in [docs/audit/2026-05-26-cutover-status.md](docs/audit/2026-05-26-cutover-status.md).
- **DNS cutover** — resolved 2026-05-26: A `@`, AAAA `@`, CNAME `www` updated to point at Hostinger (`147.79.84.112`); email records (MX/SPF/DKIM/DMARC) and Google verification preserved. Independent verification confirms LiteSpeed serving with all security headers active.
