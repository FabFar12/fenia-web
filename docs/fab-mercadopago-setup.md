# Cómo armar el cobro del primer producto en Mercado Pago

> **Audiencia**: Fab (dueño no técnico de FENIA).
> **Objetivo**: dejar el cobro de la *Guía para evaluar en tiempos de IA* listo para vender.
> **Tiempo estimado**: 20-30 minutos.
> **Lo que necesitás antes de empezar**: tu cuenta de Mercado Pago Vendedor (la misma que usás para la consultoría sirve), el PDF final de la guía en tu computadora, una imagen de portada del producto (opcional pero recomendado).

Cuando termines esto, mandale al dev (Matías) los 5 datos que se piden al final de la guía. Con esos datos, en 1-2 horas el producto pasa de "Próximamente" a "Comprar ahora" en el sitio.

---

## Paso 1 — Entrar a tu cuenta de Mercado Pago Vendedor

1. Abrí [https://www.mercadopago.com.ar/](https://www.mercadopago.com.ar/) y entrá con tu usuario y contraseña habituales (los mismos que usás para cobrar transferencias).
2. Si te aparece la opción "Comprador" vs "Vendedor", elegí **Vendedor**.
3. Una vez dentro, vas a ver tu balance, movimientos recientes, etc. Eso es la pantalla normal.

---

## Paso 2 — Crear el "Link de Pago" del producto

Mercado Pago tiene varias herramientas para cobrar. Para vender un PDF online, la mejor es **"Link de Pago"** (a veces aparece como "Cobros con link" o "Cobros Pro").

1. En el menú de Mercado Pago (arriba o lateral según la versión), buscá una sección llamada **"Cobros"** o **"Tu negocio"**.
2. Dentro de Cobros, buscá una opción tipo:
   - **"Crear link de pago"**, o
   - **"Vender productos digitales"**, o
   - **"Cobros con link"**.

   Si no la encontrás, ingresá directo a: [https://www.mercadopago.com.ar/herramientas-para-vender/link-pago](https://www.mercadopago.com.ar/herramientas-para-vender/link-pago)

3. Click en **"Crear link de pago"** o el botón equivalente.

---

## Paso 3 — Configurar los datos del producto

Te va a pedir varios datos. Llenalos así:

### Tipo de producto
- Elegí **"Producto digital"** o **"Archivo descargable"** si está esa opción.
- Si no aparece, elegí "Servicio" y en la descripción aclará que es un PDF descargable.

### Nombre del producto
```
Guía para evaluar en tiempos de IA
```
*(o el nombre final que vas a usar; este lo verá el comprador en el checkout y en el mail)*

### Descripción corta
Una o dos oraciones que el comprador ve antes de pagar. Ejemplo:
```
Guía práctica de FENIA para evaluar decisiones, equipos y proyectos en un contexto donde la inteligencia artificial cambia las reglas.
```

### Precio
Definí el precio en **pesos argentinos**. Tip importante: tené en cuenta que Mercado Pago se queda con ~6% de comisión + IVA. Si querés cobrar AR$ 10.000 netos, poné aproximadamente AR$ 10.700.

### Cuotas
Mercado Pago va a preguntar si querés ofrecer **cuotas sin interés**. Recomendación:
- Si el producto cuesta menos de AR$ 5.000: dejá pago único, sin cuotas.
- Si cuesta más de AR$ 10.000: ofrecé **3 cuotas sin interés** (vos asumís el costo financiero — vale la pena por la conversión).
- Si cuesta más de AR$ 30.000: ofrecé **6 cuotas sin interés**.

### Cantidad disponible
Si pregunta "stock" o "cantidad disponible", poné **"Sin límite"** o un número alto (ej. 9999). Es un archivo digital, no se agota.

### URL de retorno tras pago exitoso
**Importante**, este dato es lo que hace que el comprador vuelva al sitio después de pagar:
```
https://fenia.com.ar/gracias
```
*(esta página ya está creada en el sitio y muestra un mensaje de confirmación)*

### URL de retorno tras pago pendiente o rechazado
Mismo valor, o si querés diferenciarla:
```
https://fenia.com.ar/
```

---

## Paso 4 — Subir el PDF y configurar entrega automática

Acá viene la parte clave: que Mercado Pago entregue el PDF al comprador **sin que vos tengas que hacer nada manual**.

1. Buscá la opción **"Archivos digitales"**, **"Entrega automática"** o **"Producto descargable"** dentro de la configuración del producto.
2. Subí el PDF de la guía (asegurate que sea la **versión final**, sin "borrador" en el nombre).
3. Mercado Pago, después del pago, le manda un email al comprador con un link único para descargar.

> ⚠️ **Si tu plan de Mercado Pago no incluye entrega automática de archivos**, tenés dos opciones:
> - Activar Mercado Pago Pro o Empresas (planes pagos).
> - **Alternativa más simple**: subir el PDF a tu Google Drive como "compartir con cualquiera que tenga el link", copiar ese link, y configurar Mercado Pago para que el mail post-compra incluya ese link en el cuerpo del mensaje. Este flujo es menos seguro (cualquiera con el link puede descargar) pero funcional.
>
> Si vas por la opción Google Drive, avisale al dev — tenemos que ajustar la copy del flujo.

---

## Paso 5 — Guardar y copiar el link

1. Al terminar, Mercado Pago te muestra una pantalla de confirmación con el **link público del cobro**.
2. El link tiene el formato:
   ```
   https://link.mercadopago.com.ar/abc123xyz
   ```
   o también puede ser:
   ```
   https://mpago.la/abc123
   ```
3. **Copialo y guardalo** — este es el dato más importante. Pegáselo al dev tal cual.

---

## Paso 6 — (Opcional pero recomendado) Probar el flujo vos mismo

Antes de pasarle el link al dev, andá vos mismo desde otra máquina o desde el celular en datos móviles, abrí el link, y simulá una compra **a vos mismo con otra tarjeta** o con saldo Mercado Pago. Esto valida que:

- El precio aparece bien.
- El nombre y descripción son los correctos.
- Tras pagar, te redirige a `https://fenia.com.ar/gracias`.
- Recibís el email con el PDF.

Si todo funciona, podés cancelar el pago / pedir reembolso desde el panel de Mercado Pago — no vas a perder plata, solo era un test.

---

## Paso 7 — Pasarle al dev los 5 datos finales

Una vez tengas todo, mandale al dev (en mensaje único, copia los 5 datos):

```
1. Link de Mercado Pago: https://link.mercadopago.com.ar/...

2. Título oficial del producto:
   <copiá el título exacto>

3. Descripción larga (1-2 párrafos, lo que va a ver el visitante del sitio):
   <pegá el texto>

4. Precio en pesos: AR$ ____

5. Imagen de portada (1200x630 px ideal):
   <adjuntá la imagen, o decí "usar ícono genérico" si todavía no tenés>
```

Con esos 5 datos, en 1-2 horas el dev:

1. Edita el archivo del producto en el repositorio para que `status: live`.
2. Reemplaza el botón "Avisame cuando esté disponible" por "Comprar ahora →" apuntando a tu link.
3. Hace push y el sitio se actualiza automáticamente.
4. Te pide que hagas una compra de prueba **completa** (con cobro real, después se devuelve).

---

## Errores comunes y cómo evitarlos

| Síntoma | Causa probable | Solución |
|---|---|---|
| El link de pago abre una página vacía o con error | Probablemente el link es de "preview" y todavía no se publicó. Volvé al panel y confirmá que el cobro esté **activo / publicado** | Click en "Activar" o "Publicar" en el detalle del cobro |
| El precio aparece distinto al que cargaste | Mercado Pago a veces hace conversiones automáticas por moneda. Asegurate que esté en ARS | Editar y forzar moneda ARS |
| No me llega el email con el PDF al hacer la prueba | El plan de MP no incluye entrega automática, o la dirección de email del comprador es la misma de la cuenta vendedora | Probar con un email distinto al del vendedor. Si persiste, usar el plan Google Drive del Paso 4 |
| El comprador no vuelve al sitio después de pagar | Te olvidaste de poner la URL de retorno, o pusiste mal | Editar y poner exactamente `https://fenia.com.ar/gracias` |

---

## Lo que el sitio ya tiene preparado para esto

Te dejo como referencia, así el dev no tenga que explicártelo de cero:

- **La tarjeta del producto** en la sección "Productos digitales" ya existe — hoy aparece como "Próximamente" con un botón que linkea a WhatsApp.
- **La página `https://fenia.com.ar/gracias`** ya existe — es donde aterriza el comprador después de pagar. Tiene mensaje de confirmación + WhatsApp de soporte por si el email no llega.
- **El email de Mercado Pago** se manda automáticamente desde el lado de MP — el sitio no se entera ni necesita configurar nada.
- **El comprobante AFIP** lo emite Mercado Pago automáticamente y queda en tu panel — vos no hacés nada extra.

---

## Después de la primera venta

Cuando vendas el primer producto:

1. Mercado Pago te avisa por email.
2. La plata aparece en tu balance de MP, lista para transferir o gastar.
3. El comprador recibe el PDF en su mail (vos no tenés que hacer nada).
4. Si el comprador te escribe por WhatsApp con dudas, podés responderle ya con contexto: vendiste 1 producto, sabés cuál era, sabés a qué hora.

Cuando vendamos ~10 productos, conviene revisar:
- ¿Conviene activar Plausible (analytics) para ver de dónde vienen las ventas? (decisión técnica del dev).
- ¿Hace falta una página de "preguntas frecuentes" o testimonios de compradores reales? (decisión tuya).
- ¿Es momento de armar un segundo producto? (decisión tuya).

---

¿Preguntas? Escribile al dev y avanzamos.
