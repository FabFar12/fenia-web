# Propuesta: sección interna de métricas

> Pedido del dev (2026-11-12): una sección de métricas del sitio y de las ventas, que solo vean el dev y Fab. El 2026-11-14 el dev confirmó que **se miden las visitas** y pidió una investigación de qué conviene medir. Es una **propuesta para charlar**; todavía no hay código.

## 1. Qué conviene medir en un sitio como FENIA

FENIA es un sitio de marca que vende kits digitales y consigue contactos para consultoría. Lo útil es poder contestar cinco preguntas. Cada una lleva su número principal.

| Pregunta | Qué se mide | Para qué sirve |
|---|---|---|
| **¿Cuánta gente llega y de dónde?** | Visitantes únicos y visitas por día. Fuente: Google, Instagram, WhatsApp, mail, directo, otros sitios. Campañas marcadas en el link (UTM). Celular o compu. Páginas más vistas | Saber qué canal trae gente y si una publicación o un mail funcionó |
| **¿Les interesa lo que ven?** | Visitas a la página de cada kit. Clics en "Comprar", en WhatsApp y en "Solicitar diagnóstico" | Saber qué kit atrae y si la home lleva a los kits |
| **¿Compran?** (el embudo) | Página del kit → clic en Comprar → pago iniciado en Mercado Pago → pago aprobado. Porcentaje en cada paso, por kit y por fuente | Es el número más importante: dónde se pierde la gente |
| **¿Cuánto se vende?** | Ventas, ingresos, ticket promedio, por kit y por día. Efecto de la promo. Reembolsos y pagos fallidos | Plata real, y si la promo movió las ventas |
| **¿Usan lo que compraron?** | Abrieron su kit, leyeron la guía, usaron las fichas, descargas, pedidos a "No me llegó" | Calidad de la entrega y señales para mejorar los kits |

Referencias para leer el embudo: en comercio electrónico compra, en promedio, cerca del 2,8 % de las visitas en el mundo y el 1,5 % en Latinoamérica. Con pocas visitas, conviene mirar el número de ventas más que el porcentaje.

Además, tres controles técnicos, fuera de la pantalla principal:

- **Velocidad (Core Web Vitals):** es "buena" si la carga principal (LCP) tarda 2,5 s o menos, la respuesta a un toque (INP) 200 ms o menos, y la página no salta al cargar (CLS 0,1 o menos). Se puede medir una vez por día con la API gratuita de PageSpeed Insights, desde la tarea horaria.
- **Disponibilidad:** UptimeRobot, gratis, revisa el sitio cada 5 minutos desde afuera y avisa por mail. Desde junio de 2026 su plan gratis permite uso comercial.
- **Búsquedas en Google:** Google Search Console, gratis, dice con qué búsquedas aparece el sitio, cuántos clics recibe y en qué posición. Se mira en su propio panel.

## 2. Cómo medir las visitas: tres caminos

| | **A. Contador propio** (recomendado) | **B. Umami Cloud gratis** | **C. Plausible Business** |
|---|---|---|---|
| Costo | USD 0 | USD 0 (hasta 100.000 eventos por mes) | USD 19 por mes |
| Dónde se ven los números | En nuestra pantalla de métricas, junto a las ventas | En el panel de Umami | En nuestra pantalla (tiene API) y en su panel |
| Embudo visita → venta en un solo lugar | Sí: visitas y ventas en la misma base | No: las ventas no están en Umami | Sí, vía API |
| Acceso para Fab | Con el mismo acceso de la pantalla | No: el plan gratis no tiene usuarios para el equipo | Sí: hasta 10 personas |
| Cookies o cartel de aviso | No | No | No |
| Lo frenan los bloqueadores de anuncios | Casi nunca: es del propio sitio | Seguido | Seguido |
| Trabajo | Lo construimos: un registro de visitas sin datos personales y su tablero | Pegar un script y ajustar la CSP | Pegar un script, ajustar la CSP y conectar la API |
| Qué no tiene | Mapa por país (se puede sumar después) | API para cruzar con ventas | — |

Para comparar: el plan de USD 9 de Plausible que eligió [ADR-024](../adr/ADR-024-observability-on-hostinger.md) **no trae API, embudos ni ingresos**, y es para una sola persona. Por eso no alcanza para una pantalla común del dev y Fab.

**Por qué recomiendo el contador propio:** junta visitas y ventas en la misma pantalla sin pagar nada, no manda datos a terceros y casi ningún bloqueador lo frena. Además, la base, los límites de pedidos y los tests ya existen. Funcionaría como Plausible: sin cookies, y en vez de la IP guarda una huella que cambia cada día, así que no se puede seguir a nadie de un día a otro. Se descartan los bots.

## 3. Cómo se entra

| | **Usuario y contraseña** | **Link por mail** |
|---|---|---|
| Cómo es | Una pantalla de ingreso; el dispositivo queda recordado 30 días | Se escribe el email y llega un link que entra directo; el dispositivo queda recordado 30 días |
| Para Fab | Conocido, pero hay que recordar la contraseña | Nada que recordar |
| Si alguien olvida la clave | La cambia el dev | No aplica |
| Seguridad | Contraseñas guardadas cifradas e intentos limitados | Solo las dos direcciones habilitadas reciben links, que vencen a los 15 minutos |
| Extra | — | Es la misma pieza que necesita "Mis kits" (fase 2 de D25): se prueba primero con dos personas |

Las dos opciones son seguras. Recomiendo el link por mail por Fab y porque adelanta trabajo de "Mis kits". Si preferís usuario y contraseña, también está bien.

## 4. Primera versión de la pantalla (para ajustar en la charla)

```
MÉTRICAS FENIA                                      actualizado 14/11 10:05
[ Hoy ]  [ 7 días ]  [ 30 días ]  [ Todo ]

VISITAS                     Visitantes 1.240 · Visitas 1.610
  De dónde vienen: Instagram 38 % · Google 22 % · WhatsApp 17 % · Directo 15 % · Mail 8 %
  Celular 81 % · Compu 19 %

EMBUDO DE COMPRA            Página del kit 420 → Comprar 61 → Pago iniciado 40 → Pagado 12
                            Compran 2,9 % de los que ven un kit
  Por kit: Evaluar 5 · Aprender 4 · Enseñar 3

VENTAS                      Ventas 12 · Ingresos $156.000 · Reembolsos 0
  Ventas por día (30 días): ▁ ▂ ▅ █ ▃ ▂ ▁ …

TU KIT                      Abrieron su kit 11 de 12 · Usaron las fichas 7 · Bajaron el ZIP 6

ÚLTIMAS VENTAS
  14/11 09:12   Enseñar    a***@gmail.com    $13.000    abrió su kit ✓
```

Los números son de ejemplo. Los emails se ven enmascarados, como en el resto del circuito.

## Qué tenemos que charlar

1. ¿Las cinco preguntas cubren lo que querés ver? ¿Sacamos o sumamos algo?
2. Visitas: contador propio (recomendado), Umami gratis o Plausible de USD 19.
3. Entrada: link por mail (recomendado) o usuario y contraseña.

Fuentes: [Plausible, planes](https://plausible.io/#pricing) · [Umami, planes](https://umami.is/pricing) · [UptimeRobot y el uso comercial](https://flarewarden.com/insights/uptimerobot-free-plan-commercial-use) · [Core Web Vitals 2026](https://prooflytics.io/blog/core-web-vitals-thresholds-2026-lcp-inp-cls) · [Conversión de comercio electrónico 2026](https://www.mantasdigital.com/cro-2/ecommerce-conversion-rate-benchmarks-2026/)
