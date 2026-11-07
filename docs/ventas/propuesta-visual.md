# Propuesta visual — compra de kits (paso 4.1)

> Para aprobar **antes** de implementar (regla PROPOSE-THEN-EXECUTE de [AGENTS.md](../../AGENTS.md)). Sigue la [guía de estilo](../ai-context/style-guide.md): fondo navy `#0B1A2E`, Plus Jakarta Sans, coral `#E8573D` para el botón principal (con su sombra), cyan `#00B4D8` como acento, cards `rounded-2xl`, texto ≥ `white/65` (WCAG AA), mobile-first.
> Estado: **propuesta, sin aprobar** (2026-11-06).

## 1. Card del kit en "Productos digitales"

**Hoy** (kit en "Próximamente"):
```
┌──────────────────────────────────────────┐
│▔▔▔▔▔▔▔▔▔▔▔▔ (franja cyan)                 │
│ CAJA DE HERRAMIENTAS · PRÓXIMAMENTE       │
│ Evaluar en tiempos de IA                │
│ Un kit para rediseñar consignas…          │
│──────────────────────────────────────────│
│ En preparación   [Quiero el recurso gratis →] │  ← botón gris
└──────────────────────────────────────────┘
```

**Al lanzar** (kit en `live`):
```
┌──────────────────────────────────────────┐
│▔▔▔▔▔▔▔▔▔▔▔▔ (franja cyan)                 │
│ CAJA DE HERRAMIENTAS                      │
│ Evaluar en tiempos de IA                │
│ Un kit para rediseñar consignas…          │
│──────────────────────────────────────────│
│ AR$ 13.000            [ Comprar → ]       │  ← botón coral (primario)
│ Descarga inmediata                        │  ← texto chico white/65
└──────────────────────────────────────────┘
```
- El botón lleva a la **página del kit** (punto 2), no directo a Mercado Pago: ahí se pide el email al que llega el kit (threat model C8).
- **Hasta el lanzamiento**, el botón de los kits "Próximamente" pasa a **"Avisame cuando esté disponible →"**. Hoy promete un "recurso gratis" que todavía no existe (no hay Mailerlite configurado) y termina en WhatsApp.

## 2. Página del kit — `/kits/<kit>` (nueva)

Desktop (2 columnas):
```
← Volver a productos

CAJA DE HERRAMIENTAS
Evaluar en tiempos de IA                          (H1, 800)
Un kit para rediseñar consignas, rúbricas…          (summary, white/65)

┌─ Qué incluye ─────────────────────┐   ┌─ Comprar ────────────────────────┐
│ • Guía PDF de 29 páginas           │   │ AR$ 13.000                        │
│ • 10 plantillas editables (DOCX)   │   │ Pago único · Descarga inmediata   │
│ • 2 imprimibles (PDF)              │   │                                    │
│ • Mini pack de 8 prompts           │   │ Tu email (ahí te llega el kit)     │
│                                    │   │ [______________________________]  │
│ Para quién es                      │   │ Repetí tu email                    │
│ • Docentes de secundaria…          │   │ [______________________________]  │
│ Para quién no es                   │   │ ☐ Acepto los términos y entiendo   │
│ • Quienes buscan "10 trucos"…      │   │   que el contenido digital no      │
│ Formato                            │   │   admite arrepentimiento (*)       │
│ PDF + DOCX + plantillas…           │   │                                    │
└────────────────────────────────────┘   │ [   Pagar con Mercado Pago →   ]  │ ← coral
                                         │ 🔒 El pago se hace en Mercado Pago.│
                                         │ FENIA nunca ve tu tarjeta.         │
                                         └────────────────────────────────────┘
```
Mobile (una columna): título → resumen → **caja "Comprar"** → "Qué incluye" → "Para quién es / no es" → formato.

- Los textos salen del `.md` de cada kit; no se escribe copy a mano en el componente.
- (*) El texto legal exacto lo define el abogado (Bloque 5). Sin ese texto no se lanza.
- Errores en la misma caja, en texto: "Los emails no coinciden", "Probá de nuevo en unos minutos" (límite de intentos).

## 3. `/gracias` (se rehace)

Hoy promete que Mercado Pago manda el link, y eso es falso (ADR-028). Pasa a tener estados según lo que confirma el servidor:

```
Verificando tu pago…              (spinner, mientras pregunta al servidor)

✅ ¡Listo! Tu kit está pago.
   [ Descargar el kit ]           ← coral
   También te lo mandamos a a***@gmail.com (revisá Spam o Promociones).
   El link dura 30 días y permite 5 descargas.

⏳ Tu pago todavía se está procesando.
   Te llega por mail apenas se acredite.

❓ No encontramos tu compra.
   Si pagaste, entrá a "No me llegó" con el email que usaste.
```
- Nunca muestra el botón de descarga solo por los parámetros de la URL (threat model A3, prueba S-06).
- Aviso fijo: "FENIA nunca te va a pedir datos de tarjeta por mail ni por WhatsApp" (threat model B4).

## 4. `/no-me-llego` (nueva)

```
¿No te llegó tu kit?
Escribí el email que usaste al comprar y te lo reenviamos.
[______________________________]  [ Reenviar ]

→ "Si ese email tiene una compra, en unos minutos te llega el link."  (misma respuesta siempre)
¿Sigue sin llegar? Escribinos a soporte@fenia.com.ar o por WhatsApp.
```
- La respuesta es idéntica haya o no compra: no revela quién compró (threat model A15).

## 5. Página de descarga (desde el mail)

```
Tu kit: Evaluar en tiempos de IA
[ Descargar ]                     ← coral; la descarga ocurre solo al tocarlo
Te quedan 4 de 5 descargas · vence el 06/12/2026
```
- Abrir el link no gasta descargas: los antivirus de mail y las vistas previas de WhatsApp no la consumen (threat model C6).

## Qué tiene que decidir el dev

1. ¿Aprobás la card, la página del kit, `/gracias`, "No me llegó" y la página de descarga tal como están? (Se pueden pedir cambios.)
2. ¿Cambiamos **ya** el botón de los kits "Próximamente" a "Avisame cuando esté disponible"?
