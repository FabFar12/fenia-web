# Threat model — venta y entrega de kits

> Investigación anti-estafa y bugs del circuito de [ADR-028](../adr/ADR-028-own-sales-circuit-on-hostinger.md). Estado del proyecto: [README.md](./README.md).
> Última actualización: 2026-11-06.

**Cómo se usa:** cada riesgo tiene un ID, cómo lo evitamos y las pruebas que lo verifican (IDs de [test-plan.md](./test-plan.md)). Un riesgo sin prueba es un hueco: si aparece uno nuevo, se agrega acá **y** en el plan de pruebas.

**El repositorio es público** (H-04): este documento y todo el código se pueden leer. Nada de lo de abajo depende de que el código esté oculto. Depende de secretos (claves, tokens) que nunca entran al repo.

## Qué protegemos

| Activo | Por qué importa |
|---|---|
| Los archivos de los kits | Es lo que se vende. Nadie debe obtenerlos sin un pago verificado |
| La plata y la cuenta de Mercado Pago de Fab | Contracargos o fraude con tarjetas pueden costar plata y poner en riesgo la cuenta |
| Los datos de los compradores (email) | Ley 25.326; confianza en la marca |
| La reputación de FENIA | Un comprador que paga y no recibe es peor que no vender |

## Quiénes pueden causar problemas

- **Comprador deshonesto:** quiere el kit sin pagar, o pagar y después pedir la plata de vuelta.
- **Atacante o bot:** prueba URLs, falsifica avisos de pago, usa el checkout para validar tarjetas robadas.
- **Estafador por WhatsApp:** manda comprobantes falsos o se hace pasar por FENIA.
- **Sistemas sin mala intención:** los escáneres de mail, las vistas previas de links y los reintentos de Mercado Pago, que rompen cosas si no los tenemos en cuenta.

---

## A. Conseguir el kit sin pagar

| ID | Riesgo | Cómo lo evitamos | Pruebas |
|---|---|---|---|
| A1 | **Aviso de pago falso:** alguien manda al webhook un POST que dice "el pago 123 está aprobado" | Verificamos la firma `x-signature` (HMAC-SHA256, comparación en tiempo constante). Aunque la firma sea válida, consultamos la orden a la API de MP con nuestro token (`GET /v1/orders/{id}`, D14): la verdad la dice la API, nunca el aviso | U-01…U-05, S-01, S-02, S-03 |
| A2 | Reenviar un aviso viejo y legítimo (replay) | El procesamiento es idempotente: un pago entrega una sola vez (id de pago único en la base + cambio de estado "compare-and-set"). Repetir un aviso no crea entregas | U-10, S-04 |
| A3 | Escribir a mano la URL de regreso (`/gracias?status=approved&payment_id=…`) | `/gracias` no confía en los parámetros: pregunta al servidor, que exige el id de pago **y** el código de la orden (128 bits aleatorios, imposible de adivinar) | S-05, S-06 |
| A4 | Adivinar o probar links de descarga en masa | Tokens aleatorios de 256 bits, guardados solo como hash; límite de intentos fallidos por IP | U-20, S-07, S-08 |
| A5 | Bajar el ZIP directo por URL | Los ZIP están fuera de `public_html`: no tienen URL. Solo salen por el endpoint de descarga | S-09 |
| A6 | Path traversal (`?file=../../config.php`) | El endpoint nunca recibe nombres de archivo; la ruta sale del catálogo del servidor según la orden | S-10 |
| A7 | Leer secretos o código: config, logs, `.git`, `api/_lib/*.php`, `composer.json`, el estado del deploy (ver H-01) | Config, ZIPs y logs fuera de `public_html`. `api/_lib/` bloqueado por `.htaccess` y con guarda en cada archivo. Archivos ocultos bloqueados. Errores genéricos, sin trazas | S-11 |
| A8 | **Cambiar el precio desde el navegador** (pagar AR$ 1) | El navegador solo manda el id del kit; el precio lo pone el servidor desde el catálogo. Al aprobarse, se compara `transaction_amount` y `currency_id` con lo esperado: si no coincide, no se entrega, queda "en revisión" y se avisa | U-11, S-12 |
| A9 | Pagar un kit y recibir otro | La orden fija el kit; el pago se vincula a la orden por `external_reference` | U-12 |
| A10 | Que un pago pendiente, en revisión o solo autorizado cuente como pagado | Solo entrega la orden con `status: processed` + `status_detail: accredited` (`OrderRules`). Los pendientes (`created`, `processing`, `action_required`) no entregan; D4 se aplica excluyendo medios de pago diferidos al crear la orden. Máquina de estados con transiciones válidas | U-13, U-14, INT-03 |
| A11 | Que un pago de prueba (sandbox) valga en producción | Las órdenes no traen `live_mode`; las de sandbox pertenecen al usuario de prueba, así que producción las rechaza por `user_id` ≠ la cuenta real. Credenciales y base separadas por entorno | U-15 |
| A12 | Que un pago de otra cuenta, o de otra cosa que cobra Fab, libere un kit | Consultamos con nuestro token (solo ve órdenes de la cuenta) y validamos `user_id`, `external_reference` y el id de la orden de MP atada a la nuestra al crearla | U-12, U-16 |
| A13 | Pedir reembolso o contracargo después de descargar | No se puede impedir del todo (ver riesgo residual). Al pasar a `refunded`, `charged_back` o `in_mediation` se revocan los links. Guardamos evidencia para disputas: aceptación de términos con fecha, IP, entrega y descargas | INT-05 |
| A14 | Compartir el link de descarga en un grupo | Vence a los 30 días, máximo 5 descargas (D3), registro de IPs y alerta si un link se usa desde muchas IPs distintas | U-21, S-13, S-14 |
| A15 | Usar "No me llegó" para mandarse el kit a otro email | Solo reenvía al email guardado en la compra. La respuesta es idéntica exista o no la compra (no revela quién compró) | S-15 |
| A16 | Ver el link de otra persona desde el endpoint de estado (IDOR) | Exige id de pago + código de orden; sin coincidencia, respuesta genérica | S-05 |

## B. Estafas con dinero

| ID | Riesgo | Cómo lo evitamos | Pruebas |
|---|---|---|---|
| B1 | **Comprobante falso por WhatsApp** ("ya pagué, mandámelo"): la estafa más común en Argentina, con capturas editadas o apps clon de MP | **Protocolo:** Fab nunca entrega a mano por una captura. Respuesta estándar: "usá *No me llegó* con el email de la compra". Si insiste, Fab busca el número de operación en *Actividad* de su cuenta de MP. El sistema es el único canal de entrega | P-01 |
| B2 | Mail falso de "recibiste un pago" dirigido a Fab | Nadie actúa por mails: el sistema verifica cada pago con la API | P-01 |
| B3 | **Prueba de tarjetas robadas (card testing):** bots que usan el producto más barato para validar tarjetas. Trae contracargos y puede comprometer la cuenta de MP | Límite de checkouts por IP y por email, campo trampa (honeypot), preferencias que vencen a los 60 min, una orden = un pago, alerta ante picos de rechazos, interruptor para cortar ventas sin deploy | U-30, S-16, S-17, INT-07 |
| B4 | Phishing haciéndose pasar por FENIA | Mails solo desde `@fenia.com.ar` con SPF/DKIM/DMARC. Nunca pedimos datos de tarjeta, y lo dicen el mail y `/gracias`. **Hoy DMARC está en `p=none` (H-02)**: se endurece antes de lanzar | OPS-02 |
| B5 | "Me cobraron dos veces" | Un segundo pago aprobado para la misma orden no duplica la entrega: se marca para reembolso y se avisa a Fab | U-17 |

## C. Bugs que dejan a alguien que pagó sin su kit

| ID | Riesgo | Cómo lo evitamos | Pruebas |
|---|---|---|---|
| C1 | El aviso de MP no llega (servidor caído, error nuestro) | MP reintenta cada 15 min. Además, una conciliación horaria pide a MP los pagos aprobados y entrega los que falten | INT-06 |
| C2 | El mail no sale o cae en spam | La entrega no depende del mail: botón de descarga en `/gracias` + "No me llegó". La conciliación reintenta el envío. SPF/DKIM/DMARC alineados | INT-04, OPS-02 |
| C3 | Avisos duplicados → mails duplicados | Los efectos ocurren solo en el cambio de estado (compare-and-set) | U-10, S-04 |
| C4 | Avisos fuera de orden ("pendiente" llega después de "aprobado") | Transiciones válidas definidas; las demás se descartan | U-14 |
| C5 | Carrera: dos avisos simultáneos, o aviso antes de que exista la orden | La orden se crea antes de ir a MP; actualización atómica en la base | U-18 |
| C6 | **Los escáneres de mail "gastan" el link** (Outlook Safe Links, antivirus corporativos, vistas previas de WhatsApp abren el link antes que la persona) | El link del mail abre una página; la descarga solo ocurre con un clic real (POST). GET y HEAD nunca cuentan. El token va después de `#`: no viaja al servidor ni queda en logs | S-18 |
| C7 | La persona cierra la ventana antes de volver al sitio | Mail + conciliación + "No me llegó" | INT-06 |
| C8 | Email mal escrito | Se pide dos veces y se muestra en `/gracias`. Corrección solo por protocolo: Fab verifica la compra en MP y el dev reenvía | P-02 |
| C9 | PHP convierte `data.id` en `data_id` al leer la URL del aviso: si se lee mal, **todas** las firmas fallan | Lectura de ambas formas + test con un aviso real | U-03, INT-08 |
| C10 | El firewall de Hostinger bloquea los avisos de MP. **Confirmado que el firewall existe**: responde 403 a `<script>` en la URL | Se prueba en staging antes de lanzar | INT-09 |
| C11 | La CSP actual (`form-action 'self'`) bloquea la redirección a MP después de enviar un formulario | La orden se crea con `fetch` y se navega al checkout con JS; sin JS, se muestra un link manual | E2E-01 |
| C12 | El precio de la card no coincide con el cobrado | Una sola fuente: el `.md`. El build genera el catálogo que lee PHP | U-31, E2E-01 |
| C13 | Se entrega el kit equivocado o una versión vieja | Catálogo de archivos con huella SHA-256, verificada antes de lanzar | E2E-02 |
| C14 | Token de MP inválido o vencido → se pierden todos los avisos en silencio | Si la API de MP falla respondemos 500 (MP reintenta) y alertamos. `/api/health` monitoreado | INT-10, OPS-05 |
| C15 | Un deploy borra o pisa archivos privados | Lo privado vive fuera de `public_html`, donde el usuario FTP del deploy no llega | OPS-03 |
| C16 | Un error en `.htaccess` pone todo el sitio en 500 | Se prueba en staging primero; rollback documentado en [deployment.md](../ai-context/deployment.md) | OPS-03 |
| C17 | Montos con decimales (8000 vs 8000.00) o zonas horarias | Comparación en centavos enteros; todo en UTC | U-11, U-21 |
| C18 | Un kit `coming-soon` u oculto se puede comprar llamando directo a la API | El checkout solo acepta kits `live` del catálogo | S-19 |
| C19 | **Pago huérfano:** alguien paga por uno de los Links de Pago viejos o por otro canal fuera del circuito, y nadie le entrega nada | Los links viejos se desactivan (D6). La conciliación busca pagos aprobados por el monto de un kit sin orden asociada y avisa a Fab y al dev, que resuelven con el protocolo P-02 | INT-11 |
| C21 | **La CDN de Hostinger sirve contenido viejo o cachea lo que no debe** (H-06): un kit `live` que sigue viéndose "Próximamente", un `/gracias` viejo, o una respuesta de `/api` cacheada y servida a otra persona | `Cache-Control: no-store` en todo `/api` y verificación de que la CDN lo respeta. Purga de la CDN después de cada deploy, y chequeo de frescura antes de anunciar | S-24, OPS-07 |
| C22 | **Límite de envío del plan de mail:** 100 mails por día por casilla. Con más ventas, algunos mails de entrega se demoran | La entrega no depende del mail: botón en `/gracias` y "No me llegó". La conciliación reintenta al día siguiente, y el reporte diario avisa si el uso se acerca al límite | INT-04, OPS-05 |
| C20 | **Staging accesible bajo el dominio real.** Hostinger crea la carpeta del subdominio en `public_html/staging`, así que lo de pruebas también responde en `fenia.com.ar/staging/`: contenido duplicado, confusión y endpoints de prueba bajo el dominio real | El `.htaccess` de staging rechaza todo pedido que no llegue por `staging.fenia.com.ar`. Staging lleva `noindex`, credenciales de prueba de MP y su propia base | S-25 |

## D. Seguridad web general

| ID | Riesgo | Cómo lo evitamos | Pruebas |
|---|---|---|---|
| D1 | Inyección SQL | Solo consultas preparadas (PDO) | S-20 |
| D2 | Inyección de cabeceras de mail (saltos de línea en el email) | Validación estricta + PHPMailer | U-32, S-21 |
| D3 | XSS por parámetros en `/gracias` | Nada se inserta como HTML, solo como texto; CSP | S-22 |
| D4 | CSRF: otro sitio dispara checkouts o reenvíos | Verificación del encabezado `Origin` + límites de frecuencia | S-23 |
| D5 | Errores que muestran rutas o trazas | `display_errors` apagado; mensajes genéricos | S-11 |
| D6 | Datos personales de más en los logs | Solo lo necesario, fuera de la web, con retención definida | OPS-04 |
| D7 | Respuestas de la API cacheadas, o tokens filtrados por el encabezado `Referer` | `Cache-Control: no-store` en `/api`; `Referrer-Policy: no-referrer` en la página de descarga | S-24 |
| D8 | Texto arbitrario en una página de FENIA vía parámetros de la URL (hoy `titulo` en `/recursos-gratuitos`, H-03): links de aspecto oficial para phishing | Los textos salen de datos del sitio (id del producto → título), nunca de la URL | E2E-04 |

## E. Operación

| ID | Riesgo | Cómo lo evitamos | Pruebas |
|---|---|---|---|
| E1 | Secretos filtrados (git, capturas, chats) | Nunca en el repo; chequeo automático en CI; procedimiento de rotación documentado | OPS-01 |
| E2 | Que nadie se entere de un problema | UptimeRobot sobre `/api/health`, reporte diario por mail a dev y Fab, alertas por anomalías | OPS-05 |
| E3 | Facturación y legales (factura por venta, términos, privacidad, botón de arrepentimiento) | Fuera del código: paso 1.5 del [tracker](./README.md) | — |
| E4 | **Un ZIP de un kit o un secreto termina en el repo público** (H-04) y queda regalado o expuesto para siempre en el historial | `.gitignore` de `*.zip`/`*.7z`/`*.rar`. La CI falla si hay un archivo comprimido o algo con forma de credencial de MP. Los ZIP viven fuera del repo (`C:\Fenia\entregables`) y en el servidor fuera de `public_html` | OPS-01, OPS-06 |

---

## Riesgo residual aceptado

Lo que este diseño **reduce pero no elimina**, a sabiendas:

- **Quien pagó puede compartir los archivos.** Protegemos el link, no el contenido. Una marca de agua con el email del comprador en el PDF queda como mejora futura.
- **Contracargos:** el Programa de Protección al Vendedor de Mercado Pago **no cubre productos digitales**. Con un ticket de AR$ 13.000 y evidencia guardada, se acepta el riesgo.
- **Si Mercado Pago está caído, no se vende.** No hay medio de cobro alternativo.

## Fuentes

- Firma de webhooks, tiempo de respuesta (22 s) y reintentos (cada 15 min): [Mercado Pago Developers — Webhooks](https://www.mercadopago.com.br/developers/en/docs/your-integrations/notifications/webhooks) · [Checkout Pro — Webhooks](https://www.mercadopago.com.co/developers/es/docs/checkout-pro/additional-content/notifications/webhooks)
- Errores comunes (firma, fuente de verdad, URL de regreso, duplicados, transiciones, montos): [Webhooks de Mercado Pago que no pierden pagos (ni regalan accesos)](https://dev.to/javiersivack/webhooks-de-mercado-pago-que-no-pierden-pagos-ni-regalan-accesos-1ae7)
- Estados de pago: [MP — Transaction status](https://www.mercadopago.com.ar/developers/en/docs/checkout-api-orders/payment-management/status/transaction-status)
- `binary_mode`: [MP — Enable binary mode](https://www.mercadopago.com.ar/developers/en/docs/checkout-pro/checkout-customization/preferences/binary-mode)
- Protección al vendedor sin cobertura para digitales: [MP — Requisitos del Programa de Protección al Vendedor](https://www.mercadopago.com.mx/ayuda/requisitos-programa-proteccion-vendedor_294) · [Contracargos en MP](https://mariovadillo.com.ar/contracargo-mercado-pago-estafa-como-defenderse/)
- Comprobantes falsos: [La Nación](https://www.lanacion.com.ar/tecnologia/como-evitar-las-estafas-con-comprobantes-truchos-de-mercado-pago-nid25012024/) · [Infobae](https://www.infobae.com/economia/2024/02/26/transferencias-fantasma-como-funcionan-los-clones-de-mercado-pago-con-los-que-estafadores-defraudan-a-comerciantes/)
- Card testing: [Fraudio — Card testing guide](https://www.fraudio.com/blog/card-testing-fraud-guide) · [Payrix — Mitigate card testing](https://resource.payrix.com/docs/mitigate-card-testing-attacks-best-practices)
- Escáneres de mail que consumen links: [better-auth #6985](https://github.com/better-auth/better-auth/discussions/6985) · [Microsoft Q&A — SafeLinks](https://learn.microsoft.com/en-us/answers/questions/4647398/how-to-replicate-one-time-links-rendered-invalid-b)
