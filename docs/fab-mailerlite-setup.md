# Cómo armar la lista de lanzamiento en Mailerlite

> **Audiencia**: Fab (dueño no técnico de FENIA).
> **Objetivo**: que el botón "Quiero el recurso gratis" de los productos "Próximamente" capture emails de verdad y mande un recurso gratuito.
> **Tiempo estimado**: 20-30 minutos.
> **Mientras no hagas esto**: el botón sigue funcionando, pero manda a la persona a WhatsApp en vez de a un formulario — no se rompe nada, solo no está automatizado todavía.

---

## Paso 1 — Crear cuenta en Mailerlite

1. Entrá a [https://www.mailerlite.com/](https://www.mailerlite.com/) y creá una cuenta gratis (hasta 1.000 suscriptores sin costo).
2. Confirmá tu email.

## Paso 2 — Crear un grupo ("Lista de lanzamiento FENIA")

1. Dentro de Mailerlite, buscá **Subscribers → Groups** (o "Grupos").
2. Creá un grupo nuevo, por ejemplo `Lista de lanzamiento FENIA`.

## Paso 3 — Crear el formulario embebido

1. Buscá **Forms → Embedded forms** (o "Formularios → Formularios embebidos").
2. Creá uno nuevo, asociado al grupo del Paso 2.
3. Personalizá el texto si querés (ej. "Dejanos tu email y te avisamos apenas esté disponible").
4. Guardá y buscá la opción de **compartir/embeber** — Mailerlite te va a dar una URL tipo:
   ```
   https://assets.mailerlite.com/jsonp/XXXXXX/forms/YYYYYYY/view
   ```
   Esa URL es la que necesito — **copiala y pasámela**.

## Paso 4 — Armar la automation que entrega el recurso gratuito

1. Buscá **Automations → Create automation**.
2. Trigger: "Subscriber joins a group" → elegí el grupo del Paso 2.
3. Acción: **Send email** — redactá un mail corto de bienvenida con el link de descarga del recurso gratuito (podés usar un link de Google Drive con "cualquiera con el link puede ver").
4. Activá la automation.

> ⚠️ Si en algún momento tenemos más de un producto "Próximamente" con recursos gratuitos distintos, avisame — hay una forma de que el formulario sepa de qué producto vino la persona (le paso un dato extra en la URL) para que la automation mande el recurso correcto según el kit. Por ahora, mientras solo tengamos uno, no hace falta complicarlo.

## Paso 5 — Pasarme la URL

Una vez que tengas la URL del Paso 3, mandámela — yo la configuro como variable de entorno del sitio (`PUBLIC_MAILERLITE_FORM_URL`) y en unos minutos el formulario queda embebido en el sitio en vez del fallback de WhatsApp.

---

## Después de tener esto andando

- Vas a ver los emails que se suscriben en **Subscribers** dentro de Mailerlite.
- Podés exportar la lista cuando quieras para una campaña de lanzamiento del kit.
- Cuando el kit esté listo para vender, avisame para pasar el producto a `live` con el link de Mercado Pago (ver [`docs/fab-mercadopago-setup.md`](./fab-mercadopago-setup.md)) — ahí el botón cambia de "Quiero el recurso gratis" a "Comprar ahora".

¿Preguntas? Escribime y lo vemos juntos.
