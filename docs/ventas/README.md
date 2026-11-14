# Venta de kits — plan vivo y estado

> **Leé esto primero.** Es el punto de entrada para cualquier persona o sesión de IA que trabaje en la venta de kits.
> Arquitectura: [ADR-028](../adr/ADR-028-own-sales-circuit-on-hostinger.md) · Riesgos (anti-estafa y bugs): [threat-model.md](./threat-model.md) · Pruebas: [test-plan.md](./test-plan.md) · Tareas humanas de la Fase 1: [guia-fase-1.md](./guia-fase-1.md)
> Última actualización: 2026-11-14 ("Tu kit" en staging, D26).

## Objetivo

Que una persona entre a fenia.com.ar, elija uno de los 3 kits, pague con Mercado Pago y **reciba el kit siempre**, sin intervención manual, y que **nadie pueda descargarlo sin un pago verificado**.

## Cómo trabajamos (para no perder el hilo)

1. Cada sesión empieza leyendo este archivo. La tabla de estado dice dónde estamos y cuál es el próximo paso.
2. **Un paso por vez.** Al cerrar un paso se actualiza este archivo (estado + bitácora) y se hace commit en `dev` (con el OK del dev).
3. **Ningún paso de código está terminado sin sus pruebas en verde.** Cada riesgo de [threat-model.md](./threat-model.md) tiene al menos una prueba en [test-plan.md](./test-plan.md). Riesgo nuevo → fila nueva en los dos.
4. **Cambios visuales:** primero propuesta y aprobación (regla de [AGENTS.md](../../AGENTS.md)).
5. **Nada llega a `main`** sin PR, CI verde, compuerta de lanzamiento completa ([test-plan.md](./test-plan.md#compuerta-de-lanzamiento)) y aprobación explícita.
6. **Falla cerrada:** si falta la configuración del servidor, todo endpoint responde "no disponible" y nunca entrega nada. El código del backend puede llegar a producción antes del lanzamiento sin riesgo.

## Estado

Leyenda: ✅ hecho · 🟡 en curso / esperando a alguien · ⏳ pendiente · ⏸️ postergado a propósito · ⛔ bloqueado

| Fase | Paso | Estado | Quién |
|---|---|---|---|
| **0. Diseño** | 0.1 Este tracker + entrada en AGENTS.md | ✅ | dev |
| | 0.2 Investigación anti-estafa y bugs → [threat-model.md](./threat-model.md) | ✅ | dev |
| | 0.3 Plan de pruebas → [test-plan.md](./test-plan.md) | ✅ | dev |
| | 0.4 [ADR-028](../adr/ADR-028-own-sales-circuit-on-hostinger.md) (arquitectura) | ✅ Aceptado | dev |
| | 0.5 Marcar lo obsoleto: ADR-025, guía de MP para Fab, PENDING #10 | ✅ | dev |
| **1. Prerrequisitos** — [guia-fase-1.md](./guia-fase-1.md) (los pasos internos de cada bloque se numeran en la guía) | Bloque 1 · Hostinger por API (D11). Plan Business, PHP 8.3 con las extensiones necesarias (incluida `nd_pdo_mysql`). Creados: la base de staging, el subdominio `staging.fenia.com.ar` con SSL activo y el cron disponible por API. La carpeta privada la crea PHP (Q5). Los datos de acceso están en `~/fenia-secrets`, **nunca en el repo** | ✅ 2026-11-06 | dev |
| | Bloque 2 · Mercado Pago: aplicación "FENIA kits" creada (Checkout Pro vía **Orders**, D14). Producción activada. **Token de prueba validado** contra `/users/me` (usuario `test_user`, MLA) y guardado en `~/fenia-secrets`. Pendientes para más adelante: webhooks (cuando exista el endpoint), comprador de prueba (Fase 5), token de producción (lanzamiento) y decidir qué hacer con los Links de Pago viejos | ✅ 2026-11-06 (lo de ahora) | dev |
| | Bloque 3 · Mail: `kits@` creada por API (D10). `soporte@` creado como **alias de `contacto@`** (OK explícito del dev, 2026-11-06): lo que llega a soporte cae en la bandeja de contacto. Falta el mail de prueba | 🟡 Falta mail de prueba (paso 3.3) | dev |
| | Bloque 4 · ZIP de cada kit en `C:\Fenia\entregables` (2026-11-06) + precio AR$ 13.000 (D15). Los tres revisados y OK (D16):
  - **Evaluar ✅**.
  - **Aprender ✅**: el dev sacó el `.md` interno (ZIP del 23:26).
  - **Enseñar ✅**: formato distinto pero completo. 8 fichas editables interactivas (HTML: completar / copiar para la IA / PDF) y 8 imprimibles, verificadas en Edge con y sin internet; la página ya lo describe así.

  Al subirlos se renombran con el id del kit, y en Enseñar se corrige la carpeta interna "…mejorado para final" (en una copia; el original no se toca) | ✅ (se suben con el circuito) | dev |
| | Bloque 5 · Contador · abogado · garantía de 7 días sí/no | 🟡 Guía entregada | Fab |
| **2. Entorno de pruebas** | 2.1 Deploy automático de `dev` a `staging.fenia.com.ar` (job `deploy-staging`, corrida 42317545237). Primera publicación resuelta sembrando el archivo de estado (aprobado por el dev). Staging solo responde en su host (C20 cerrado), es `noindex`, no tiene CDN y bloquea archivos ocultos, `_lib/` y `default.php` | ✅ 2026-11-06 | dev |
| | 2.2 CI: barrera anti-ZIP/credenciales, lint PHP + PHPUnit (8.1 y 8.3), verificación del catálogo (U-31); el deploy depende de todo eso | ✅ Verde en GitHub (2026-11-06) | dev |
| | 2.3 Suite de seguridad `npm run test:ventas`. Contra staging: **42 OK, 0 fallas**, 22 pendientes hasta que existan los endpoints. Contra producción: 41 OK y 1 falla conocida (H-01) | ✅ | dev |
| **3. Backend** | 3.1a Núcleo puro con tests: firma, **reglas de orden (`OrderRules`, API de Orders, D14)**, tokens, validación, montos, catálogo generado por el build. Bug corregido: `$` en las regex aceptaba un `\n` final (modificador `D`) | ✅ | dev |
| | 3.1b `Env` (entorno y carpeta privada, nunca dentro de `public_html`), `Config` (falla cerrada, rechaza la config de otro entorno), `Inbox` (Q5), `Db` (migración idempotente MySQL/SQLite), `OrderStore` (compare-and-set), `TokenStore` (consumo atómico), `RateLimiter` (claves HMAC, nunca IPs en claro). **114 tests**, con mutación verificada | ✅ 2026-11-06 | dev |
| | 3.2b Staging configurado (2026-11-07). Config y ZIPs de prueba (D21) subidos por el buzón con la API de archivos de Hostinger (TUS); PHP los movió a la carpeta privada y el buzón quedó vacío. `health`: `{"ok":true,"sales":true,"kits":true}`, con base MySQL migrada. **Checkout real contra el sandbox de MP OK**: $13.000 aunque el pedido decía `price: 1` (S-12), sin efectivo (D4), regreso a `/gracias`, "FENIA" en el resumen. Falta la clave del webhook | 🟡 Dev: webhook en MP | dev |
| | 3.2 `Shop` (todo el flujo), `Api` (arranque que falla cerrado, logs en la carpeta privada), cliente de MP (Orders), endpoints `checkout` · `webhook` · `status` · `download` · `resend` · `health` y `.htaccess` de `/api` (lista blanca + `no-store`). **139 tests** (flujo completo con MP y mail falsos) y mutación verificada. Suite de seguridad: las pendientes pasaron a tests reales | ✅ Código (2026-11-07). 🟡 Falta la config de staging y el webhook en MP | dev |
| | 3.3 Mail de entrega (SMTP de Hostinger, PHPMailer 7.1.1). Texto aprobado por el dev. Enviado desde el PHP de staging en las compras de prueba: llegó a la bandeja de entrada en ~1 s, con DKIM, SPF y DMARC en pass | ✅ 2026-11-07 | dev |
| | 3.4 Tarea horaria (`Cron` + `hourly.php`): conciliación, limpieza de límites, avisos por mail con explicación y resumen diario después de las 9. **Creada en staging** por API (a los :07 de cada hora; los avisos van a `kits@`). En producción se crea en el lanzamiento, y falta decidir quién recibe los avisos | ✅ staging | dev |
| | 3.5 `.htaccess` de `/api` (lista blanca + `no-store`). Archivos ocultos negados (H-01) y `www` → `fenia.com.ar` (H-08), verificados en producción. HTML con `no-cache` (siempre fresco, ADR-023) | ✅ 2026-11-07 | dev |
| **4. Frontend** | 4.1 Propuesta visual: card, página del kit, `/gracias`, "No me llegó", descarga → [propuesta-visual.md](./propuesta-visual.md) | ✅ Aprobada por el dev (2026-11-06) | dev |
| | 4.2a Implementada en staging (`PUBLIC_SALES_PREVIEW`: ahí los kits están "en venta"; producción no cambia). Revisada con capturas de escritorio y celular. Además: H-03 cerrado y bug del layout corregido (no tenía slot `head`, así que el `noindex` de `/gracias` nunca se publicaba) | ✅ | dev |
| | 4.2 Implementación + capturas antes/después + migración del schema + H-03 | ⏳ | dev |
| **5. Pruebas en staging** | 5.1 **Circuito completo en el sandbox de MP OK** (2026-11-07). Probado:
  - compra con tarjeta de prueba;
  - el aviso firmado de MP entrega solo, en ~1 s;
  - mail en la bandeja de entrada con DKIM/SPF/DMARC en pass;
  - `/gracias` con link;
  - descarga en Edge y Firefox, con el archivo idéntico al subido;
  - límite de 5 y aviso de la última;
  - "No me llegó" renueva el link agotado y el viejo queda muerto;
  - reembolso → link anulado en <5 s.

  Dos bugs encontrados y corregidos: H-09 y H-10. Suite de seguridad: 68 OK, 0 fallas | ✅ staging | dev |
| **6. Lanzamiento** | 6.1 Docs: [guía de ventas para Fabián](./guia-fab-ventas.md) (resumen diario, avisos, P-01, P-02, reembolsos, contracargos, promo), `deployment.md` (operación de la API), ADR-023 y ADR-027 actualizados. Falta ensayar P-01/P-02 con Fab | ✅ docs · ⏳ ensayo | dev + Fab |
| | 6.2 PR #18 `dev` → `main` (D13), 2026-11-07. **Infraestructura en producción, sin ventas habilitadas**: los kits siguen "Próximamente" y la API responde 503 hasta tener config. Capturas antes/después: la home queda idéntica; `/gracias` y `/recursos-gratuitos`, como se aprobaron. CDN purgada (D18). Verificado en vivo: `www` → 301 (H-08 ✅), archivo de estado → 403 (H-01 ✅). Suite ✱ contra producción: 54 OK, 0 fallas | ✅ infraestructura | dev |
| | 6.3 Compra real + reembolso en producción. Orden real de producción ya inspeccionada sin pagar ($13.000, cuenta real, URLs y medios de pago correctos) | ⏳ Compra del dev; el reembolso lo hace la IA | dev |
| | 6.4 **Kits a la venta desde el 2026-11-07** con la promo de lanzamiento (D23) y la franja de la home. Faltan: 72 h de monitoreo (tarea horaria + resumen diario a soporte@), anuncio a la lista y DMARC (H-02) | 🟡 en venta | Fab + dev |

**Próximo paso (pendientes al cierre del 2026-11-07):**

Del dev o de Fab:
1. **Compra real** de un kit en fenia.com.ar. La IA la reembolsa y verifica que se anule el link (6.3).
2. **Desactivar los 3 Links de Pago viejos** de Mercado Pago (D6).
3. **Ensayar P-01 y P-02** con Fab, usando la [guía](./guia-fab-ventas.md).
4. **Legales y facturación** (Bloque 5): términos definitivos con el abogado y cómo se factura cada venta.
5. **Anuncio** (el lunes 9/11) y, después, DMARC a `p=quarantine` (H-02).

De la IA:
6. **Después del 16/11 23:59:** el sitio y Mercado Pago pasan solos a $30.000. Hay que hacer un deploy para que los datos de Google (`priceValidUntil`) muestren el precio nuevo, y decidir con el dev si se borra el bloque `promo`.
7. **Al terminar:** revocar el token de la API de Hostinger, que vence en 3 meses (D11). Antes, decidir cómo se purga la CDN sin él (D18).
8. **"Tu kit" (D25, D26):** construida y probada; staging con material de prueba. Los tres kits quedaron estandarizados y verificados (Evaluar y Aprender con Claude Design). Sigue: el dev la mira en staging y da el OK para el mail nuevo, la subida a producción de los tres kits y la corrección de H-11 en el ZIP de Enseñar.
9. **Después del entorno:** sección interna de métricas del sitio y de ventas, solo para el dev y Fab (pedido del dev, 2026-11-12). Propuesta lista para aprobar: [propuesta-metricas.md](./propuesta-metricas.md), con 3 decisiones del dev.

## Cómo se corren las pruebas

| Qué | Comando | Dónde |
|---|---|---|
| Unitarias PHP (U) | `vendor/bin/phpunit`, después de `composer install` | CI, en cada push. En local hace falta PHP ≥ 8.1, que no es obligatorio |
| Catálogo = `.md` (U-31) | `npm run build` y después `node scripts/verify-catalog.mjs` | CI, en cada push |
| Suite de seguridad (S) | PowerShell: `$env:BASE_URL="https://fenia.com.ar"; npm run test:ventas` | Contra producción, solo las pruebas ✱. Con `MODE=staging` contra staging, todas |
| Navegador real (E2E, Playwright) | `npm run e2e` | Contra staging, en escritorio (Edge), celular y Firefox. Cubre el sitio, la promo con reloj simulado (corriendo, último día, vencida), "Tu kit" con datos simulados, capturas de referencia y accesibilidad (axe). 69 pruebas, unos 2 minutos (66 en staging; 3 son solo de producción) |
| E2E en producción | `npm run e2e:prod` | Lo mismo pero sin comprar nada y sin capturas de referencia. 54 pruebas |
| Compra completa en el sandbox | `npm run e2e:compra` | Staging: compra con tarjeta de prueba, `/gracias`, "Tu kit" (guía, fichas, descargas), reembolso y kit cerrado. Necesita `~/fenia-secrets/mp-test-token.txt`. Hacerla **antes** que la suite de seguridad, que gasta el cupo de descargas de la IP. Con `E2E_KEEP=1` no reembolsa e imprime el link, para que una persona pruebe staging |
| Capturas de referencia | `npm run e2e:update` | Solo después de un cambio visual aprobado. Las capturas nuevas quedan en `tests/e2e/fotos/` y hay que hacerles commit |

`npm run dev` no cambia: ninguna de estas pruebas se encadena ahí ([ADR-006](../adr/ADR-006-dx-single-command-dev.md)).

## Decisiones

| # | Fecha | Decisión | Estado |
|---|---|---|---|
| D1 | 2026-11-06 | Circuito propio en Hostinger (opción B), descartando plataformas intermediarias y entrega manual | ✅ Confirmada por el dev |
| D2 | 2026-11-06 | Lanzar directo con entrega automática, sin puente de Link de Pago + envío manual | ✅ Confirmada por el dev |
| D3 | 2026-11-06 | Link de descarga: vence a los 30 días, máximo 5 descargas, reenviable desde "No me llegó" | ✅ Confirmada por el dev |
| D4 | 2026-11-06 | Solo pagos que se aprueban al instante (`binary_mode`): sin efectivo (Rapipago / Pago Fácil) ni pagos "en revisión" | ✅ Confirmada por el dev |
| D5 | 2026-11-06 | Decisiones técnicas de ADR-028 | ✅ ADR aceptado |
| D6 | 2026-11-06 | Los Links de Pago que ya existen en Mercado Pago no se usan en el circuito. Se desactivan, salvo que se estén usando para ventas manuales. Verificado: hay 3 activos ("Evaluar / Aprender / Educar con IA - Ebook"). Sus URLs **no** se escriben en el repo | ✅ (acción de Fab en la guía, 2.7) |
| D7 | 2026-11-06 | El webhook se configura en el panel de MP por entorno (URL de prueba → staging, URL productiva → producción), así los avisos llegan firmados. `notification_url` por preferencia solo si INT-08 prueba que también llega firmado | ✅ Técnica |
| D8 | 2026-11-06 | Versión mínima de PHP: 8.1 (CI prueba 8.1 y 8.3). Se revisa al confirmar Q1 | ✅ Técnica |
| D26 | 2026-11-14 | **"Tu kit" (fase 1 de D25), construida y probada para staging.**
  - `/descarga` pasa a ser "Tu kit" ([propuesta-visual.md §6](./propuesta-visual.md)): la guía para leer o bajar, las fichas y los imprimibles montados, y el ZIP. Leer y usar no gasta nada. El tope es de **10 descargas de archivos**, también para los links ya enviados (la base migra sola al esquema 2).
  - El token no viaja en ninguna URL: el dispositivo recibe un **pase de lectura** (cookie `HttpOnly` firmada) y las páginas del kit se sirven con él (A18). Esas páginas tienen una CSP propia en `api/.htaccess`, que no deja salir nada a internet (A19).
  - **Kits estandarizados:** Evaluar y Aprender, con guía (prompts integrados), fichas, imprimibles y Word, verificados palabra por palabra contra los originales. Los paquetes listos para subir están en `C:\Fenia\entregables\tu-kit\` (fuera del repo).
  - **Falta el OK del dev para:** (1) el texto nuevo del mail para kits con fichas; (2) subir a producción los kits nuevos y las páginas; (3) usar en el ZIP de Enseñar la corrección de H-11 | 🟡 Staging · producción con OK del dev |
| D25 | 2026-11-12 | **Entorno FENIA ("Tu kit" / "Mis kits")**, acordado con el dev tras la idea de Fab. En vez de bajar un archivo, la compradora llega a una página con su kit montado. En fases:
  - **Fase 1, "Tu kit"**: la página de descarga pasa a mostrar la guía (leer o bajar), las fichas interactivas montadas, los imprimibles y el ZIP. Ver no gasta descargas. Arranca con Enseñar, el único con fichas HTML. Los links ya mandados siguen funcionando.
  - **Fase 2, "Mis kits"**: entrar con el email, sin contraseña (link mágico). Todas las compras de ese email, **de por vida**; se limitan solo las descargas de archivos (reemplaza D3). La identidad y una marca al pie de los PDF reemplazan al contador como freno a compartir.
  - **Fase 3, entorno**: las fichas guardan las respuestas en la cuenta (hoy ya las guardan en el navegador). Implica datos personales: política de privacidad con el abogado antes.

  Prerrequisito de las fases 1-3 para Evaluar y Aprender: estandarizarlos al formato de Enseñar con Claude Design (carpeta `C:\Fenia\entregables
eformateo`, un prompt por kit). Restricción conocida: el mail de Hostinger permite 100 envíos por día por casilla; los links mágicos salen de ahí | ✅ Decisión del dev |
| D24 | 2026-11-09 | **Descarga más simple en el celular (A+B), en producción** (decisión del dev).
  - La página de descarga ofrece la **guía en PDF suelta** ("se abre directo, también en el celular") como botón principal y el ZIP completo como secundario. Cada descarga cuenta una de las 5.
  - Hay una ayuda "¿Cómo abro el ZIP en el celular?" y el mail lo explica.
  - Las guías son las de cada ZIP (la de Enseñar, la corregida).

  **Para la semana:**
  - estandarizar Evaluar y Aprender al formato de Enseñar con Claude Design: guía con los prompts como capítulo final, fichas HTML y los Word de plantillas en una carpeta aparte. La guía de cada sesión está en `C:\Fenia\entregables
eformateo`;
  - evaluar la página "Tu kit" con los datos de descargas desde el celular | ✅ Decisión del dev |
| D23 | 2026-11-07 | **Actualización:** el dev pasó el fin al **lunes 16/11 a las 23:59**, así el lunes 9 "quedan 7 días". Es legítimo porque se cambió antes de anunciar y sin ventas. Diseño final, **aprobado por el dev** el 2026-11-07:
  - un cartel arriba de los 3 kits: "Oferta de 10 días: del sábado 7/11 al lunes 16/11" (días de calendario en hora argentina, así total, transcurrido y restante cierran), barra de avance "Día N de 10" y "Quedan 9 días y 10 h 05 min 30 s";
  - el último día dice "¡Último día! Quedan …";
  - en las tarjetas, "Ahorrás $17.000" y el botón "Comprar con 56% OFF";
  - sin "Promo de N días", que se leía como contradicción, ni "Después, $30.000";
  - SEO: Schema.org Product con `priceValidUntil`.

  Después del 16/11 hay que hacer un deploy, para que los datos de Google dejen de mostrar $13.000. **Original:** promo de lanzamiento (decisión del dev):
  - precio normal **$30.000**;
  - **$13.000** desde el lanzamiento hasta el **sábado 14/11 a las 23:59 (hora argentina)**, 7 días;
  - en las tarjetas y la página del kit: etiqueta "−56% · Lanzamiento" (56,7% redondeado para abajo), precio tachado y cuenta regresiva.

  Todo sale del campo `promo` de cada `.md`. El sitio y Mercado Pago cambian solos al vencer, sin deploy. La cuenta regresiva es igual para todos y nunca se reinicia. El plazo es real: no se extiende en silencio (ley de defensa del consumidor). Se descartó vender un día a $30.000 antes del descuento | ✅ Decisión del dev |
| D22 | 2026-11-08 | **En producción, los avisos y el resumen diario de la tarea horaria van a `soporte@`** (decisión del dev). En staging van a `kits@`, para no llenar la bandeja de Fab con pruebas | ✅ Decisión del dev |
| D21 | 2026-11-07 | **Staging entrega ZIPs de prueba, nunca los kits reales** (A17): su sandbox se paga con tarjetas de prueba públicas. Los kits reales (renombrados con el id; Enseñar con la carpeta interna corregida) se suben solo a producción en el lanzamiento | ✅ Técnica |
| D20 | 2026-11-07 | **`/gracias` muestra el link de descarga solo durante 24 h** después del pago. Después, el link está únicamente en el mail (y en "No me llegó"). Así, si alguien encuentra el código de orden en un historial, no le sirve | ✅ Técnica |
| D19 | 2026-11-07 | **"No me llegó" con un link vencido o agotado manda uno nuevo** (otros 30 días / 5 descargas), como dice la página de descarga. Máximo 2 renovaciones por compra; después lo resuelve soporte. Si el link todavía funciona, se reenvía el mismo, sin renovar. Solo al email de la compra (A15) y con límite de 3 pedidos por día | ✅ Técnica (dentro de D3) |
| D18 | 2026-11-06 | **Después de cada deploy a producción se purga la CDN de Hostinger** (`DELETE …/cdn/purge`, lo hace la IA al mergear). Sin eso, algunos visitantes ven la versión vieja durante días (H-06, comprobado: un pedido viejo y otro nuevo en la misma página). Antes del lanzamiento, decidir si se automatiza | ✅ |
| D17 | 2026-11-06 | Hasta el lanzamiento, los kits "Próximamente" muestran **"Avisame cuando esté disponible"**. `/recursos-gratuitos` ya no promete un recurso gratis que no existe. Aprobado por el dev; en producción con PR #17 | ✅ |
| D16 | 2026-11-06 | Contenido de los kits (decisiones del dev):
  - "Markdown fuente" sale de las tres descripciones.
  - El kit 1 se llama **"Evaluar en tiempos de IA"**.
  - Enseñar se describe con su formato real (fichas interactivas + imprimibles).
  - Las notas internas al comienzo de cada `.md` no se publican cuando exista la página del kit (paso 4.2) | ✅ |
| D15 | 2026-11-06 | **Precio de cada kit: AR$ 13.000** (antes 8.000), cargado en los `.md`. El catálogo y lo cobrado salen de ahí (U-31). Mientras los kits estén en "Próximamente", no se ve en el sitio | ✅ Decisión del dev |
| D14 | 2026-11-06 | **Corregida el mismo día:** la aplicación de MP es **Checkout Pro vía Orders API**. Al crearla, MP avisa que la API de Preferences "será descontinuada pronto", y su documentación indica Orders para integraciones nuevas. El flujo es: `POST /v1/orders` → `checkout_url`; `external_reference` obligatorio; URLs de regreso en `config.online`; aviso de tipo `order` con `x-signature`; `GET /v1/orders/{id}` como fuente de verdad. Lo ya construido sirve (firma, tokens, validación, montos, catálogo). En 3.1b se adaptan `PaymentRules` y `Validate` (los ids de orden son alfanuméricos) y se revisan ADR-028 y el threat model | ✅ Técnica |
| D13 | 2026-11-06 | El dev autorizó a la IA a mergear `dev` → `main` cuando lo considere listo. Siempre por PR, con CI en verde y verificación de producción antes y después. **No reemplaza** la compuerta de lanzamiento ([test-plan.md](./test-plan.md#compuerta-de-lanzamiento)) ni la aprobación previa de cambios visuales (AGENTS.md) | ✅ |
| D9 | 2026-11-06 | Los ZIP finales llegan **al final**, después de la revisión final del material, antes de la Fase 5. Staging se prueba con ZIPs de prueba. En esa revisión se confirman el nombre (el link viejo dice "Educar con IA", el sitio "Enseñar con IA…") y el precio de cada kit | ✅ Confirmada por el dev |
| D10 | 2026-11-06 | Los compradores ven y responden a `soporte@fenia.com.ar`. Los mails salen desde `kits@` (Reply-To a `soporte@`), así la contraseña de soporte nunca vive en el servidor. `kits@` la crea el dev por la API de mail. Si el plan no tiene cupo de casillas, se usa `soporte@` | ✅ |
| D11 | 2026-11-06 | **Decisión del dev:** Hostinger se configura por su API, para evitar errores en el panel. El dev sabe que el token no se puede limitar (acceso total a la cuenta, incluidos el dominio y el DNS). Resguardos: vence en 3 meses (elección del dev), y se recomienda revocarlo al terminar staging y crear uno nuevo para el lanzamiento; vive en `~/fenia-secrets`, nunca en el chat; solo se usan endpoints de lectura y de creación de base, subdominio, SSL, casilla y cron (nunca DNS, dominios, facturación ni borrados); se revoca al terminar | ✅ |
| D12 | 2026-11-06 | La API no crea cuentas FTP, así que el deploy de staging reutiliza las credenciales FTP de producción. Esa cuenta está limitada a `public_html` y su carpeta remota es `/`, así que staging = `/staging/`. Una barrera en CI exige que el destino termine en `staging/`. Staging tiene su propio archivo de estado de sincronización y nunca usa `dangerous-clean-slate` | ✅ |

## Preguntas abiertas

| # | Pregunta | Cómo se responde |
|---|---|---|
| Q1 | ✅ Respondida 2026-11-06 por API: plan **Business**, PHP **8.3.36** (disponibles de 7.3 a 8.5), `nd_pdo_mysql`, `curl`, `openssl`, `mbstring`, `zip`, `fileinfo` y `posix` activos. Cierra también [PENDING #3](../../PENDING.md) | — |
| Q6 | CDN de Hostinger (H-06). Staging: ✅ desactivada el 2026-11-06 con OK del dev; la de producción sigue activa y sin tocar. Falta decidir cómo se purga la CDN de producción después de cada deploy | Antes del lanzamiento |
| Q2 | ¿Mercado Pago firma también los avisos que manda a la `notification_url` de cada preferencia? (Mientras tanto aplica D7.) | Prueba INT-08 en staging |
| Q3 | ¿El firewall de Hostinger deja pasar los avisos de MP? Confirmado que **hay un firewall activo**: responde 403 a `<script>` en la URL | Prueba INT-09 en staging |
| Q5 | Cómo llegan la configuración secreta y los ZIP a la carpeta privada, fuera de `public_html`. La API de archivos solo escribe dentro de `public_html`, y no hay FTP propio de staging. Propuesta: se suben por la API a una carpeta "buzón" bloqueada (`.htaccess` deny) y el propio PHP los mueve a `~/fenia-private/<entorno>/` y borra el original | ✅ Implementado en el 3.1b (`Inbox.php` + `api/_inbox/.htaccess`). Solo acepta `config.php` y ZIPs de kits. S-11 verifica que el buzón no se sirva. Canal: API de archivos de Hostinger (TUS) al buzón, usado en staging el 2026-11-07 |
| Q4 | Ruta exacta de la carpeta privada y carpeta del subdominio de staging. Respondida en parte: Hostinger crea el subdominio en `public_html/staging`, dentro del sitio real, y se protege con C20. La carpeta privada se crea desde la casita (⌂) del Administrador de archivos | Guía, Bloque 1, pasos 1.3–1.4 |

## Hallazgos

| # | Fecha | Hallazgo | Impacto | Se corrige en |
|---|---|---|---|---|
| H-01 | 2026-11-06 | `https://fenia.com.ar/.ftp-deploy-sync-state.json` es público (HTTP 200): lista los archivos desplegados con sus hashes. La suite lo detecta (S-11 en rojo) | Mínimo: el repo es público, así que la lista ya se ve en GitHub. Se corrige por higiene | La regla ya funciona en staging (403, S-11 verde). Falta llevarla al `.htaccess` de producción en el paso 3.5 |
| H-02 | 2026-11-06 | DMARC de `fenia.com.ar` en `p=none`: los proveedores no rechazan mails que se hacen pasar por `@fenia.com.ar`. SPF y DKIM están bien | Medio una vez que haya ventas (phishing a compradores, B4) | Paso 6.4, coordinado con Mailerlite: primero reportes (`rua`), después `p=quarantine` |
| H-03 | 2026-11-06 | `/recursos-gratuitos?titulo=…` muestra cualquier texto como título. Sirve para armar links de aspecto oficial. No es XSS (usa `textContent`) | Bajo | ✅ Cerrado en 4.2a: el título sale de un mapa id → título armado en el build; `?titulo=` se ignora |
| H-05 | 2026-11-06 | Al crear el subdominio, Hostinger dejó `default.php` en `public_html/staging`, y `https://fenia.com.ar/staging/` responde 200 con la página genérica de Hostinger: C20 activo. S-25 lo detecta | Bajo (página genérica) | ✅ Cerrado 2026-11-06 en el paso 2.1: `fenia.com.ar/staging/` → 403 y `default.php` → 403 (S-25 verde) |
| H-06 | 2026-11-06 | ✅ Parte IP cerrada el 2026-11-07: `health` de producción muestra la IP real del visitante detrás de la CDN, así que los límites por IP funcionan. Sigue vigente la purga después de cada deploy (D18). Detalle original: | **La CDN de Hostinger está activa** en `fenia.com.ar` y en staging (`Server: hcdn`). Sirve la home con `Age` ≈ 10 días aunque el sitio pide `max-age=3600`. Por eso el DNS ya no apunta a la IP del cutover. Después de un deploy, la CDN puede mostrar la versión vieja durante días | **Alto para el lanzamiento**: un kit que pasa a `live` podría seguir viéndose "Próximamente". Además hay que asegurar que `/api` nunca se cachee (C21) | Q6. Purga de CDN después de cada deploy y verificación de frescura (OPS-07) antes del lanzamiento |
| H-07 | 2026-11-06 | Ya estaba de antes: [docs/audit/2026-05-26-cutover-status.md](../audit/2026-05-26-cutover-status.md) publica en el repo público el usuario FTP del deploy, el host, el puerto y la carpeta. Solo falta la contraseña para entrar | Medio: facilita intentos de fuerza bruta. El historial de git ya es público, así que borrarlo del doc no alcanza | Contraseña FTP fuerte y rotada. Antes del lanzamiento: deploy por **SFTP con llave** (el plan Business tiene SSH, ver [deployment.md](../ai-context/deployment.md)) y desactivar el usuario FTP expuesto |
| H-11 | 2026-11-14 | **Las fichas de Enseñar que se venden no muestran lo guardado** al reabrir una ficha o recargar la página: el paquete de Claude Design pasa el valor en el atributo `defaultvalue`, que el navegador no muestra. Lo escrito no se pierde: queda en el navegador y "Copiar para la IA" lo incluye. Las fichas nuevas de Evaluar y Aprender no tienen el problema | Medio: parece que la ficha no guardó | Corrección de pocas líneas, sin tocar ningún texto, en la copia que sirve "Tu kit" (`tu-kit\kit-ensenar-con-ia\fichas.html`). Probada en compu y celular, con la CSP del sitio. Falta el OK del dev para usarla también en el ZIP |
| H-10 | 2026-11-07 | La descarga por POST de formulario fallaba: Chrome y Firefox recibían el ZIP y lo volvían a pedir con GET (rechazado a propósito, C6). Además **gastaba una de las 5 descargas** | Alto: el comprador no podía bajar el kit | Corregido: la página baja el ZIP con `fetch` y lo entrega completo. Probado en Edge y Firefox |
| H-09 | 2026-11-07 | **MariaDB de Hostinger: "Illegal mix of collations"** al marcar una orden pagada (la conexión usa `utf8mb4_general_ci` y las tablas `utf8mb4_unicode_ci`). SQLite no lo mostraba. Nada se entregó por error: la orden quedó pendiente | **Crítico**: ninguna venta se habría entregado | Corregido: la decisión pasa a PHP y la conexión fija la collation. CI ahora corre los tests también contra MariaDB 10.11 |
| H-08 | 2026-11-07 | `www.fenia.com.ar` servía el sitio entero sin redirigir. Una compra empezada ahí habría fallado: la API exige que el pedido venga de `fenia.com.ar`. Además, Google veía dos copias | **Alto para el lanzamiento** | 3.5: redirección 301 en `public/.htaccess`. Verificar en producción después del merge |
| H-04 | 2026-11-06 | **El repositorio de GitHub es público** | Ningún archivo pago ni secreto puede entrar. Protegido por `.gitignore` + barrera en CI (OPS-01, OPS-06). La seguridad nunca depende de que el código no se vea | Hecho (2.2) |

## Lo que hoy está mal y se corrige en este plan

- [`src/pages/gracias.astro`](../../src/pages/gracias.astro) promete que Mercado Pago manda el link de descarga → se rehace en la Fase 4. Hoy nadie llega ahí: no hay kits en `live`.
- El schema de productos (`paymentUrl`, `deliveryMode: "mercadopago-auto"`) asume el Link de Pago → se migra en el paso 4.2 (ver ADR-028).
- El "Project Summary" de [AGENTS.md](../../AGENTS.md) dice que no hay backend y que el sitio está en Vercel → se actualiza en el paso 6.1.

## Bitácora

- **2026-11-14 (26)** — **"Tu kit" fase 1** (D26).
  - Reformateo verificado contra los originales: Evaluar (guía, mini pack 26/26, 10 fichas, 2 imprimibles) y Aprender (29 secciones, los 25 prompts en tarjetas con su advertencia, 8 fichas, 4 imprimibles). Fichas probadas en Edge, en compu y celular, desde el disco y servidas con la CSP del sitio: guardan, copian y no piden nada a internet.
  - Hallazgo H-11 en las fichas de Enseñar, con su corrección probada.
  - Backend: `kit.php` y `guia.php`, pase de lectura, esquema 2, config con guía, fichas e imprimibles, buzón que acepta `.html`. 179 tests de PHP.
  - Comprobado en staging que LiteSpeed reemplaza los encabezados que pone PHP: la CSP de las páginas del kit va en `api/.htaccess` (ADR-023).
  - Pruebas nuevas: S-30…S-36, U-40…U-42, E2E-01 ampliada y E2E-05 (captura y accesibilidad de "Tu kit" con datos simulados). `E2E_KEEP=1` deja un link de prueba sin reembolsar.
- **2026-11-12 (25)** — Diálogo con el dev sobre el "entorno FENIA" (idea de Fab): acordado D25 (identidad por link mágico, ver de por vida, guardar respuestas, arrancar con Enseñar). Inspección de las fichas de Enseñar: app en un solo archivo, sin recursos externos, guarda en `localStorage` bajo `fenia_plantillas_editable_v1`, fichas `t1…tN`. Por eso el prompt de reformateo pide una clave por kit e ids estables. Carpeta `reformateo` simplificada: un prompt por kit, el ZIP se adjunta directo. Pendiente nuevo: métricas internas (punto 9).

- **2026-11-09 (24)** — A+B en producción (D24, PR #25): las 3 guías reales se subieron por el buzón, `health` las ve y la batería contra producción dio 54/54. Primera venta real registrada el 7/11, entregada bien (Aprender, $13.000); falta que el dev confirme si fue su compra de prueba para reembolsarla. Otro cobro de $13.000 ("Varios") no pasó por el sitio: probablemente un Link de Pago viejo (D6).

- **2026-11-08 (23)** — Batería E2E con Playwright en `tests/e2e/`, a pedido del dev, para trabajar sobre ella en los próximos cambios. Resultados: staging 60/60, producción 54/54 y compra completa en el sandbox OK. Encontró 3 problemas de accesibilidad que ya existían. Quedan como "conocidos" en `accesibilidad.spec.ts` porque arreglarlos cambia la estética y necesita OK:
  - contraste del texto blanco sobre el coral de los botones;
  - links sin subrayado en `/gracias` y `/descarga`;
  - etiqueta gris de sección.

  También mostró que MP rechaza el reembolso unos segundos después del pago (422); la prueba reintenta.

- **2026-11-07 (22)** — Cierre del lanzamiento. La franja de la home queda con mejor contraste (WCAG AA), jerarquía y un brillo suave. Guía de ventas para Fabián. Tracker al día. Todo en producción y verificado (ver "Próximo paso").

- **2026-11-07 (21)** — El dev aprobó el cartel final y lo pasó a producción (PR #21). Fabián pidió algo llamativo arriba de todo: franja coral sobre el menú, con 56% OFF, cuenta regresiva y link a los kits; desaparece sola al vencer. El dev no la veía en staging porque su navegador tenía la página guardada (las páginas HTML tenían 1 hora de caché). Ahora el HTML se revalida siempre (`no-cache`, ADR-023).

- **2026-11-07 (20)** — **Lanzamiento.** El dev eligió D23 (la promo arranca con el lanzamiento y dura 7 días) y lanzar hoy, con los términos provisorios.
  - PR #19: los 3 kits a la venta, con la CDN purgada; 6 de 6 pedidos muestran la versión nueva.
  - Resumen diario prendido en producción.
  - Orden real de producción inspeccionada sin pagar: $13.000, cuenta real, URLs de `fenia.com.ar`, sin efectivo.
  - La tarea horaria de staging corre bien. Su aviso destapó que un email que rebota se reintentaba para siempre, con un aviso por hora. Ahora avisa una vez y reintenta durante 48 h.
  - Falta 6.3: compra real del dev y reembolso.

- **2026-11-07 (19)** — El dev pasó el token de producción. Producción quedó configurada, sin vender todavía (ver "Próximo", punto 4). La suite ✱ tuvo 2 fallas que eran de las pruebas, no del sistema: el firewall de Hostinger responde 403 a `../`, y antes del lanzamiento el kit se rechaza con 404 antes de mirar el email. Se ajustaron.
  - El dev propone mostrar un descuento en el cartel, con un precio de $30.000. Va como propuesta visual, que espera su OK, y antes se aclara qué precio se cobra.

- **2026-11-07 (18)** — PR #18 a producción (D13), con los kits en "Próximamente". Ver 6.2. Pendientes de producción: config, ZIP reales, tarea horaria, H-06 (IP detrás de la CDN, se ve en `health` cuando haya config) y la compra real.

- **2026-11-07 (17)** — El dev pasó la clave del webhook de prueba y decidió D22 (avisos a `soporte@`). Compras reales en el sandbox de MP: 3 pagos aprobados con tarjeta de prueba, un reembolso.
  - Detección de navegador automático en MP: con un navegador normal, la pantalla de pago carga bien.
  - **H-09** (crítico): la primera compra quedó trabada por un error de collation de MariaDB. Se diagnosticó con la salida de la tarea horaria, que imprime el error, y se corrigió. La orden se destrabó sola.
  - **H-10:** la descarga por formulario fallaba en Chrome y Firefox. Se corrigió con `fetch`.
  - El circuito completo funciona (ver 5.1). Mejoras de paso:
    - el aviso de una orden ajena responde 200, sin reintentos (S-03);
    - la conciliación sigue aunque una orden falle;
    - la CI prueba contra MariaDB.

- **2026-11-07 (16)** — El dev aprobó el mail y las 5 descargas, y pidió sacar del PDF de Enseñar la mención al `.md` (hecho, ver (15)). Mañana pasa la clave del webhook. Repaso general:
  - **H-08:** `www` no redirigía y habría roto las compras empezadas ahí. Corregido en el `.htaccess` (3.5).
  - **Origin `null`:** el estándar permite que algunos navegadores lo manden en `/descarga`, que tiene `no-referrer`. Chrome, Edge y Firefox 150 mandan el origen bien (verificado con navegadores reales). Igual se cubre con `Sec-Fetch-Site`, para Safari y modos de privacidad.
  - 3.4: tarea horaria hecha y creada en staging.
  - 3.3: mail real con DKIM, SPF y DMARC en pass.
  - Suite contra staging: 68 OK, 0 fallas. PHP: 154 tests.

- **2026-11-07 (15)** — El dev pidió revisar las descripciones de los kits y autorizó subir la config a staging.
  - Descripciones contra los PDF: Evaluar (29 págs., 8 prompts) y Aprender (30 págs., 25 prompts) OK. **Enseñar decía 25 páginas y tiene 37**; los 25 prompts están dentro del PDF (sección 8). Corregido.
  - El PDF de Enseñar mencionaba un archivo `Mini_Pack_de_Prompts.md` que no está en el ZIP. **El dev pidió sacar la frase**, porque los prompts ya están en el cuerpo del PDF. Se borró solo esa oración (pág. 27, el resto del PDF idéntico) en una copia: `C:\Fenia\entregables\corregido\`, con la carpeta interna renombrada a "Enseñar con IA". El original no se tocó. Esa copia es la que se sube en el lanzamiento.
  - 3.2 hecho (código). Decisiones D19 y D20. Comprobado con 139 tests de PHP y mutaciones. Una mutación sobrevivió y es equivalente: el compare-and-set ya impide ese cambio.
  - Staging configurado (3.2b). Riesgo nuevo A17 → D21 (ZIPs de prueba en staging).
  - Mejora encontrada en vivo: los pedidos inválidos gastaban el cupo de compras de la IP. Corregido: ahora solo cuentan los intentos que crearían una orden.
  - Suite contra staging, con los ajustes: sin fallas en lo que corre sin compra.
  - Pendiente antes del lanzamiento: verificar qué IP ve PHP detrás de la CDN de producción (H-06). Si todos los pedidos llegan con la IP de la CDN, los límites por IP no sirven.

- **2026-11-06 (14)** — El dev aprobó la propuesta visual y pidió verla y probarla.
  - **PR #17 a producción:** título "Evaluar en tiempos de IA" + botón D17. Hubo capturas antes y después, y se corrigió un rótulo que se partía en dos renglones.
  - Después del deploy la CDN servía la versión vieja en algunos pedidos. Se purgó por API y 6 de 6 pedidos mostraron la nueva (D18).
  - Empieza 4.2 en staging, con los kits visibles "en venta" solo ahí.
- **2026-11-06 (13)** — El dev dejó los ZIP en `C:\Fenia\entregables`. Se revisaron sin extraer (listado + SHA-256):
  - Evaluar: OK.
  - Aprender: trae un archivo que parece interno.
  - Enseñar: no coincide con lo que promete la página.

  Precio a AR$ 13.000 (D15). El dev pidió cambiar "recurso gratis" por "Comprar": queda para el lanzamiento, porque un botón de compra sin circuito sería mentir (regla de AGENTS.md). Propuesta visual escrita ([propuesta-visual.md](./propuesta-visual.md)), con un botón honesto para mientras tanto.
- **2026-11-06 (12)** — Núcleo adaptado a la API de Orders, con campos verificados en el SDK oficial `mercadopago/sdk-php`:
  - rutas `/v1/orders` y `/v1/orders/{id}`;
  - campos `status`, `status_detail`, `total_amount` y `total_paid_amount` (strings), `currency`, `user_id` y `transactions.chargebacks`;
  - **sin `live_mode`**: el control de sandbox lo hace `user_id`.

  `PaymentRules` reemplazado por `OrderRules`. Bug real encontrado por un test nuevo: `$` en las regex aceptaba un `\n` final; corregido con `/D` en todas.

  Paso 3.1b hecho: 114 tests, con mutación verificada sobre el límite de descargas y el compare-and-set.
- **2026-11-06 (11)** — Aplicación de MP creada (Checkout Pro vía Orders; Preferences avisa que "será descontinuada pronto", D14).
  - En el panel nuevo, las credenciales no se ven desde el menú de la documentación: se llega con el link directo de la aplicación.
  - Token de prueba pasado del celular por WhatsApp a sí mismo (aceptable solo por ser de prueba), validado contra MP y guardado. El de producción se pasará de otra forma.
  - Los productos y los ZIP se revisan el 2026-11-07.
- **2026-11-06 (10)** — **PR #16 mergeado a `main`** (`ca72899`) con autorización del dev (D13). Deploy a producción OK (corrida 42318156057).
  - Subieron solo `/api/_lib/*` y `/api/catalog.json` (11 cambios, 17 kB). Ningún archivo existente fue reemplazado.
  - Antes del merge se comparó el HTML de `dev` con producción: idéntico, salvo dos `uid` aleatorios de Astro.
  - Verificado después: las páginas responden 200, `/api/_lib` da 403 y el catálogo sirve los 3 kits en `coming-soon`. Suite ✱: 41 OK y 1 falla conocida (H-01).
  - Observación: Vercel sigue conectado al repo y hace deploys propios (no sirve el dominio). Es el pendiente #2 de [PENDING.md](../../PENDING.md).
- **2026-11-06 (9)** — **Staging andando:** corrida 42317545237, 41 archivos.
  - Verificado desde afuera: C20 y H-05 cerrados, la regla de H-01 funciona en LiteSpeed y producción quedó intacta.
  - Suite contra staging: 42 OK y 0 fallas. Contra producción: 41 OK y 1 falla conocida (H-01).
- **2026-11-06 (8)** — El dev aprobó explícitamente las dos acciones bloqueadas. Alias `soporte@` → `contacto@` creado y activo. Se agrega a CI el paso que siembra el archivo de estado vacío en `/staging/`: solo si falta y solo ese archivo.
- **2026-11-06 (7)** — CDN de staging desactivada por API (con OK del dev; producción intacta).
  - Pasos de Mercado Pago corregidos: desde nov-2025 la aplicación trae solo las credenciales de prueba, sin cuentas de prueba ni activación de producción.
  - Commits `78552c2` (docs) y `d406238` (núcleo PHP + CI) subidos a `dev`. Primera corrida en GitHub: PHP 8.1 y 8.3, build y U-31 en verde.
  - El ensayo del deploy a staging salió OK. La subida real falló dos veces con `ECONNRESET` (`b8bf235`).
  - El clasificador de seguridad bloqueó dos acciones que quedan para el dev: el alias `soporte@` y sembrar el archivo de estado del deploy usando el FTP de producción. El cambio local se deshizo; nada de eso se aplicó.
- **2026-11-06 (6)** — Token de Hostinger tomado del portapapeles, validado contra la API antes de guardarlo y portapapeles vaciado.
  - Configuración por API: base de staging, subdominio `staging` (SSL activo, PHP 8.3 respondiendo) y casilla `kits@`.
  - Verificado por DNS público que MX, SPF, DKIM y DMARC quedaron intactos.
  - Hallazgos nuevos: H-05 (página por defecto en `/staging/`) y **H-06 (CDN activa)**. Abierta Q6. `soporte@` no existe: falta decidir.
  - El usuario de la cuenta de Hostinger y el host de la base **no** se escriben en el repo público.
- **2026-11-06 (5)** — El dev pidió que la configuración la haga el dev de forma autónoma.
  - Se revisó la especificación oficial de la API de Hostinger: crea bases, subdominios, SSL, cron y casillas, y lee PHP. No crea cuentas FTP, y la API de archivos solo escribe dentro de `public_html`.
  - Decisiones: D11 (API, con resguardos), D12 (FTP de producción con barrera para staging) y D10 resuelta (`kits@` por API). Abierta Q5.
  - El trabajo humano baja a 2 permisos (P1 y P2). Se creó `~/fenia-secrets/` con los archivos vacíos.
- **2026-11-06 (4)** — El dev pidió pasos claros para el Bloque 1 y ofreció un acceso por API.
  - Se verificó que la API de Hostinger crea bases y subdominios, pero su token no se puede limitar → D11.
  - El Bloque 1 de la guía se reescribió en 6 pasos cortos, con rutas del panel verificadas en la documentación de Hostinger, y ahora incluye cómo verificar lo de GitHub.
  - Se reduce el trabajo manual: la carpeta privada lleva solo `staging/`; las subcarpetas las crea la app.
  - Riesgo nuevo C20: staging accesible bajo el dominio real.
- **2026-11-06 (3)** — Datos del dev para la Fase 1:
  - Se confirmó el vínculo GitHub → Hostinger: las 5 claves FTP de producción. Sirve solo para el deploy, no reemplaza el hPanel.
  - El dev es colaborador del repo (push, no admin) y puede cargar secrets con `gh secret set`.
  - Los 3 Links de Pago viejos están activos (D6).
  - La dirección visible es `soporte@` (D10). Los ZIP van al final (D9).
  - Guía actualizada.
- **2026-11-06 (2)** — El dev confirmó D2–D4 y ADR-028; contó que ya existen Links de Pago en MP (→ D6, riesgo C19).
  - Se escribió [guia-fase-1.md](./guia-fase-1.md), con los pasos de Mercado Pago verificados contra la documentación oficial.
  - Hecho: 2.2, 2.3 y 3.1a. Son 80 tests de PHP en 8.1 y una prueba de mutación: al romper a propósito la firma o el control de monto, los tests fallan.
  - El catálogo se genera en el build y U-31 verifica que coincida con los `.md`.
  - La suite de seguridad contra producción da 38 OK, 1 falla conocida (H-01) y 22 pendientes.
  - Hallazgos nuevos: H-02, H-03 y H-04. Confirmado que hay un firewall en Hostinger.
- **2026-11-06** — Se verificó que el Link de Pago de Mercado Pago no entrega archivos ni emite la factura del vendedor: ADR-025 partía de un supuesto falso. El dev eligió la opción B. Se escribió la Fase 0 (este tracker, threat model, plan de pruebas, ADR-028) y se marcaron como obsoletos los docs afectados. Hallazgo H-01 registrado.
