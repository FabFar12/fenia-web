# PENDING — Tasks the AI cannot complete alone

> This file tracks **what the human owner / collaborators need to provide or decide** so the AI can continue. Keep it short and dated. When an item is resolved, delete it from here and write the resolution into the relevant ADR or context file.

> Last sync: 2026-05-19 (after Pack v2 commit).

---

## 🔴 Blockers (needed before specific work can proceed)

### 1. Vercel project access — 2026-05-19
**Why it matters:** We need to versionar el `vercel.json` y la config de build/env vars sin sobreescribir lo que ya está corriendo en producción. Sin acceso, vamos a ciegas.
**Owner action:** Pedirle a tu amigo:
  - Invitarte como colaborador al proyecto Vercel (email: `matiasslpknt08@gmail.com`).
  - O mandarte capturas de: Build Settings, Environment Variables, Domain settings, Functions region.
**Mientras tanto:** No tocamos config de hosting. El repo deploya como esté configurado en la UI de Vercel.

### 2. Hostinger — ¿qué hay realmente ahí? — 2026-05-19
**Why it matters:** Tu amigo mencionó que la página "también está montada en Hostinger". Verificamos en `https://fenia.com.ar/` que **Vercel** sirve el contenido (`Server: Vercel`, `X-Vercel-Id: gru1::...`). Las hipótesis son:
  - (a) Hostinger gestiona solo el dominio (DNS) que apunta a Vercel → escenario más probable.
  - (b) Hay un sitio viejo en Hostinger ya desuso → habría que apagarlo para no tener costes/confusiones.
  - (c) Hay otro deploy paralelo que sirve algo distinto en otro subdominio → habría que conocerlo.
**Owner action:** Preguntar a tu amigo:
  - ¿Para qué se usa Hostinger? (¿solo dominio? ¿hay hosting activo? ¿correo?)
  - Si paga por hosting Hostinger sin usarlo → cancelarlo.
  - Si hay un sitio viejo allí → URL exacta para auditarlo.

### 3. Catálogo real de productos — 2026-05-19
**Why it matters:** Los 3 productos que aparecen hoy en producción (Guía de Neuro-Liderazgo, Toolkit de IA para Emprendedores, Método de Bienestar Organizacional) **fueron inventados por una IA**. No existen. Mostrarlos como reales es riesgo reputacional.
**Owner action:** Tu amigo tiene que decidir:
  - ¿Cuántos productos digitales reales planea tener en los próximos 3 meses?
  - Para cada uno: título, formato (PDF / curso video / Notion / etc.), precio (o "consultar"), público objetivo, status (en desarrollo / próximamente / disponible).
  - Mientras tanto, los 3 productos actuales quedan marcados con badge **"Próximamente"** (decisión confirmada 2026-05-19).
**Cómo se agregan:** Crear un archivo nuevo en `src/content/products/{slug}.md` con el frontmatter documentado en [docs/ai-context/content-model.md](./docs/ai-context/content-model.md).

### 4. Testimonios reales — 2026-05-19
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
  3. Configurar en Vercel la variable de entorno **`PUBLIC_TALLY_TESTIMONIAL_FORM_URL`** con ese link. Cuando exista, la página `/dejanos-tu-testimonio` automáticamente embebe el formulario.
  4. Empezar a pedir testimonios por WhatsApp a clientes reales tras cada engagement.
  5. Cuando llegue un testimonio aprobado y con consentimiento explícito → el dev lo agrega como `.md` en `src/content/testimonials/`. Una vez haya al menos 1, restaurar el render de `<Confianza />` en `src/pages/index.astro` (línea comentada).

### 5. KPIs reales del Hero y de la sección Confianza — 2026-05-19
**Why it matters:** "6 áreas de expertise", "12+ productos digitales", "100% enfoque aplicado", "95% satisfacción en formaciones", "100% enfoque basado en evidencia" son cifras sin fuente documentable.
**Owner action:** Definir con tu amigo:
  - ¿Cuántas áreas reales? (probablemente 4: consultoría, capacitación, IA aplicada, productos digitales).
  - ¿Cuántos productos digitales habrá? (ver item #3).
  - ¿Hay un dato de satisfacción real medido (ej. encuesta de fin de formación)? Si no, sacarlo.
  - ¿Cuántas audiencias atendemos? (3: profesionales, emprendedores, empresas — esto sí es real).
**Mientras tanto:** Las cifras quedan documentadas como placeholder en `src/data/site.ts` con comentarios `// TODO: validar con dueño` para que sea trivial cambiarlas.

---

## 🟡 Decisiones de producto pendientes

### 6. ¿Sección Productos debe vender o solo informar? — 2026-05-19
Hoy los botones dicen "Comprar ahora →" pero todos linkan a `#contacto`. Hay dos caminos:
  - **A.** No habrá e-commerce nunca: rebrandear "Productos" como "Recursos" y cambiar CTAs a "Consultar" / "Solicitar acceso".
  - **B.** Habrá e-commerce: integrar Mercado Pago / Gumroad / Stripe (ver Fase 5 del Plan Maestro).

### 7. ¿Quién aparece en la sección "Quién está detrás"? — 2026-05-19
Hoy no existe. Una consultoría humana sin caras pierde credibilidad. ¿Tu amigo trabaja solo? ¿Tiene socios? ¿Quiere mostrar bio + foto + LinkedIn?

### 8. Política de privacidad y términos legales — 2026-05-19
Los links del footer (`href="#"`) están muertos. Para cumplir Ley 25.326 (Argentina) — si en algún momento se captura un email/teléfono en un formulario propio — hace falta:
  - Política de privacidad real (puede generarse con templates legales argentinos).
  - Términos de servicio si vendemos productos digitales.
**Owner action:** Hablar con un abogado o usar un template estándar argentino.

---

## ⚪ Activos de marca pendientes

### 9. Favicon propio — 2026-05-19
Hoy se usa el de Astro (`public/favicon.svg`). Tu amigo tiene un logo F→FENIA (visible en Nav.astro), pero falta un favicon optimizado (16/32/180/192/512 px).
**Owner action:** Exportar el logo F + nodos como favicon set, o pedir al diseñador.

### 10. Open Graph image — 2026-05-19
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
