# Plan de pruebas — venta y entrega de kits

> Verifica cada riesgo de [threat-model.md](./threat-model.md). Estado del proyecto: [README.md](./README.md). Arquitectura: [ADR-028](../adr/ADR-028-own-sales-circuit-on-hostinger.md).
> Última actualización: 2026-11-06.

**Principio:** ningún riesgo sin prueba, ningún paso sin pruebas en verde, ningún lanzamiento sin la [compuerta](#compuerta-de-lanzamiento) completa.

## Niveles

| Nivel | Qué es | Dónde corre | Cómo se corre |
|---|---|---|---|
| **U** — Unitarias | La lógica crítica, aislada: firma, estados, montos, tokens, límites | CI (GitHub Actions). No hace falta PHP local | Automático en cada push/PR (paso 2.2) |
| **S** — Suite de seguridad | Ataques de caja negra contra el sitio: intentar descargar sin pagar, falsificar avisos, buscar archivos, inyecciones | Contra staging (completa) y contra producción (solo las marcadas ✱: no compran ni mandan mails) | `npm run test:ventas` con `BASE_URL=…`. Corre con el test runner nativo de Node, sin dependencias nuevas. **Nunca** encadenado a `npm run dev` |
| **INT** — Integración | El circuito real con el sandbox de Mercado Pago: cuentas de prueba y tarjetas con resultado predefinido | Staging, con credenciales de prueba | Manual guiado + verificación en la base y los logs |
| **E2E** — Recorrido completo | Lo que vive la persona compradora, en navegador real | Staging | Playwright (ya está en el repo) + checklist |
| **P** — Protocolos | Lo que hacen las personas ante situaciones de riesgo | Simulacro con Fab | Guion escrito (paso 6.1) |
| **OPS** — Operación | Secretos, mail, deploy, monitoreo | Staging y producción | Checklist |

**Entornos:** local (solo frontend; `npm run dev` no cambia y el botón de pago muestra un aviso de que funciona solo en el sitio publicado), **staging** (`staging.fenia.com.ar`, credenciales de prueba de MP, base propia) y **producción**.

**Tarjetas de prueba de MP:** el resultado se elige con el nombre del titular: `APRO` (aprobado), `OTHE` (rechazado), `CONT` (pendiente); DNI `12345678`. Fuente: [MP — Tarjetas de prueba](https://www.mercadopago.com.ar/developers/es/docs/your-integrations/test/cards).

---

## Catálogo de pruebas

**Implementadas al 2026-11-07:**
- U-01…U-05, U-10…U-18, U-20, U-21, U-30, U-32: [`tests/php/`](../../tests/php). U-10 y U-18 cubren la decisión (`OrderRules`) y la transición atómica en la base (`OrderStore`), sobre SQLite; contra MySQL se verifican en staging.
- Flujo completo a nivel servicio ([`ShopTest`](../../tests/php/ShopTest.php), con MP y mail falsos y reloj simulado). Cubre:
  - el checkout con el precio del catálogo (S-12);
  - la entrega única;
  - el pago tardío leído desde `/gracias`;
  - 5 descargas y la sexta rechazada (S-13);
  - el vencimiento (S-14);
  - el ZIP alterado;
  - el reembolso;
  - "No me llegó" (D19);
  - el mail caído y la conciliación.
- U-31: [`scripts/verify-catalog.mjs`](../../scripts/verify-catalog.mjs).
- E2E con Playwright, en [`tests/e2e/`](../../tests/e2e). Comandos en el tracker, sección "Cómo se corren las pruebas". Cubre:
  - E2E-01: compra completa en el sandbox → entrega → descarga → reembolso → link anulado (`npm run e2e:compra`);
  - la promo en todos sus momentos, con reloj simulado;
  - la grilla y los botones en celular y escritorio;
  - las páginas de soporte;
  - las capturas de referencia;
  - accesibilidad WCAG AA con axe, con los problemas conocidos listados.
- Suite de seguridad en [`tests/security/`](../../tests/security):
  - S-01, S-02, S-05…S-08, S-10, S-11, S-15, S-17, S-19…S-25 y S-30…S-35 son ✱, seguras en producción; se saltean si `/api/health.php` no responde OK;
  - S-13, S-16, S-18 y S-36 son solo para staging y necesitan variables opcionales (ver el encabezado de `api.test.mjs`);
  - S-03 y S-04 se prueban en INT.
- OPS-01 y OPS-06: barrera automática en CI.

El resto está escrito como pendiente y se implementa en el paso que construye cada pieza.

### U — Unitarias

| ID | Verifica | Pasa si |
|---|---|---|
| U-01 | Firma válida con un vector conocido | Se acepta |
| U-02 | Firma alterada o secreto incorrecto | Se rechaza |
| U-03 | `data.id` alfanumérico en mayúsculas; lectura de `data.id` / `data_id` | Se usa en minúsculas y se lee en ambas formas |
| U-04 | Falta `x-request-id` o `data.id` | El manifiesto se arma sin esa parte, como indica MP |
| U-05 | `x-signature` ausente o malformada | Se rechaza sin errores de PHP |
| U-10 | El mismo pago notificado N veces | Una sola entrega y un solo mail |
| U-11 | Monto o moneda distintos (7999.99, 8000.00, USD, UYU) | 8000.00 entrega; el resto no y queda "en revisión" |
| U-12 | Un pago de una orden del kit A | Nunca libera el kit B |
| U-13 | Cada estado de orden de MP: `created`, `processing`, `action_required`, `failed`, `canceled`, `refunded`, `processed` sin `accredited`, estados nuevos | Ninguno entrega; solo `processed` + `accredited` |
| U-14 | Transiciones inválidas (aprobado → pendiente); aprobado → `refunded` / `charged_back` / `in_mediation` | Las inválidas se descartan; las de reembolso o disputa revocan los links |
| U-15 | Orden de sandbox en producción (`user_id` del usuario de prueba) | No entrega |
| U-16 | `user_id` ajeno o ausente, `external_reference` desconocido | No entrega |
| U-17 | Segundo pago aprobado para la misma orden | No duplica la entrega; marca para reembolso y alerta |
| U-18 | Dos procesamientos simultáneos del mismo pago | Una sola entrega |
| U-20 | Generación de tokens | 256 bits aleatorios; solo se guarda el hash; comparación en tiempo constante |
| U-21 | Vencimiento (UTC) y límite de descargas | Vencido o agotado → rechazado |
| U-30 | Limitador de frecuencia | Bloquea al superar el umbral y libera al pasar la ventana |
| U-31 | Catálogo generado por el build (en Node, después del build en CI) | Coincide con los `.md` (productos visibles, título, estado, precio, moneda) |
| U-32 | Validación de email | Rechaza saltos de línea y direcciones inválidas |
| U-40 | "Tu kit" (ShopTest): abrir el kit, el pase de lectura, la guía, las fichas y los imprimibles | Leer y usar no gasta descargas; un link sin descargas sigue abriendo; el pase no sirve para otro kit, otro link ni más tiempo; los pases inventados se limitan por IP; un reembolso cierra el kit; un archivo cambiado no se sirve; abrir desde muchas IPs no dispara la alerta de link compartido; el mail de un kit con fichas invita a abrirlo |
| U-41 | Migración del esquema 1 al 2 (StoresTest) | Agrega `download_log.kind` y sube los links ya enviados de 5 a 10 descargas, sin perder las ya hechas; correrla dos veces no cambia nada |
| U-43 | "No me llegó" con acceso para siempre (D27, ShopTest) | Un link vencido se renueva sin límite y el nuevo arrastra las descargas que quedaban; un link sin descargas se reenvía igual y sigue abriendo el kit; nunca hay más de 10 descargas por compra |
| U-42 | Config de "Tu kit" (EnvConfigInboxTest) | Lee guía, fichas e imprimibles con su tamaño; rechaza extensiones, rutas y tamaños inválidos; el buzón acepta solo `.html` de nombre válido |

### S — Suite de seguridad (✱ = segura para correr contra producción)

| ID | Ataque | Pasa si |
|---|---|---|
| S-01 ✱ | POST al webhook sin firma | 401, sin efectos |
| S-02 ✱ | POST al webhook con firma inventada | 401, sin efectos |
| S-03 | Firma válida + id de pago inexistente | 200 sin entrega |
| S-04 | El mismo aviso real repetido 5 veces | Una sola entrega |
| S-05 ✱ | Endpoint de estado con un pago ajeno o un código de orden incorrecto | Respuesta genérica, sin link |
| S-06 ✱ | `/gracias?status=approved&payment_id=123` escrito a mano | No aparece ningún botón de descarga |
| S-07 ✱ | 1.000 tokens de descarga aleatorios | Todos rechazados con la misma respuesta |
| S-08 ✱ | N intentos fallidos seguidos desde una IP | 429 |
| S-09 ✱ | Rutas probables de los ZIP (`/kits/*.zip`, `/storage/`, `/files/`, `/downloads/`…) | 404, sin listado de carpetas |
| S-10 ✱ | Path traversal: `../`, codificado y doble codificado | 400/404 |
| S-11 ✱ | Archivos sensibles: `/api/_lib/*.php`, `config.php`, `.env`, `.git/config`, `composer.json`, `*.log`, `.ftp-deploy-sync-state.json` (H-01) | 403/404; ninguna respuesta contiene secretos, rutas ni trazas |
| S-12 | POST de checkout con campos `price`, `amount`, `unit_price` | Se ignoran: la preferencia sale con el precio del catálogo |
| S-13 | Sexta descarga con el mismo link | Rechazada |
| S-14 | Link vencido | Rechazado |
| S-15 ✱ | "No me llegó" con un email sin compras | La misma respuesta que con compras, y no sale ningún mail |
| S-16 | Más de N checkouts por IP en una hora | 429 |
| S-17 ✱ | Formulario con el campo trampa completado | Rechazado sin crear nada |
| S-18 | GET y HEAD repetidos al link del mail (simulando un escáner) | El contador de descargas no cambia |
| S-19 ✱ | Checkout de un kit `coming-soon`, `hidden` o inexistente | 404, sin crear nada |
| S-20 ✱ | Payloads de inyección SQL en todos los campos | Sin 500 y sin cambios en la base |
| S-21 ✱ | Email con `\r\n` | Rechazado |
| S-22 ✱ | Parámetros con `<script>` en `/gracias` y `/recursos-gratuitos` | El payload nunca vuelve en la respuesta. 200 o 403: el firewall de Hostinger bloquea `<script>` en la URL |
| S-23 ✱ | POST desde otro origen (`Origin` ajeno) | Rechazado |
| S-25 ✱ | `https://fenia.com.ar/staging/` y `/staging/api/…` (staging visto desde el dominio real, C20) | 403/404; staging solo responde en `staging.fenia.com.ar` y con `noindex` |
| S-24 ✱ | Encabezados | `/api/*` con `Cache-Control: no-store`; descarga con `Referrer-Policy: no-referrer`; HSTS y CSP presentes |
| S-30 ✱ | "Tu kit": fichas e imprimibles sin pase de lectura | 403, con la CSP de las páginas del kit (`default-src 'none'`, sin red ni formularios) y `no-store` |
| S-31 ✱ | "Tu kit": la guía sin pase | 403 y sin CSP (comprueba que LiteSpeed respeta el `unset` de `api/.htaccess`) |
| S-32 ✱ | Pase inventado, de otro kit, o con rutas raras en `k` y `r` | Nunca un archivo: 403/404/429 |
| S-33 ✱ | Abrir el kit con tokens al azar | 404/429, sin pase |
| S-34 ✱ | Abrir el kit desde otro sitio (`Origin` ajeno) | 403, sin pase |
| S-35 ✱ | Métodos que no corresponden en `kit.php` y `guia.php` | 405 |
| S-36 | Con un token de una compra de prueba: el pase abre las fichas | 200, cookie `HttpOnly` + `SameSite=Strict` + `Path=/api/`, y las descargas restantes no cambian |

### INT — Integración con el sandbox de Mercado Pago (staging)

| ID | Escenario | Pasa si |
|---|---|---|
| INT-01 | Compra aprobada (`APRO`) | Llega el mail, `/gracias` muestra el botón y el ZIP descarga bien |
| INT-02 | Compra rechazada (`OTHE`) | Sin entrega; mensaje claro |
| INT-03 | Pago pendiente (`CONT`) con `binary_mode` | Termina rechazado, sin entrega |
| INT-04 | SMTP caído durante una compra | La conciliación reenvía el mail cuando vuelve |
| INT-05 | Reembolso desde el panel de MP | Links revocados; descarga denegada |
| INT-06 | Webhook apagado durante una compra | La conciliación entrega en ≤ 1 h |
| INT-07 | Ráfaga de pagos rechazados | Llega la alerta de pico |
| INT-08 | Avisos reales de MP | Traen `x-signature` válida y `data.id` se lee bien (responde Q2) |
| INT-09 | Firewall de Hostinger | Los avisos llegan: 200 en el panel de notificaciones de MP (responde Q3) |
| INT-10 | Token de MP inválido | El webhook responde 500 y alerta; al corregirlo, el reintento de MP se procesa |
| INT-11 | Pago aprobado por un monto de kit sin orden asociada (simula un Link de Pago viejo) | La conciliación lo detecta y avisa; no entrega nada sola |

### E2E — Recorrido completo (staging)

| ID | Escenario | Pasa si |
|---|---|---|
| E2E-01 | Card → página del kit → email → checkout sandbox → `/gracias` → "Tu kit" (guía en el navegador, fichas, descargas) → reembolso | Funciona de punta a punta; el precio es el mismo en la card, la página y el checkout; leer y usar las fichas no gasta descargas; en la ficha de prueba corren el script `blob:` y `new Function`, internet queda bloqueado y lo escrito se guarda; el reembolso cierra el kit |
| E2E-05 | "Tu kit" con datos simulados (sin comprar) | Captura de referencia en escritorio, celular y Firefox, y accesibilidad con axe sin problemas nuevos |
| E2E-02 | Descarga de los 3 kits | La huella SHA-256 de cada ZIP descargado coincide con el original |
| E2E-03 | Mobile (390 px) y desktop | Capturas antes/después aprobadas (regla de AGENTS.md) |
| E2E-04 | `/recursos-gratuitos?titulo=Texto inventado` (H-03) | La página no muestra el texto de la URL; el título sale del id del producto |

### P — Protocolos humanos

| ID | Simulacro | Pasa si |
|---|---|---|
| P-01 | Llega por WhatsApp una captura de "pagué y no me llegó" | Fab aplica el protocolo y no entrega nada a mano |
| P-02 | Una compra con el email mal escrito | Se verifica en MP y se reenvía al email correcto por el procedimiento |

### OPS — Operación

| ID | Chequeo | Pasa si |
|---|---|---|
| OPS-01 | Secretos | Ninguno en el repo: la CI falla ante algo con forma de credencial de MP; además, revisión manual |
| OPS-02 | Mail de entrega | [mail-tester](https://www.mail-tester.com/) ≥ 9/10; DMARC publicado |
| OPS-03 | Deploy y rollback | Después de un deploy, config, ZIPs y base intactos; rollback de `.htaccess` ensayado en staging |
| OPS-04 | Logs | Sin datos de tarjeta ni secretos; retención definida |
| OPS-05 | Monitoreo | UptimeRobot alerta si `/api/health` falla; llega el reporte diario |
| OPS-06 | Ningún archivo comprimido (`.zip`, `.7z`, `.rar`) en el repo público | La CI falla si aparece uno (automático en cada push) |
| OPS-07 | Frescura detrás de la CDN de Hostinger (H-06, C21) | Después de un deploy y de la purga, la home y `/gracias` muestran la versión nueva (`Age` bajo). `/api/*` nunca sale de la caché de la CDN |

---

## Compuerta de lanzamiento

Todo en verde antes del PR `dev` → `main` que activa la venta:

- [ ] U: 100% verde en CI.
- [ ] S: 100% verde contra staging.
- [ ] INT-01 … INT-11 verdes; Q2 y Q3 respondidas.
- [ ] E2E-01 … E2E-04 verdes; capturas aprobadas.
- [ ] Revisión de seguridad del código (skill `security-review`) sin hallazgos abiertos.
- [ ] P-01 y P-02 ensayados con Fab.
- [ ] OPS-01 … OPS-07 verdes; hallazgos H-01, H-02, H-03, H-05 y H-06 cerrados.
- [ ] Legales y facturación resueltos (paso 1.5).

Después del merge, antes de anunciar:

- [ ] Subconjunto ✱ de S verde contra producción.
- [ ] Compra real en producción + reembolso → revocación verificada.

## Después del lanzamiento

- Subconjunto ✱ de S **todas las semanas** contra producción (workflow programado en GitHub Actions).
- Revisión mensual del reporte de anomalías: rechazos, links con muchas IPs, reembolsos, contracargos.
- Todo bug o incidente real → fila nueva en [threat-model.md](./threat-model.md) + prueba nueva acá.
