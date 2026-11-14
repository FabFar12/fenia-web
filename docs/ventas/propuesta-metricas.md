# Propuesta: sección interna de métricas

> Pedido del dev (2026-11-12): una sección de métricas del sitio y de las ventas, que solo vean el dev y Fab. Es una **propuesta para aprobar** (regla de AGENTS.md); todavía no hay código.

## Para quién y dónde

- Solo el dev y Fab. No aparece en el menú, no se indexa y no se cachea.
- Una sola pantalla, pensada primero para el celular.

## Qué muestra

```
MÉTRICAS FENIA                                      actualizado 14/11 10:05
[ Hoy ]  [ 7 días ]  [ 30 días ]  [ Todo ]

VENTAS
  Ventas 12        Ingresos $156.000        Compras empezadas → pagadas: 40 → 12 (30 %)
  Por kit: Evaluar 5 · Aprender 4 · Enseñar 3
  Reembolsos 0 · Para revisar 0 · Mails sin enviar 0

VENTAS POR DÍA (últimos 30 días)
  ▁ ▂ ▅ █ ▃ ▂ ▁ ▁ ▂ ▃ …

TU KIT: QUÉ HACEN DESPUÉS DE COMPRAR (desde el 14/11)
  Abrieron su kit            11 de 12
  Leyeron la guía online      9
  Usaron las fichas           7
  Abrieron los imprimibles    3
  Bajaron el ZIP              6
  Pidieron "No me llegó"      1

ÚLTIMAS VENTAS
  14/11 09:12   Enseñar    a***@gmail.com    $13.000    abrió su kit ✓
  13/11 21:40   Evaluar    m***@hotmail.com  $13.000    todavía no lo abrió
```

- Los emails se ven enmascarados, como en el resto del circuito.
- "Todavía no lo abrió" sirve para escribirle a alguien que pagó y no entró.
- Los datos de "Tu kit" existen desde el 14/11, cuando se empezó a registrar qué abre cada comprador.

## De dónde salen los números

- **Ventas y uso de "Tu kit":** de la base de ventas que ya existe (órdenes y registro de aperturas y descargas). Sin servicios nuevos y sin costo.
- **Visitas al sitio:** hoy no se miden. [ADR-024](../adr/ADR-024-observability-on-hostinger.md) eligió Plausible (sin cookies, USD 9 por mes), pero nunca se activó. Con visitas se podría ver, por ejemplo, cuántos de los que entran a la página de un kit lo compran.

## Cómo se entra

- **Recomendado: link mágico por mail.** Solo a dos direcciones fijas, la del dev y la de Fab. Sin contraseñas. Dura 30 días en cada dispositivo. Es la misma pieza que va a usar "Mis kits" (fase 2 de D25), así que se prueba primero acá, con dos personas.
- Alternativa más simple: usuario y contraseña del servidor. Menos cómodo para Fab.

## Qué tiene que decidir el dev

1. ¿Aprobás el contenido de la pantalla? (Se pueden sacar o sumar números.)
2. ¿Entrada por link mágico (recomendado) o con usuario y contraseña?
3. ¿Visitas del sitio: activar Plausible (USD 9 por mes), un contador propio sin cookies, o dejarlo para después?
