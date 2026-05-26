# PENDING — Tasks the AI cannot complete alone

> This file tracks **what the human owner / collaborators need to provide or decide** so the AI can continue. Keep it short and dated. When an item is resolved, delete it from here and write the resolution into the relevant ADR or context file.

> Last sync: 2026-05-26 (hosting **pivot to Hostinger** — see [docs/audit/2026-05-26-hosting-pivot-to-hostinger.md](./docs/audit/2026-05-26-hosting-pivot-to-hostinger.md). The earlier same-day Cloudflare Pages plan was reverted by the owner.).

---

## 🔴 Blockers (needed before specific work can proceed)

### 1. Hostinger FTPS credentials + plan tier confirmation — 2026-05-26 (pivot)
**Why it matters:** Per [ADR-022](./docs/adr/ADR-022-pivot-to-hostinger-only.md), production is moving to Hostinger and deploys happen via GitHub Actions FTPS. Until Fab provides the FTPS account details, the deploy job in [`.github/workflows/ci.yml`](./.github/workflows/ci.yml) fails at the pre-flight check.

**Owner actions — ask the friend who set up Hostinger:**
  1. Login to hPanel → Files → FTP Accounts. **Create a dedicated FTP account** (e.g. `deploy@fenia.com.ar`) scoped to `/public_html/`. Use a strong password ≥20 chars.
  2. Share with the dev (via a secure channel — *not* WhatsApp plain text, ideally a password manager link):
     - Hostname (`HOSTINGER_FTP_HOST`).
     - Port (`HOSTINGER_FTP_PORT` — `21` is the FTPS default on Hostinger).
     - Username (`HOSTINGER_FTP_USER`).
     - Password (`HOSTINGER_FTP_PASSWORD`).
     - Document root path (`HOSTINGER_FTP_REMOTE_DIR` — visible in File Manager; commonly `/public_html/`).
  3. Confirm the exact plan name (Premium Web Hosting / Business Web Hosting / Cloud Startup / etc.). This decides whether SSH/SFTP is an option later.
  4. Confirm the LiteSpeed (or Apache) flavor — visible in hPanel → Hosting → Manage → details.
**Dev action once received:** configure the five secrets at *Repo → Settings → Secrets and variables → Actions → New repository secret*. Then run the migration runbook in [docs/ai-context/deployment.md § Migration from Vercel — runbook](./docs/ai-context/deployment.md).

### 2. Confirm exactly what the 4-year Hostinger plan includes — carried over from 2026-05-26
**Why it matters:** Beyond the deploy credentials, knowing the *features* of the plan (SSH/SFTP availability, daily backups, multi-site, dedicated IP) shapes future decisions — e.g., whether a `staging.fenia.com.ar` preview subdomain is even possible, whether DB-backed features become feasible later, etc.

**Owner action — ask the friend who set it up:**
  - Plan name (Premium / Business / Cloud Startup / etc.).
  - End date (so we know when renewal questions arise).
  - Is the `@fenia.com.ar` email actually being used? List the mailbox(es).
  - Confirm the domain `fenia.com.ar` registration is on this plan (vs. paid separately).
  - Backup policy (daily? weekly? retention window?).
**Resolution path:** once confirmed, document in [docs/ai-context/deployment.md](./docs/ai-context/deployment.md) and delete this item.

### 3. Vercel project access for clean shutdown — carried over from 2026-05-19
**Why it matters:** After Hostinger cutover, we want to pause and then delete the Vercel project to avoid surprise charges (if Pro) or stale deploys. Owner access is needed.
**Owner action:** ask the friend to invite `matiasslpknt08@gmail.com` as a collaborator on the Vercel project, OR send screenshots of: Build Settings, Environment Variables, Domains, Functions Region, current plan tier. Then dev confirms whether a cancel-plan step is required.

### 4. Catálogo real de productos — carried over from 2026-05-19
**Why it matters:** Los 3 productos que aparecen hoy en producción (Guía de Neuro-Liderazgo, Toolkit de IA para Emprendedores, Método de Bienestar Organizacional) **fueron inventados por una IA**. No existen. Mostrarlos como reales es riesgo reputacional.
**Owner action:** Tu amigo tiene que decidir:
  - ¿Cuántos productos digitales reales planea tener en los próximos 3 meses?
  - Para cada uno: título, formato (PDF / curso video / Notion / etc.), precio (o "consultar"), público objetivo, status (en desarrollo / próximamente / disponible).
  - Mientras tanto, los 3 productos actuales quedan marcados con badge **"Próximamente"** (decisión confirmada 2026-05-19).
**Cómo se agregan:** Crear un archivo nuevo en `src/content/products/{slug}.md` con el frontmatter documentado en [docs/ai-context/content-model.md](./docs/ai-context/content-model.md).

### 5. Testimonios reales — carried over from 2026-05-19
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

### 6. KPIs reales del Hero y de la sección Confianza — carried over from 2026-05-19
**Why it matters:** "6 áreas de expertise", "12+ productos digitales", "100% enfoque aplicado", "95% satisfacción en formaciones", "100% enfoque basado en evidencia" son cifras sin fuente documentable.
**Owner action:** Definir con tu amigo:
  - ¿Cuántas áreas reales? (probablemente 4: consultoría, capacitación, IA aplicada, productos digitales).
  - ¿Cuántos productos digitales habrá? (ver item #4).
  - ¿Hay un dato de satisfacción real medido (ej. encuesta de fin de formación)? Si no, sacarlo.
  - ¿Cuántas audiencias atendemos? (3: profesionales, emprendedores, empresas — esto sí es real).
**Mientras tanto:** Las cifras quedan documentadas como placeholder en `src/data/site.ts` con comentarios `// TODO: validar con dueño` para que sea trivial cambiarlas.

---

## 🟡 Decisiones de producto pendientes

### 7. ¿Sección Productos debe vender o solo informar? — carried over from 2026-05-19
Hoy los botones dicen "Comprar ahora →" pero todos linkan a `#contacto`. Hay dos caminos:
  - **A.** No habrá e-commerce nunca: rebrandear "Productos" como "Recursos" y cambiar CTAs a "Consultar" / "Solicitar acceso".
  - **B.** Habrá e-commerce: integrar Mercado Pago / Gumroad / Stripe (ver Fase 5 del Plan Maestro).

### 8. ¿Quién aparece en la sección "Quién está detrás"? — carried over from 2026-05-19
Hoy no existe. Una consultoría humana sin caras pierde credibilidad. ¿Tu amigo trabaja solo? ¿Tiene socios? ¿Quiere mostrar bio + foto + LinkedIn?

### 9. Política de privacidad y términos legales — carried over from 2026-05-19
Los links del footer (`href="#"`) están muertos. Para cumplir Ley 25.326 (Argentina) — si en algún momento se captura un email/teléfono en un formulario propio — hace falta:
  - Política de privacidad real (puede generarse con templates legales argentinos).
  - Términos de servicio si vendemos productos digitales.
**Owner action:** Hablar con un abogado o usar un template estándar argentino.

---

## ⚪ Activos de marca pendientes

### 10. Favicon propio — carried over from 2026-05-19
Hoy se usa el de Astro (`public/favicon.svg`). Tu amigo tiene un logo F→FENIA (visible en Nav.astro), pero falta un favicon optimizado (16/32/180/192/512 px).
**Owner action:** Exportar el logo F + nodos como favicon set, o pedir al diseñador.

### 11. Open Graph image — carried over from 2026-05-19
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
- **Hostinger investigation (original item #2)** — resolved 2026-05-26: Hostinger plan is prepaid 4 years (ARS 200K); will be used for the full stack (web + DNS + domain + email) after the owner's same-day pivot from Cloudflare Pages. See [ADR-022](docs/adr/ADR-022-pivot-to-hostinger-only.md). *(Exact plan tier still pending — new item #2 above.)*
- **Black-box Vercel config (original item #1)** — resolved 2026-05-26: project moves to Hostinger with hosting config versioned in `public/.htaccess` ([ADR-023](docs/adr/ADR-023-htaccess-hosting-config.md)). The interim plan to land at Cloudflare Pages ([ADR-018](docs/adr/ADR-018-migrate-to-cloudflare-pages.md), [ADR-019](docs/adr/ADR-019-version-hosting-config.md)) was superseded same day by the owner pivot.
