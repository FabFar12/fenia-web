---
title: "Guía para evaluar en tiempos de IA"
type: "Guía"
status: "coming-soon"
accent: "cyan"
summary: "Marco práctico para evaluar decisiones, equipos y proyectos en un contexto donde la inteligencia artificial cambia las reglas. Para profesionales que necesitan criterios claros."
price: null
cta:
  label: "Avisame cuando esté disponible"
  href: "https://wa.me/5493513559947?text=Hola%20FENIA%2C%20quiero%20saber%20cuando%20est%C3%A9%20disponible%20la%20Gu%C3%ADa%20para%20evaluar%20en%20tiempos%20de%20IA"
audiences:
  - profesionales
  - empresas
  - emprendedores
publishedAt: 2026-05-26
---

> **Estado**: producto real, primer infoproducto de FENIA. Status `coming-soon` hasta que el link de Mercado Pago esté configurado. Plan documentado en [ADR-025](../../../docs/adr/ADR-025-product-sales-via-mercadopago.md).
>
> **Datos pendientes de Fab antes de activar (`status: live`)** — ver [PENDING.md](../../../PENDING.md):
> - Título oficial confirmado (¿este nombre es definitivo?)
> - Subtítulo / descripción larga (1-2 párrafos)
> - Precio en pesos argentinos
> - Link de cobro de Mercado Pago (`https://link.mercadopago.com.ar/...`)
> - Foto de portada o ícono específico
> - Confirmación de qué públicos atacar (hoy: los tres)

Cuando Fab pase los datos, esta guía pasa a `status: live`, el `cta.label` cambia a "Comprar ahora", el `cta.href` apunta al link de MP, y `price` se llena con el número en ARS. El componente `Productos.astro` ya maneja `live` correctamente sin tocar el template.
