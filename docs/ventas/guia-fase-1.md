# Fase 1 — Tu paso a paso (lo que no puede hacer el dev)

> Para quien tenga acceso al hPanel de Hostinger y a la cuenta de Mercado Pago de Fab. Parte del [plan de venta de kits](./README.md).
> Tiempo total estimado: 1 a 1,5 horas, en bloques independientes. Los bloques 1, 2 y 3 destraban las pruebas; el 4 y el 5 hacen falta recién para lanzar.
> Última actualización: 2026-11-06.

## Antes de empezar: la regla de las claves

Varias cosas de esta guía generan **claves secretas**: contraseñas, el *Access Token* de Mercado Pago, la clave secreta de webhooks. El *Access Token de producción* es la llave de la cuenta de Mercado Pago de Fab: con él se pueden ver ventas y hacer reembolsos.

- **Guardalas en un gestor de contraseñas** (Bitwarden es gratis; también sirve el de Google o el de Apple).
- **Nunca** las mandes por WhatsApp, mail o chat (tampoco a la IA), ni en capturas de pantalla, ni las pegues en un archivo del proyecto.
- Cuando el servidor esté listo, te voy a dar un archivo modelo para que **vos** las pegues directamente en Hostinger. Así no pasan por ningún otro lado.

Al final de la guía está la lista de lo que **sí** me pasás: nombres, rutas y "listo", nunca claves.

## Camino elegido: lo configura el dev por API (D11)

Para evitar errores en un panel que no conocés, el dev configura Hostinger por su API. Vos solo das **dos permisos**. Cada clave va a un archivo de tu compu, nunca al chat. Los archivos ya están creados en `%USERPROFILE%\fenia-secrets\`, tu carpeta de usuario, fuera del repo.

- [x] **P1 — Token de Hostinger (3 min)**. ✅ Hecho 2026-11-06 (vence en 3 meses). Con él ya se crearon la base de staging, el subdominio con SSL y la casilla `kits@`. Los Bloques 1 y 3 manuales de abajo **ya no hacen falta**.
  1. Entrá a [hpanel.hostinger.com/api](https://hpanel.hostinger.com/api). En el menú también figura como *Herramientas de desarrollo → API*.
  2. *Nuevo token* → nombre `claude-fenia-setup` → vencimiento **7 días** → *Generar*.
  3. Copiá el token: se muestra una sola vez.
  4. En una terminal, `notepad $HOME\fenia-secrets\hostinger-token.txt` → pegalo → **Guardar** → cerrá.
- [ ] **P2 — Mercado Pago: aplicación + token de prueba (5 min)**

  > **Desde noviembre de 2025 el flujo es más corto:** al crear la aplicación, Mercado Pago entrega solo las credenciales de prueba. **No hace falta** crear cuentas de prueba ni activar producción para empezar ([novedad oficial](https://www.mercadopago.com.mx/developers/es/news/2025/11/19/Streamlined-integration-testing-with-automatic-credentials)).

  1. Entrá a **https://www.mercadopago.com.ar/developers/panel/app** con la cuenta de Fab, la que cobra.
  2. Tocá **Crear aplicación** (arriba a la derecha) y completá el asistente:
     - **nombre:** `FENIA Kits`;
     - **cómo vas a integrar:** desarrollo propio (no plataforma);
     - **tipo de pago:** pagos online;
     - **producto**, si lo pregunta: *Checkout Pro*.

     Aceptá y confirmá.
  3. Te abre la aplicación en **Inicio**. Arriba hay un selector **Prueba / Producción**: dejalo en **Prueba**.
  4. En el bloque de credenciales, o en el menú izquierdo → **Credenciales**, copiá el **Access Token** (empieza con `APP_USR-`) con el **ícono de copiar**.
  5. Avisale al dev "copiado": lo toma del portapapeles y lo valida contra Mercado Pago con una consulta de solo lectura. Si es el de **producción** en vez del de prueba, no lo guarda.

  **Si te trabás:** decile al dev qué texto ves en pantalla.
- [ ] **P3 — Avisale al dev "listo".**

**Qué hace el dev con esos permisos:**
- leer el plan y la versión de PHP;
- crear la base de datos de staging, con una contraseña que genera él y no se muestra;
- crear el subdominio `staging` y su certificado;
- crear la casilla `kits@` (D10);
- más adelante, las tareas programadas.

**Lo que nunca toca:** DNS, dominio, facturación, ni borra nada. Cada llamada a la API queda a la vista en la sesión.

**Al terminar** te avisa: borrás el token en la misma página (*Eliminar*) y el dev borra el archivo.

> **Lo que la API no puede hacer:** crear cuentas FTP. Por eso el deploy de staging reutiliza el usuario FTP del sitio real, con una barrera en la integración continua que solo le permite escribir en la carpeta `staging/` (D12).

Los Bloques 1 y 3 de abajo quedan como **respaldo manual**, por si algo de la API falla. Del Bloque 2, por ahora solo hace falta P2. Las cuentas de prueba y la activación de producción se hacen más adelante, juntos.

---

## Bloque 1 — Hostinger (≈ 20 min)

> **GitHub para producción ya está listo** (verificado 2026-11-06): GitHub tiene las 5 claves FTP de producción y los últimos deploys a `main` salieron bien. En GitHub solo faltan 3 claves de **staging**, que se cargan en el paso 1.6 porque necesitan el usuario FTP del 1.5.
>
> **Para verificarlo vos:**
> 1. En una terminal: `gh secret list --repo FabFar12/fenia-web`. Tienen que aparecer 5 nombres que empiezan con `HOSTINGER_FTP_`.
> 2. En [github.com/FabFar12/fenia-web/actions](https://github.com/FabFar12/fenia-web/actions): el último "CI + Deploy" de `main` tiene que estar en verde.

Entrá a [hpanel.hostinger.com](https://hpanel.hostinger.com) → **Sitios web** → `fenia.com.ar` → **Administrar**. Todo lo de abajo está en el menú de la izquierda de esa pantalla. Los nombres pueden variar un poco: si no encontrás algo, usá el buscador del menú.

- [ ] **1.1 Mirar y anotar** (2 min, no cambies nada):
  - el nombre del plan (figura en el panel principal: Premium, Business…);
  - *Avanzado → Configuración de PHP*: la versión actual;
  - *Avanzado → Cron Jobs*: ¿la sección existe?
- [ ] **1.2 Base de datos** (3 min). *Bases de datos → Bases de datos MySQL* → crear:
  - nombre `fenia_staging`;
  - usuario `fenia_staging`;
  - contraseña: usá el generador (mínimo 8 caracteres, con mayúscula, minúscula y número) y **guardala en el gestor**.

  Anotá el nombre y el usuario **completos**: Hostinger les agrega un prefijo tipo `u123456789_`.
- [ ] **1.3 Subdominio** (3 min). *Dominios → Subdominios* → escribí `staging` → **no** tildes "carpeta personalizada" → *Crear*.
  - Anotá la carpeta que muestra. Termina en `public_html/staging`.
  - En *Seguridad → SSL*: si `staging.fenia.com.ar` no figura activo, tocá *Instalar*. Puede tardar unos minutos.
- [ ] **1.4 Carpeta privada** (2 min). *Archivos → Administrador de archivos*:
  1. Tocá la **casita (⌂)** al principio de la ruta, arriba. Te lleva a la raíz de la cuenta, donde se ve la carpeta `domains`.
  2. Ahí, al lado de `domains`, creá la carpeta `fenia-private`, y adentro otra llamada `staging`.
  3. Anotá la ruta completa de `fenia-private`, algo como `/home/u123456789/fenia-private`.

  ⚠️ Nunca adentro de `public_html`: quedaría visible en internet.
- [ ] **1.5 Usuario FTP de staging** (3 min). *Archivos → Cuentas FTP* → crear:
  - usuario `staging-deploy`;
  - contraseña fuerte, guardada en el gestor;
  - directorio: la carpeta del subdominio del paso 1.3, la que termina en `public_html/staging`.

  Anotá el usuario **completo**, tal como aparece en la lista.
- [ ] **1.6 Claves de staging en GitHub** (2 min). En una terminal **tuya**, no en el chat. Cada comando pide el valor y no lo muestra en pantalla:
  ```
  gh secret set HOSTINGER_STAGING_FTP_USER --repo FabFar12/fenia-web
  gh secret set HOSTINGER_STAGING_FTP_PASSWORD --repo FabFar12/fenia-web
  gh secret set HOSTINGER_STAGING_FTP_REMOTE_DIR --repo FabFar12/fenia-web
  ```
  Los valores son, en orden: el usuario completo del 1.5, su contraseña y `./`.

  Verificá con `gh secret list --repo FabFar12/fenia-web`: ahora tienen que aparecer 8 nombres. Tu usuario de GitHub tiene permiso para esto (verificado).

> **¿Por qué no con la API de Hostinger?** La API puede crear la base y el subdominio, pero el token que genera tiene **acceso total** a la cuenta de Fab, incluidos el dominio y el DNS del mail, y no se puede limitar. Además, la API no crea cuentas FTP ni la carpeta privada: igual habría que entrar al panel. Para 15 minutos de clics, no vale el riesgo.

## Bloque 2 — Mercado Pago (≈ 20 min)

Con la cuenta de Mercado Pago de Fab, la que cobra. Cada paso está verificado contra la documentación oficial (enlaces al final del bloque).

> **Los Links de Pago que ya existen no son este bloque.** Hay tres activos (verificado 2026-11-06): "Evaluar con IA - Ebook", "Aprender con IA - Ebook" y "Educar con IA - Ebook". Cobran, pero no le avisan a nuestro sistema quién pagó ni pueden atar el pago a una orden, así que no sirven para entregar solos. Este bloque crea otra cosa: una **aplicación**, que le da al servidor permiso para crear cobros y verificar pagos. **No publiques esos links en ningún lado**, tampoco en el repo: quien pague por ahí no recibe nada.

- [ ] **2.1** Entrá a [Tus integraciones](https://www.mercadopago.com.ar/developers/panel/app) → **Crear aplicación**.
- [ ] **2.2** Completá el asistente:
  - **nombre:** `FENIA Kits`;
  - **tipo de integración:** desarrollo propio (no una plataforma de e-commerce);
  - **tipo de pago:** online;
  - **producto:** si te lo pregunta, *Checkout Pro*.

  Confirmá.
- [ ] **2.3 Credenciales de prueba.** En la aplicación → *Credenciales*, con el selector de arriba en **Prueba**. Copiá el **Access Token** al gestor, con el nombre "MP prueba — Access Token". La *Public Key* no la necesitamos.
- [ ] **2.4 Cuentas de prueba.** En la aplicación → *Cuentas de prueba* → **Crear cuenta de prueba**, dos veces:

  | | País | Identificador | Tipo | Saldo ficticio |
  |---|---|---|---|---|
  | 1 | Argentina | `Vendedor FENIA test` | Vendedor | el que ofrezca |
  | 2 | Argentina | `Comprador FENIA test` | Comprador | AR$ 100.000 |

  Guardá en el gestor el **usuario**, la **contraseña** y el **código de verificación** de cada una (están en el detalle de la cuenta).
- [ ] **2.5 Activar producción** (se puede hacer ahora). Selector arriba en **Producción** → *Activar ambiente de producción*:
  - rubro: el más cercano a educación o capacitación;
  - sitio web: `https://fenia.com.ar`;
  - aceptar los términos → *Activar*.

  **No copies todavía el Access Token de producción:** te lo pido recién para el lanzamiento.
- [ ] **2.6 Webhooks: todavía no.** Se configuran cuando el servidor de pruebas esté andando. Te aviso y lo hacemos juntos.
- [ ] **2.7 Los Links de Pago que ya existen.** Si **no** los están usando para vender a mano, desactivalos o eliminalos desde la sección *Link de pago* de Mercado Pago. Si alguien paga por uno de esos links, el sistema no se entera y no le llega nada. Si los están usando, avisame y lo contemplamos.

Documentación oficial: [crear aplicación](https://www.mercadopago.com.ar/developers/es/docs/checkout-api-orders/create-application) · [credenciales](https://www.mercadopago.com.ar/developers/es/docs/your-integrations/credentials) · [cuentas de prueba](https://www.mercadopago.com.ar/developers/es/docs/your-integrations/test/accounts)

## Bloque 3 — Mail de entrega (≈ 10 min)

Decidido: los compradores **ven y responden a `soporte@fenia.com.ar`**.

- [ ] **3.1 Elegí cómo se envían los mails automáticos:**
  - **Recomendado:** crear una casilla solo para enviar, `kits@fenia.com.ar` (hPanel → *Correos*), con contraseña fuerte guardada en el gestor. Los mails salen como "FENIA" desde `kits@` y las respuestas van a `soporte@`. Así la contraseña de `soporte@`, donde está toda la atención a clientes, nunca se guarda en el servidor. Y si algún día cambian esa contraseña, las entregas no se cortan.
  - **Alternativa:** enviar directo desde `soporte@`. Funciona, pero su contraseña pasa a vivir en el servidor.
- [ ] **3.2** Desde el webmail de la casilla que envía, mandá un mail de prueba a un Gmail personal. Anotá si llegó a la **bandeja de entrada** o a **spam**.
- [ ] **3.3 No toques el DNS del mail.** Hoy el registro DMARC está en modo "solo observar" (`p=none`, hallazgo H-02). Lo ajustamos juntos más adelante: tocar el DNS del mail sin cuidado lo rompe.

## Bloque 4 — Los ZIP de los kits (al final, antes de las pruebas finales)

**Se hace al final**, después de la revisión final del material (decisión D9). Mientras tanto, staging funciona con ZIPs de prueba que armo yo. En esa revisión final confirmamos también:
- el **nombre** de cada kit (el link viejo dice "Educar con IA" y el sitio dice "Enseñar con IA sin automatizar la pedagogía");
- el **precio final**. Lo que se cobra sale del sitio: hoy AR$ 8.000 por kit.

- [ ] **4.1** Un ZIP por kit con **solo lo que recibe el comprador**: PDF, DOCX, plantillas, imprimibles, prompts, fuentes. **Sacá todo lo interno**: carpetas como `Ventas/` o `Assets/`, borradores, mockups, copys de venta.
- [ ] **4.2** Nombres exactos:
  - `guia-evaluar-tiempos-ia.zip` (*Evaluar con IA sin vigilar*);
  - `kit-ensenar-con-ia.zip` (*Enseñar con IA sin automatizar la pedagogía*);
  - `kit-aprender-con-ia.zip` (*Aprender con IA sin dejar de pensar*).
- [ ] **4.3** Guardalos en `C:\Fenia\entregables\`.

  ⚠️ **Nunca** dentro de `C:\Fenia\fenia-web\`: el repositorio es **público** en GitHub, y un ZIP ahí adentro es el kit regalado a cualquiera. Igual dejé una protección para que git ignore los `.zip`.
- [ ] **4.4** Avisame cuando estén. Reviso que no se haya colado nada interno y calculo las huellas de verificación (SHA-256).

## Bloque 5 — Contador, abogado y una decisión (para el lanzamiento)

Esto no bloquea el desarrollo, pero sí el lanzamiento. Preguntas concretas para llevar:

**Contador**
- [ ] ¿Cómo facturamos cada venta de AR$ 8.000 a consumidor final: a mano en ARCA, o con un servicio que se conecta a Mercado Pago y factura solo?
- [ ] ¿La categoría de monotributo de Fab aguanta este ingreso extra?
- [ ] ¿Hace falta mostrar algo en el sitio por vender online (por ejemplo, el QR de Data Fiscal de ARCA)?

**Abogado**
- [ ] Términos y condiciones para venta de contenido digital descargable. Los descargables están exceptuados del derecho de arrepentimiento (art. 1116 del Código Civil y Comercial): ¿cómo lo informamos antes de la compra?
- [ ] Política de privacidad (Ley 25.326). ¿Hay que inscribir la base de compradores en el registro de bases de datos de la AAIP?
- [ ] Botón de arrepentimiento (Disposición 954/2025): ¿hay que mostrarlo igual, aunque los kits estén exceptuados?

**Decisión de Fab**
- [ ] ¿Ofrecemos garantía de devolución de 7 días, como sugiere el copy de dos kits? Si es sí, el abogado la redacta.

---

## Qué me pasás (sin ninguna clave)

Copiá esto, completalo y mandámelo:

```
1.1 Plan: ____ · PHP actual: ____ · Cron Jobs existe: sí / no
1.2 Base de staging: ____ / usuario: ____   (sin contraseña)
1.3 Carpeta del subdominio staging: ____ / SSL activo: sí / no
1.4 Ruta completa de fenia-private: ____
1.5–1.6 Usuario FTP de staging: ____ · gh secret list muestra 8 nombres: sí / no
2.x App "FENIA Kits" creada: sí / no · Access Token de prueba guardado: sí / no
    2 cuentas de prueba creadas: sí / no · Producción activada: sí / no
    Links de Pago viejos: desactivados / se usan para ____
3.x Envío desde: kits@ (recomendado) / soporte@ · Mail de prueba llegó a: bandeja / spam
4.x (al final) ZIPs en C:\Fenia\entregables + nombres y precio confirmados
5.x Respuestas de contador / abogado / garantía: cuando las tengas
```

## Qué NO me pasás nunca

Contraseñas, Access Tokens (de prueba o de producción), la clave secreta de webhooks ni los usuarios y contraseñas de las cuentas de prueba. Si alguno se filtra por error, avisame: se regeneran en minutos.
