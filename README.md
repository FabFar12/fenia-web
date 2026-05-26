# fenia-web — Sitio público de FENIA

> [!IMPORTANT]
> **🤖 AI ASSISTANTS & MLLMs: START HERE**
> 1. You **MUST** read `AGENTS.md` before making any code changes. It contains the documentation routing matrix, branch protocol and dev-experience constraints.
> 2. Do **NOT** assume content (WhatsApp number, products, testimonials, hero stats) lives in components — it lives in `src/data/site.ts` and `src/content/`. See `docs/ai-context/content-model.md`.
> 3. For any visual change (color, layout, animation), produce a written/ASCII proposal first and wait for human approval. See `AGENTS.md` § PROPOSE-THEN-EXECUTE.

---

`fenia-web` es el sitio público de **FENIA — Formación Estratégica de Neuro-Inteligencia Aumentada**, una marca argentina que articula neurociencias, gestión emocional, pensamiento estratégico e inteligencia artificial aplicada para profesionales, emprendedores y empresas.

| Item | Detalle |
|------|---------|
| URL producción | https://fenia.com.ar/ |
| Hosting | **Hostinger** (web + DNS + dominio + email — un único proveedor). Deploy auto desde `main` vía GitHub Actions FTPS. Ver [ADR-022](./docs/adr/ADR-022-pivot-to-hostinger-only.md). *Migración en curso desde Vercel — ver [PENDING.md](./PENDING.md).* |
| Plan Hostinger | ARS 200.000 / 4 años (tier exacto pendiente de confirmar — [PENDING.md #2](./PENDING.md)) |
| Repositorio | https://github.com/FabFar12/fenia-web |
| Rama producción | `main` |
| Rama de trabajo | `dev` |
| Conversión actual | WhatsApp único (`+54 351 355-9947`) |
| Idioma | Español (es-AR) |

## Para el dueño no técnico

Si solo querés ver la página local mientras editás algo, abrí una terminal en la carpeta del proyecto y corré:

```sh
npm install   # una sola vez
npm run dev   # cada vez que quieras previsualizar — abre http://localhost:4321
```

Eso es todo. No necesitás ningún otro comando, terminal extra ni servicio.

### Cómo cambiar contenidos comunes sin tocar código

| Quiero cambiar… | Edito este archivo |
|-----------------|--------------------|
| Número de WhatsApp, redes sociales | `src/data/site.ts` |
| Estadísticas del Hero ("6 áreas", "12+ productos") | `src/data/site.ts` |
| Texto de la metodología (4 pilares) | `src/components/Metodologia.astro` *(pendiente de refactor a `site.ts`)* |
| Agregar / quitar un producto | crear o editar un `.md` en `src/content/products/` |
| Agregar un testimonio aprobado | crear un `.md` en `src/content/testimonials/` |

Más detalle en [`docs/ai-context/content-model.md`](./docs/ai-context/content-model.md).

## Stack

- **Framework**: Astro `^6.1.3` (static output)
- **UI islands**: React `^19.2.4` (solo `IntroLoader` y `Servicios`)
- **Estilos**: Tailwind `^4.2.2` (migración progresiva desde estilos inline — ver [`ADR-004`](./docs/adr/ADR-004-styling-strategy.md))
- **TypeScript**: strict
- **Node**: `>=22.12.0`
- **Tipografía**: Plus Jakarta Sans (vía `@fontsource`)

## Scripts disponibles

| Comando | Para qué |
|---------|----------|
| `npm run dev` | **Único comando que necesita el dueño.** Servidor de desarrollo en `http://localhost:4321`. |
| `npm run build` | Compila la versión de producción (lo corre Vercel automáticamente). |
| `npm run preview` | Previsualiza la build local antes de mergear. |
| `npm run check` | `astro check` — diagnóstico TypeScript + Astro. Opcional, recomendado antes de PR. |
| `npm run sync` | `astro sync` — regenera tipos de las content collections si TS se queja. Opcional. |

## Estructura del proyecto

```
fenia-web/
├── docs/
│   ├── adr/              # Decisiones arquitectónicas numeradas (ADR-001 …)
│   ├── ai-context/       # Contexto legible por IA: overview, content model, deployment, style guide
│   └── audit/            # Auditorías puntuales (estado, performance, accesibilidad)
├── public/               # Assets estáticos (favicon, imágenes, vídeo de intro)
├── src/
│   ├── components/       # Astro + React components
│   ├── content/          # Astro Content Collections (productos, testimonios)
│   ├── data/             # site.ts — fuente única de WhatsApp/redes/stats
│   ├── layouts/
│   ├── pages/            # Por ahora solo index.astro (one-pager)
│   └── styles/
├── AGENTS.md             # Reglas obligatorias para IAs y colaboradores
├── PENDING.md            # Tareas que dependen del dueño no técnico
└── README.md             # ← este archivo
```

## Documentación clave

- [`AGENTS.md`](./AGENTS.md) — Reglas obligatorias para cualquier colaborador o IA.
- [`PENDING.md`](./PENDING.md) — Tareas bloqueadas en el dueño (acceso Vercel, productos reales, etc.).
- [`docs/adr/`](./docs/adr/) — Por qué se tomó cada decisión técnica.
- [`docs/ai-context/`](./docs/ai-context/) — Modelos mentales del proyecto (lectura obligatoria antes de tocar código).
- [`docs/audit/`](./docs/audit/) — Auditorías históricas (estado inicial, performance, etc.).

## Despliegue

Cada `git push origin main` dispara [`.github/workflows/ci.yml`](./.github/workflows/ci.yml):

1. **Job `check-and-build`** — `astro check` + `npm run build` (corre también en PRs como quality gate).
2. **Job `deploy`** — solo en `main`. Sube `dist/` a Hostinger por **FTPS**. Requiere los secretos `HOSTINGER_FTP_*` configurados en *Repo → Settings → Secrets and variables → Actions*.

La configuración de hosting (headers de seguridad, cache, 404, HTTPS) vive versionada en [`public/.htaccess`](./public/.htaccess) — ver [ADR-023](./docs/adr/ADR-023-htaccess-hosting-config.md).

⚠️ **Sin preview deploys por PR** — Hostinger no los soporta nativamente. Para validar visualmente un cambio antes de mergear: `npm run build && npm run preview` localmente, o subir manualmente a un subdominio de prueba. Ver [AGENTS.md § PROPOSE-THEN-EXECUTE](./AGENTS.md).

📌 **Migración en curso (mayo 2026):** el sitio se está moviendo desde Vercel a Hostinger. Ver [ADR-022](./docs/adr/ADR-022-pivot-to-hostinger-only.md), [docs/audit/2026-05-26-hosting-pivot-to-hostinger.md](./docs/audit/2026-05-26-hosting-pivot-to-hostinger.md) y los items abiertos en [PENDING.md](./PENDING.md).

## Contribuir

1. Leé [`AGENTS.md`](./AGENTS.md) entero — no es opcional.
2. Trabajá siempre sobre `dev`. Nunca hagas push directo a `main`.
3. Para cambios visuales, propone primero (mockup ASCII o capturas) y esperá aprobación.
4. Para cambios de contenido (un texto, un producto), edita el archivo de datos correspondiente, no el componente.
5. Antes de PR, corré `npm run check` para asegurarte de no romper TypeScript.
