# Conectar un frontend con el framework — guía para agentes

> **Lee primero `AGENTS.md §1.1`**: el framework es backend + arquitectura (WordPress headless
> + el BFF de Next.js en `app/api/*`/`lib/*`). La UI — `components/**` (salvo `ui/`) y todas
> las vistas de `app/[locale]/*` — **no es núcleo del framework**: se hereda una vez al clonar
> y desde ahí es responsabilidad de cada instancia. El framework no la actualiza ni promete
> que se mantenga genérica.

Este documento tiene dos partes con garantías muy distintas:

- **Parte A — El contrato backend/BFF.** Esto es lo único que el framework promete mantener
  estable. Constrúyelo sobre esto tanto si usas la UI heredada como si escribes un frontend
  desde cero (otro framework, otro repo, lo que sea).
- **Parte B — Lo heredado al clonar.** Inventario de la UI que trae el repo hoy: qué existe,
  qué está conectado, qué es un building block sin usar. Es una foto del momento en que se
  escribió esto — no hay promesa de que el framework la actualice. Trátalo como el punto de
  partida de TU proyecto, no como una librería externa.

> **Smoke test del contrato:** `examples/minimal-consumer/` (script Node sin dependencias)
> ejercita la Parte A de punta a punta contra una pila levantada — útil para verificar una
> integración nueva o una actualización del framework.

---

# Parte A — El contrato backend/BFF (estable, mantenido por el framework)

## A.1 Lectura de catálogo/contenido: WPGraphQL + WooGraphQL

- Endpoint: `${WORDPRESS_INTERNAL_API_URL}` (servidor, red interna Docker). **El navegador no
  habla con WordPress**: no hay URL pública de GraphQL; todo pasa por el BFF (`/api/*`).
- **Secreto interno.** WordPress solo atiende a GraphQL, la Store API (`wc/store`) y
  `hwe/v1/auth|consent` si la petición trae `X-HWE-Internal-Secret` con el valor de
  `HWE_REVALIDATION_SECRET` (comparación en tiempo constante), o viene de WP-CLI; cualquier otra
  recibe un **404**, llegue por la ruta o por parámetro (`?graphql`, `?rest_route=`). Un
  consumidor propio del lado servidor (un BFF distinto del de este repo, un script) debe enviarlo
  en todas sus llamadas; el secreto no debe salir nunca a un navegador. `wc/v3` sigue
  autenticándose con `ck/cs`.
- Queries de catálogo/posts son **públicas** (sin auth) — WooGraphQL para productos/
  categorías/variaciones, WPGraphQL nativo para posts. CORS restringido por
  `HEADLESS_ALLOWED_ORIGINS` (mu-plugin `headless-config.php`).
- Queries "como el usuario autenticado" (p. ej. reseñas propias) llevan
  `Authorization: Bearer <accessToken>`.
- **Introspección desactivada y límites de profundidad/complejidad activos**
  (`graphql-protection.php`) — no asumas que puedes introspectar el schema en producción;
  hazlo contra un WordPress local con la misma versión de plugins (ver
  `docs/COMPATIBILITY.md`) o lee las queries ya escritas en `lib/woocommerce/queries.ts` y
  `lib/queries.ts` como referencia del schema real en uso.
- Caché: ISR con `revalidateTag`, invalidado on-demand por webhook de WooCommerce contra
  `POST /api/revalidate` (firma HMAC, `lib/security/webhook.ts`).
- **Campos propios en el schema GraphQL** (mu-plugins del núcleo):
  - `Post`, `Page`, `Product`, `ProductCategory` → `seoTitle`, `seoDescription` (null = sin
    override; usa el título/resumen nativos), `seoNoindex` (boolean: `noindex, follow` y fuera
    del sitemap/`llms.txt`) y `seoCanonical` (URL absoluta de la versión principal; null = la
    propia). El frontend los aplica con `lib/seo/entity-index.ts`.
  - `Product.hweGtin`: GTIN del campo nativo de WooCommerce (9.2+), solo con 8/12/13/14
    dígitos; null si no hay o no es válido.
  - `MediaItem.isReferenceImage` (boolean): foto genérica compartida por varios productos;
    la ficha la etiqueta como «Imagen de referencia».
  - `RootQuery.hweFeaturedPost`: entrada destacada elegida a mano (null si no hay).
- **Traducciones de contenido** (mu-plugin `hwe-content-i18n`, solo con idiomas secundarios en
  `i18n.locales`; con un solo idioma no existen):
  - `translation(locale: "en")` en `Post`, `Page`, `Product`, `ProductCategory`, `Category` y
    `Tag`. Devuelve un objeto `Hwe<Tipo>Translation` cuyos campos son los traducibles del
    tipo (`Post`: `title`, `excerpt`, `content`, `seoTitle`, `seoDescription`, `slug`; `Page`:
    sin `excerpt` ni `slug`; `Product`: `name`, `shortDescription`, `description`, SEO y
    `slug`; `ProductCategory`: `name`, `description`, SEO y `slug`; `Category`: `name` y
    `description`; `Tag`: `name`). Cada campo es **null** si esa ficha no tiene traducción: el
    cliente cae al texto nativo. El propio `translation` es null si `locale` no es uno de los
    idiomas secundarios (el idioma por defecto es el contenido nativo).
  - `hweSlugFrom(postType: "post"|"product"|"product_cat", locale: "en", slug: "…")` → slug
    **nativo** de la ficha publicada (o de la categoría de producto, si `postType` es
    `product_cat`) cuyo slug en ese idioma es `slug` (null si ninguna). Es la búsqueda inversa
    de `/<idioma>/…/<slug>`; el slug nativo sigue siendo la identidad (carrito, wishlist,
    overrides). Las categorías y etiquetas del blog no traducen su slug.
  - **En el frontend** (`lib/i18n/`): las queries llevan marcadores `# @translation <Tipo>[: campos]`
    que `fetchLocalizedGraphQL` sustituye por `translation(locale)` de los idiomas que hagan falta
    (ninguno en el idioma por defecto) y aplana a `<campo>_<idioma>`; `pickTranslated` /
    `pickLocalized` resuelven al idioma con caída al texto nativo. Las fichas (`/blog/[slug]`,
    `/products/[slug]`, `/categories/[slug]`) aceptan el slug de **cualquier** idioma y redirigen con
    308 al del idioma de la página (`resolveEntityRoute`). Un idioma sin traducción no se anuncia:
    ni hreflang ni fila de sitemap. Los enlaces usan `productHref` / `categoryHref` /
    `blogPostHref` (tramo `urlSlug` ya resuelto).
  - **El BFF recibe el idioma** en `X-HWE-Locale` (lo manda el navegador con el `lang` del
    documento; un valor que no sea de `routing.locales` cae al idioma por defecto): el carrito
    devuelve los nombres de producto traducidos. `GET /api/store/products` y `/api/blog/posts`
    aceptan `?locale=`.
  - El idioma `pt-BR` se escribe así en el argumento (`locale: "pt-BR"`); en las metas es
    `_hwe_<campo>_pt_br`. Las claves `_hwe_title_en`, `_hwe_slug_en`… de instalaciones previas
    valen tal cual.
  - Una instancia declara campos de su propio tipo con el filtro `hwe_translatable_fields` y
    aparecen en la caja, en GraphQL (`Hwe<Tipo>Translation`) y en la búsqueda sin más código.
- **Endpoints internos de WordPress** (solo con `X-HWE-Internal-Secret`, nunca desde el
  navegador): `GET hwe/v1/best-sellers` (`{ items: [{ id, slug, qty }] }`, de las líneas de
  pedidos pagados), `POST hwe/v1/contact`, `GET hwe/v1/payments/credentials`,
  `GET hwe/v1/integrations/<id>`.

## A.2 Escritura y datos privados: el BFF (`app/api/*`)

**Regla de oro: el navegador nunca habla directo con WordPress/WooCommerce para nada
sensible.** Todo pasa por estos Route Handlers, que guardan los secretos (`ck/cs` de
WooCommerce, secreto JWT, secretos de webhook) en el servidor.

| Grupo | Rutas | Qué hace |
|---|---|---|
| Auth | `POST /api/auth/{login,register,refresh,logout,logout-all}`, `GET /api/auth/me`, `POST /api/auth/{forgot-password,reset-password,verify-email,resend-verification,change-password}`, `POST /api/auth/2fa/{setup,activate,disable,status}`, `POST /api/auth/verify-2fa` | Sesión JWT en cookies httpOnly, 2FA TOTP opcional, verificación de email opcional no bloqueante. La cookie de sesión solo acepta el **access token**: un refresh token presentado en ella se rechaza (el refresh token solo se canjea en `refresh` y en el refresco transparente del proxy). `register`, `forgot-password` y `resend-verification` aceptan `locale` (uno de `routing.locales`): el correo y su enlace salen en ese idioma. `verify-email` no exige sesión (el token identifica al usuario). `change-password` cambia la contraseña con `POST hwe/v1/auth/change-password` (solo BFF y con sesión; WordPress verifica la actual y bloquea 15 min tras 5 fallos), cierra las demás sesiones y reemite la de este dispositivo. `reset-password` también cierra todas las sesiones anteriores del usuario (rota su secreto JWT y destruye las sesiones de WordPress): los refresh tokens emitidos antes del reset dejan de valer. Con `auth.phone_login_enabled`, `login` acepta el teléfono como usuario y `register` un `phone` E.164 opcional (409 si ya es de otra cuenta). |
| Tienda | `GET/POST/DELETE /api/store/cart` (el `DELETE` vacía el carrito y devuelve el carrito vacío), `POST /api/store/cart/items`, `POST /api/store/checkout`, `GET/PUT /api/store/customer`, `GET/POST/DELETE /api/store/coupons`, `GET /api/store/orders/[id]`, `GET /api/store/products`, `GET/POST/DELETE /api/store/wishlist`, `GET/POST /api/store/reviews/[productId]`, `GET/PUT /api/store/addresses`, `GET /api/store/shipping` | Proxy autorizado a la Store API / WC REST v3 de WooCommerce. |
| Fidelización | `GET /api/loyalty/{summary,transactions,referrals,referral-link,validate-code}`, `POST /api/loyalty/transfer`, `POST/DELETE /api/store/cart/points` | Módulo `loyalty`: monedero y referidos. Ver «Fidelización» más abajo. |
| Pagos | `POST /api/payments/create`, `POST /api/payments/webhook/[provider]`, `GET /api/payments/return` | Capa agnóstica de pasarela (`lib/payments/`). **La prueba de pago es el webhook verificado, nunca el `return`.** El `returnUrl` opcional de `create` debe ser del propio sitio (origen = `ALLOWED_ORIGIN`; si no, 422). |
| Webhooks de Woo | `POST /api/webhooks/woocommerce/{order-created,order-updated}` | Firma HMAC verificada, dispara efectos (`lib/woocommerce/order-events.ts`). |
| Utilidad | `GET /api/csrf`, `GET /api/health` (+`/live`), `POST /api/contact`, `GET /api/og`, `GET /api/icon`, `POST /api/revalidate` | Token CSRF, sondas de salud, formulario de contacto (se entrega por email desde WordPress; con sesión, la identidad sale de la cuenta), imágenes OG/icon dinámicas, revalidación on-demand. |

**Contrato de auth/CSRF** (aplica a cualquier frontend que consuma este BFF):
1. Sesión en **cookies httpOnly** (nunca `localStorage` ni body) — el frontend no maneja el
   JWT directamente, solo hace `fetch` con `credentials: "include"`.
2. Toda escritura (`POST`/`PUT`/`PATCH`/`DELETE`) exige **primero** `GET /api/csrf` y reenviar
   el token en la cabecera `X-CSRF-Token`. Sin esto, `guardMutation` (`lib/api/guard.ts`)
   rechaza la petición (también verifica `Origin` y aplica rate-limit).
3. Los importes de la Store API vienen en **unidades menores** (céntimos); los de WooGraphQL
   ya vienen formateados. No los mezcles sin convertir.
4. Un cuerpo inválido responde **422** con `{ error, details }`, donde `details` mapea cada
   campo a sus errores (`{ productId: ["…"] }`); un JSON ilegible responde 400. Las claves que el
   schema de la ruta no declara se ignoran (salvo en `PUT /api/store/customer`, que es
   estricto). Así, `POST /api/store/reviews/[productId]` solo lee `rating` y `content`: el nombre
   y el email del autor salen **siempre** de la cuenta autenticada, nunca del cuerpo.
5. Todo cuerpo tiene un **tope de bytes**: **64 KiB** para los JSON de escritura y **256 KiB**
   para los webhooks firmados (`lib/api/body.ts`). Al superarlo se responde **413**
   `{ error }` sin leer el resto; un frontend que necesite más debe trocear la operación, no
   ampliar el tope.

### Pagos: medios, pasarela y confirmación

Tres **medios de pago independientes**, cada uno con su interruptor en Control Center →
Pasarelas de pago (`payments.*` en la config pública). Un frontend nuevo debe ofrecer solo los
activos y mandar al BFF el *gateway* de WooCommerce del elegido:

| Medio (`PaymentChoice`) | Flag | `payment_method` | Qué hace el BFF |
|---|---|---|---|
| `online` | `payments.gateway_enabled` | `hwe_offline` | Crea el pedido (`pending`); el cliente inicia el cobro con `POST /api/payments/create` y vuelve por `/checkout/return`. |
| `whatsapp` | `payments.whatsapp_enabled` | `hwe_whatsapp_link` | Crea el pedido y lo pasa a `on-hold` («En espera»): WooCommerce envía los correos y la tienda manda el link de pago. No hay cobro en línea. |
| `cash` | `payments.cash_enabled` | `cod` | Crea el pedido; se paga al recibirlo. |

- **`lib/payments/checkout-options.ts`** (módulo puro, también para cliente): `enabledPaymentChoices(config)`
  (con los tres apagados queda `online`: un checkout sin medio no cerraría la compra),
  `paymentMethodFor(choice)`, `paymentChoiceFor(method)` y `shouldStartGatewayPayment(choice)`.
- **`POST /api/store/checkout`** responde **422** si `payment_method` no es uno de los tres o su
  medio está apagado: ya no se reenvía cualquier gateway que WooCommerce tenga activo.
- **`POST /api/payments/create`** solo cobra pedidos de pago en línea (`hwe_offline`) y con ese
  medio activo; si no, **409**. Sin pasarela decidida, **503**.
- **Pasarela activa:** la elegida en el Control Center; `PAYMENT_PROVIDER` del entorno solo la
  anula (desarrollo, CI). En producción sin ninguna **no hay `noop`**: el cobro falla con un
  error claro. `noop` tampoco se activa en producción aunque el entorno o el panel lo indiquen:
  su webhook responde **404** y el guard de arranque rechaza `PAYMENT_PROVIDER=noop`. Con
  `payments.gateway_enabled` apagado (p. ej. «solo WhatsApp») **no hay pasarela activa**,
  aunque quede una guardada: ni cobro (503) ni aviso de pruebas ni webhook de `noop`. Credenciales por `GET hwe/v1/payments/credentials` (interno), en caché de memoria
  del proceso (60 s) que `POST /api/revalidate` vacía al guardar el Control Center.
- **Modo de pruebas:** `isActiveGatewaySandbox()` (`lib/payments/registry.ts`, server-only)
  dice si la pasarela cobra con credenciales de sandbox; la vista debe avisarlo
  (`CheckoutForm` lo recibe como `gatewaySandbox`).
- **Confirmación:** solo el webhook verificado (`/api/payments/webhook/<id>`: firma, importe y
  moneda; Aval Pay además consulta la sesión servidor a servidor). El webhook de una pasarela de
  pruebas (`noop`) solo se atiende si es la activa. Wompi y Aval Pay están implementadas de punta
  a punta; PayU y Bold responden 501.

### Checkout y carrito: invitado, pedido y reconciliación

- **Quién compra:** con `ecommerce.checkout_login_required` encendido (valor por defecto)
  `POST /api/store/checkout` exige sesión (401 sin ella). Apagado, acepta invitados: el pedido
  queda con `customer_id = 0` y el BFF deja la cookie httpOnly `hwe_guest_order`
  (`<id>:<order_key>`, 7 días). Esa credencial —y solo ella— autoriza a ese navegador a iniciar
  el cobro (`POST /api/payments/create`), consultar el estado (`GET /api/payments/return`) y ver
  `/checkout/return` (`lib/checkout/order-access.ts`: `canAccessOrder`). El navegador nunca lee
  la cookie ni la `order_key`.
- **Pedido de invitado → cuenta:** al abrir sesión (`login`, `verify-2fa`, `register`),
  `claimGuestOrder` liga el pedido a la cuenta **solo si el correo del pedido es el de la
  cuenta** y retira la cookie; aparece en «Mis pedidos». Si añades otro punto que abra sesión,
  llámalo ahí también.
- **Carrito con sesión:** el JWT se reenvía a la Store API (el carrito es el del cliente, en
  cualquier dispositivo). Al abrir sesión **prevalece el de la cuenta** (el del navegador se
  descarta) y `GET /api/store/cart` reconcilia existencias: quita o reduce las líneas que ya no
  se pueden comprar. `logout-all` vacía el carrito de la cuenta.
- **Qué completa el BFF en `StoreCart`** (`lib/woocommerce/complete-cart.ts`, en todas las rutas
  que lo devuelven): `coupons[].description` y `coupons[].freeShipping`, y `items[].name` como
  texto (sin entidades HTML). Los errores 4xx de WooCommerce llegan como texto plano.
- **Guard de envío:** si el carrito necesita envío y no hay ninguna tarifa, `POST
  /api/store/checkout` responde **409** `{ error, status }` sin crear el pedido (`canSubmitCheckout`, la
  misma regla que aplica el formulario; ver «Checkout sin tarifa» en «Envío por distancia»).
- **Medio de pago frente a WooCommerce:** `payment_methods` del carrito dice qué gateways ofrece
  Woo ahora. Efectivo o WhatsApp no ofrecidos → 422 (no se sustituyen); el pago en línea cae a
  otro medio de cobro, nunca a efectivo.
- **Cliente:** `Idempotency-Key` estable durante todo el intento (un reintento tras un fallo no
  duplica el pedido), un solo envío en vuelo, y se descartan las respuestas obsoletas de
  mutaciones concurrentes del carrito. La vuelta de la pasarela usa `checkoutReturnUrl`
  (`lib/checkout/return-url.ts`), que respeta el idioma de la página; `/checkout/return` repinta
  sola cuando el webhook confirma el pago (`PaymentStatusWatcher`) y ofrece «Pagar ahora».
- **Puntos de extensión que otros módulos rellenan:** (ver «Envío») destino de envío del carrito (`shipping`, tarea 54), nombres
  de producto traducidos en `completeStoreCart` (i18n de contenido, tarea 51), el contexto de
  medición del pedido (analítica, tarea 44) y el canje de puntos (`loyalty`, ver «Fidelización»).

### Paquetes por país: dirección, documento y teléfono (mu-plugin `hwe-geo`)

- **Endpoint:** `GET /wp-json/hwe/v1/geo` (público, cacheable 5 min). Sirve SIEMPRE el paquete de
  `ecommerce.country`; no acepta un país por parámetro. Forma:
  `{ country, name, fallback, requested, dialCode, divisionLabel, address: { division, postcode,
  cityDivision }, divisions: [{ code, name }], cities: [{ name, division }], documentTypes:
  [{ code, label, pattern }] }`.
  - `address.division`: `required` / `optional` (lista cerrada), `free` (texto libre) o `none`
    (no se pide). `address.postcode`: `required` / `optional` / `none`. `cityDivision`: una ciudad
    de la lista solo admite su división.
  - `documentTypes[].pattern` es una expresión regular **sin delimitadores**, válida en PHP y en
    JavaScript, que se compara con el número normalizado (sin espacios, puntos ni guiones, en
    mayúsculas). La usa el módulo `customer-identity`.
  - `geocoding: { abbreviations: { abreviatura: forma completa }, dropTokens: string[] }` (opcional;
    vacío en `generic`). Reglas para normalizar una dirección antes de geocodificarla: el módulo
    `shipping` expande las abreviaturas de vía (sin distinguir mayúsculas) y descarta los tokens de
    `dropTokens` (p. ej. «#», «-», «BIS», cardinales); una letra suelta de un token (la letra de vía
    de «Calle 10 A») solo se descarta si el token anterior del mismo segmento contiene un dígito, así
    «Diagonal A 12» conserva su letra; un segmento tras la coma que empieza por un token descartable
    (un complemento como «Apto») se quita entero. Paquete `CO` con
    las de Colombia; una instancia que añada un país declara las suyas en el filtro `hwe_geo_packs`.
  - Un país sin paquete cae a `generic` (`fallback: true`, `requested` = el país pedido): todo
    texto libre, sin documentos ni prefijo. WordPress lo registra una vez.
  - El núcleo trae `CO` y `generic`. Una instancia añade o sustituye paquetes con el filtro
    `hwe_geo_packs` (forma en `mu-plugins/hwe-geo/packs.php`); un paquete mal formado se descarta y
    se registra, sin tirar los demás.
- **Lector:** `getGeoPack()` (`lib/geo`, server-only, tag ISR `geo`). Si WordPress no responde o
  contesta fuera de contrato, devuelve `GENERIC_PACK` y lo registra (`geo.read_failed`). Los
  formularios reciben el paquete **por props** (`geoPack`); nunca lo leen por su cuenta.
- **Lo que valida el BFF** (`lib/geo/address.ts`, `checkAddress` / `applyGeoPack`): en `POST
  /api/store/checkout`, `PUT /api/store/customer` y la libreta (`/api/store/addresses`), para
  direcciones del país del paquete —las de otro país son texto libre—: la división se resuelve
  (por código, nombre o sufijo) al **código** que exige WooCommerce; una ciudad conocida fija la
  división si falta; una división que no existe o **no corresponde a la ciudad** es un 422 por
  campo (`billing_address.state`); obligatoria y sin resolver, 422. **Nunca se rellena una división
  por defecto.** El teléfono sin prefijo recibe el del paquete (E.164); lo que no cuadra se deja
  como lo escribió el cliente.
- **Libreta:** `buildAddressSchema(pack)` / `buildAddressUpdateSchema(pack)` sustituyen al
  `addressSchema` fijo; sin país en el cuerpo se usa el de la tienda (con el paquete genérico,
  el país es obligatorio).
- **Starter:** `components/forms/geo-fields.tsx` (`CityField`, `DivisionField`) los usan el
  formulario del checkout y la libreta. Una vista propia solo necesita el paquete y estas dos
  piezas (o `divisionOptionsForCity` / `findDivision` si pinta sus propios campos).

### Envío: tarifa por ciudad, extras y envío gratis (módulo `shipping`)

- **Qué hace el backend** (plugin `hwe-shipping`): tarifa fija **por ciudad** que sustituye el coste del
  `flat_rate` de WooCommerce (solo para destinos del país de la tienda, `ecommerce.country`), extras de
  envío **aditivos**, **envío gratis por regla** (la regla la entrega el Control Center por
  `hwe_shipping_free_rule`) y una **zona de reserva** para las ciudades sin tarifa (Control Center →
  Envío). Con el módulo apagado nada de esto existe: las rutas de abajo responden 404 y el checkout
  no ofrece extras.
- **Destino del carrito:** la tarifa y el envío gratis se deciden dentro de WooCommerce con la ciudad
  del carrito. `PUT /api/store/shipping/destination` `{ address_1?, location?, city, country, state?,
  postcode? }` la fija (`/cart/update-customer`) y devuelve el carrito recalculado. El `state` se valida y
  normaliza con el paquete de país (código de la división): 422 si no existe o no corresponde a la ciudad,
  **nunca** se rellena una por defecto. El checkout lo llama (con espera) cada vez que cambia la ciudad.
  La calle y el pin van en la misma petición (ver «Envío por distancia» abajo).
- **Destino de un cliente con sesión** (`lib/woocommerce/cart-destination.ts`): Woo no lee la libreta de
  direcciones, así que el BFF fija el destino de la dirección **predeterminada** al abrir sesión
  (`syncAuthenticatedCart(authToken, userId)`), al cambiar la libreta (`/api/store/addresses`) y, como
  red, al leer un carrito con sesión que llega sin ciudad (`GET /api/store/cart`).
- **Aviso de envío gratis:** el estado llega en `cart.extensions.hwe_free_shipping` (importes en
  **unidades menores**: `enabled`, `threshold`, `eligible_subtotal`, `remaining`, `qualifies`, `city`
  `eligible|not_eligible|unknown`, `all_cities`, `scoped`). `resolveFreeShippingNotice(cart)`
  (`lib/shipping/free-shipping.ts`) lo traduce a qué pintar sin recalcular nada. Starter:
  `components/cart/free-shipping-notice.tsx` (cajón, carrito y checkout) y su «?».
- **«?» del alcance:** `GET /api/store/free-shipping/scope?locale=` → `{ categories: [{ name, path,
  children }], products, cities }` en el idioma pedido (cualquiera de los del sitio: las traducciones
  vienen en `translations` por idioma desde `hwe-content-i18n`; las categorías enlazan con la ruta de su
  idioma). Cacheable (tags `shipping` y `site-config`).
- **Extras de envío:** el checkout ofrece los métodos activos (`getShippingMethods()`, tag `shipping`) y
  manda `shipping_method_ids: number[]`. El BFF los vuelve a leer **frescos** de WordPress al crear el
  pedido y los suma como líneas de cargo (`fee_lines`) **antes** del paso a «En espera» (que dispara los
  correos): el importe nunca sale del cliente, un id inexistente o inactivo no cuenta y un fallo no
  pierde el pedido (se registra `checkout.shipping_fees_failed`). El total que pinta el formulario suma
  los extras porque el carrito de la Store API no los conoce.
- **Ciudades:** las sugerencias del selector de ciudad salen del **paquete de país** (ver «Paquetes por
  país»), no de las tarifas. `GET /wp-json/hwe-shipping/v1/rates` sigue existiendo (solo nombres, nunca
  importes) para otros frontends.
- **Starter:** `FreeShippingNotice` en el cajón, `/cart` y el checkout; el checkout pinta los extras y
  comunica el destino. Cada página pasa `shipping={{ methods }}` **solo** si `modules.shipping`.

#### Envío por distancia: pin, estado, metas y mapa

Una ciudad del módulo es `fixed` (un importe por opción) o `distance` (precio por km con mínimo y
máximo, dentro de un radio desde una dirección de referencia). **El precio lo calcula WooCommerce**: el
frontend nunca calcula ni firma un importe; solo muestra lo que devuelve la Store API.

- **Pin obligatorio en las ciudades por distancia.** `PUT /api/store/shipping/destination` acepta
  `location: { lat, lng }` junto a `address_1`. Con `location` fija el punto de entrega; **la calle es
  obligatoria** con pin (sin calle: 422 con `details.address_1`). **`location` ausente = no se toca el
  punto** (el pin pudo marcarse en otra pantalla y el formulario del checkout no lo envía; WordPress lo
  invalida solo si cambian calle, ciudad o país); **`location: null` = borrarlo** (`clear_point`). Lo mismo
  vale para el destino que el BFF fija desde la libreta de un cliente con sesión: sin `location` en la
  dirección, el punto de la sesión no se toca. Coordenadas NaN, cadenas, fuera de rango o `0,0` dan
  422 **antes de llamar a WordPress**. El punto viaja por la Store API (extensión `hwe_shipping`,
  `set_point`/`clear_point`) con la calle, la ciudad y el país; WordPress lo ignora si no coincide con el
  destino de la sesión. Cambiar la calle (aunque la ciudad sea la misma) invalida el pin: el carrito pasa a
  `needs_location`.
- **Estado del envío.** `cart.extensions.hwe_shipping` = `{ mode, status, distance_km?, duration_min?,
  fallback? }`. `mode`: `fixed` | `distance` | `reserve` | `none`. `status`: `ok` | `needs_address` |
  `needs_location` | `out_of_coverage`. Todo lo que no es `ok` significa **sin tarifas** para ese destino;
  la UI debe explicar el motivo con él, no escribir un «sin envío» genérico. `readShippingStatus(cart)`
  (`lib/shipping/status.ts`) devuelve `null` ante una forma inesperada. Con el módulo apagado no existe.
- **Metas de cada tarifa** (`shipping_rates[].meta_data`, ids `hwe_city_rate:<optionId>` y
  `hwe_city_rate:reserve`): `hwe_option_id`, `hwe_city_type` (`fixed` | `distance` | `reserve`), y solo en
  distancia `hwe_distance_km` (cadena numérica) y `hwe_fallback` (`yes` | `no`). `readRateDetails(rate)` las
  lee. Son las únicas que publica la Store API: las de operación (proveedor, duración, punto de entrega,
  motivo del envío gratis) llevan `_` y no salen de WordPress. No leas de ahí nada que decida un importe.
- **Envío gratis por opción.** `cart.extensions.hwe_free_shipping.rate_ids: string[]` lista las tarifas que
  la regla deja a 0. `FreeShippingState.rateIds` las recoge en `lib`. El resto de campos no cambia.
- **Configuración pública.** `config.modules.shipping` y `config.shipping.distance_enabled` (booleano, por
  defecto `false`): hay al menos una ciudad por distancia. Si es `true`, el mapa de ubicación es obligatorio
  para esas ciudades. El buscador siempre está disponible.
- **Ciudades.** `GET /wp-json/hwe-shipping/v1/rates` devuelve `[{ name, type }]` (`fixed` | `distance`), sin
  importes ni referencias.
- **Geocodificación para el mapa** (BFF, con módulo `shipping` y las mismas guardas que el resto de escrituras):
  - `POST /api/shipping/geocode` `{ query }` (1–200 caracteres) → `{ results: [{ lat, lng, label }] }`, máx. 5.
  - `POST /api/shipping/reverse-geocode` `{ lat, lng }` → `{ label }`, para confirmar el pin.
  - Orden: `requireModule` (404) → `guardMutation` (Origin, CSRF y límite `shipping_geocode`, 10 por minuto
    por usuario con sesión o por IP sin ella) → sin sesión con `ecommerce.checkout_login_required` encendido,
    401. Errores `{ error, code }`: 422 (`no_results`, `invalid_query`, `invalid_point`) o 503
    (`geocoding_backoff`, `provider_unavailable`). Respuesta siempre `Cache-Control: no-store`. La clave del
    proveedor nunca sale de WordPress.
- **Checkout sin tarifa.** Si el carrito necesita envío y no tiene tarifa seleccionable, `POST
  /api/store/checkout` responde **409** `{ error, status }` **antes** de crear el pedido (antes era 422). `status`
  es `extensions.hwe_shipping.status`, o `null` si el módulo está apagado. Los textos están en
  `shippingErrors` (es/en).
- **Guía de mapa (decisión de la instancia, no código del framework).**
  - **Buscar al enviar, nunca por tecla.** La búsqueda llama a `/api/shipping/geocode` al enviar el texto. La
    política de uso de Nominatim lo prohíbe y Google cobra por petición. Muestra los candidatos y deja elegir uno.
  - **Confirmar el pin** con `/api/shipping/reverse-geocode` después de marcarlo, y permitir corregir lat/lng
    o volver a marcar.
  - **Teselas, librería y clave de navegador a elección de la instancia.** El framework no fija proveedor de
    teselas ni librería. La CSP de `next.config.mjs` limita `img-src` (y `connect-src`/`script-src` según la
    librería): la instancia debe autorizar los hosts que use, igual que hace `csp-analytics.mjs` con la analítica.
    Si el mapa usa un servicio de Google en el navegador, necesita **su propia clave de navegador**: la de
    Integraciones → Mapas es de servidor y nunca se envía al cliente.
  - El mapa debe ser operable con teclado: el buscador y los campos de latitud y longitud son la alternativa al puntero.

### Campos de producto declarativos (`hwe-product-fields`)

- **Qué es.** Un mu-plugin del núcleo que NO declara ningún campo: la instancia los declara con
  `hwe_product_fields` (`clave => [label, type, translatable, box, description, options, max]`). El
  núcleo pinta una caja por título en la ficha del producto de WooCommerce, sanea al guardar según el
  tipo (`text`, `textarea`, `richtext`, `url`, `number`, `boolean`, `select`, `color`, `image`) y
  expone los valores por WPGraphQL.
- **GraphQL.** `Product.hweFields(locale: String)` → `[{ key, value }]` con los campos que tienen valor,
  **ya resueltos al idioma**: la traducción si el campo es traducible y la hay; si no, el valor del idioma
  por defecto. Un idioma que el sitio no declara devuelve el valor por defecto. La ficha lo pide con la
  consulta `ProductBySlug` y lo entrega como `product.fields` (mapa clave → texto).
- **Almacenamiento.** Meta `_hwe_<clave>` (idioma por defecto) y `_hwe_<clave>_<idioma>` (traducción: la
  misma convención de `hwe-content-i18n`, así que un dato heredado con `_hwe_<clave>` y `_hwe_<clave>_en` vale
  sin migrar). Los traducibles aparecen en la caja «Traducciones» de la ficha y en
  `Product.translation(locale)`.
- **Claves.** Minúsculas con guion bajo, hasta 40 caracteres. Están reservadas `name`, `short_description`,
  `description`, `seo_title`, `seo_description`, `slug` y el prefijo `landing_` (el módulo `product-landing`).
  Una clave que coincida con la traducción de otra (`nota` y `nota_en`) se descarta. Una declaración mal
  formada se descarta y queda en el log (`[hwe] product-fields: …`).
- **Frontend.** `lib/catalog/product-fields.ts`: `readProductFields`, `productFieldNumber`,
  `productFieldBoolean` y `labelledProductFields`. La instancia declara clave → tipo y etiqueta en
  `instanceRegistry.productFields` y tipa `product.fields` con *module augmentation* de
  `ProductFieldValues` (`lib/instance/types.ts`). El starter lista en la ficha los campos con valor y
  etiqueta; un `richtext` pasa por `sanitizeHtml` y una `url` solo se enlaza si es `http(s)`.
- **Ejemplo vivo:** `examples/instance-minimal` declara `example_usage` (traducible) y `example_weight`.

### Detalles de línea de pedido (`hwe-order-item-details`)

- **Qué es.** Un mu-plugin del núcleo que NO declara ningún detalle: la instancia los declara con
  `hwe_order_item_details` (`clave => [label, validate, applies_to, required, meta_key]`) para datos de una
  línea además del producto (una nota, una dedicatoria, una elección). `applies_to` es obligatorio (lista de
  slugs de producto o callable): ningún detalle aplica a todo el catálogo por descuido.
- **Alta.** El cliente manda `extensions: { <clave>: valor }` en `POST /api/store/cart/items`
  (`useCart().addItem(id, qty, extensions)` / `cartApi.addItem`). El BFF solo **acota** claves
  (`^[a-z][a-z0-9_]{0,39}$`, hasta 8) y valores (texto ≤ 1000, número, booleano o hasta 20 textos); el
  validador declarado en WordPress decide. Si el valor no vale, falta y es obligatorio, o el validador
  lanza una excepción, la Store API responde **400** y el ítem NO entra al carrito (falla cerrado).
- **Pago.** Una línea aplicable que no lleva el dato (un carrito anterior, un alta por otra vía) hace
  fallar el checkout con `hwe_item_detail_missing`: no se paga.
- **Carrito (lectura).** `StoreCartItem.item_data` lleva una entrada visible (etiqueta → valor, en el
  idioma por defecto) y una **oculta** `hwe_item_<clave>` con el dato máquina; `lib/catalog/order-item-details.ts`
  (`cartItemDetailData`, `cartItemDetailValue`) las lee **por clave**, nunca en bloque.
- **Pedido.** La línea guarda la meta **visible** `meta_key` (por defecto la clave; es la que ven los
  correos de WooCommerce, wp-admin y la API) y la oculta `_hwe_item_<clave>` con el dato máquina
  (`orderItemDetailValue`, `orderItemDetailData`).
- **Ejemplo vivo:** `examples/instance-minimal` declara `example_note`.

### Landings editoriales (módulo `landings`)

- **Qué hace el backend** (plugin `hwe-landings`): un registro de landings y, por cada una, un molde de
  **slots fijos** editado en Control Center → Landings. Con el módulo apagado el plugin no registra nada
  y el frontend no lee ni pinta nada de esto: `getEditorialLanding` devuelve `null`, el sitemap y
  `/llms.txt` no las listan y las rutas responden 404.
- **Dónde llega.** Todo sale por `GET /wp-json/hwe/v1/config` bajo `landings` (campos `public`):
  `landings.registry` (texto `identificador | Nombre | tipo`, una landing por línea, tipo `article` o
  `person`) y `landings.editorial.<id>.*`. El identificador es **interno e inmutable**: es la clave del
  contenido y la locación del banner del hero (`landing-<id>`); la URL pública vive aparte.
- **Slots** de `landings.editorial.<id>`: `published`, `url` (`base`: `area` | `root`, `path` y
  `path_<idioma>`), `accent`, `product`, `product_cta_label`, `seo` (`title`, `description`,
  `og_image`, `date_published`, `date_modified`, `author`, `author_landing`), `lead_enabled` /
  `lead_body`, `sections.s1`…`s5` (`enabled`, `order`, `layout`, `kicker`, `title`, `body`, `image`,
  `image_alt`, `image_2`, `image_2_alt`), `quote` (`enabled`, `text`, `author`, `author_photo`,
  `background_image`, `logo`, `logo_alt`), `faq` (`enabled`, `title`, `q1`…`q6` con `question` y
  `answer`), `products` (`enabled`, `title`, `after`, `mode`, `category`, `manual`) y `closing`
  (`enabled`, `title`, `text`, `cta_label`, `cta_href`, `cta2_label`, `cta2_href`, `background_image`).
- **Idiomas.** Cada texto trae un `<campo>_<idioma>` por idioma de `i18n.locales` (`title`, `title_en`,
  `title_pt_br`…): vacío = usar el del idioma por defecto (`pickLocalized`). Las imágenes, los colores y los
  interruptores no se traducen. Un tramo de URL vacío en un idioma es el del idioma por defecto.
- **Identidad por slot.** Una sección es `s1`…`s5`, no una posición: `order` solo cambia dónde se
  pinta. La disposición es una de `image-left`, `image-right`, `full`, `text-only`, `prose-blocks`,
  `mirror-pair` o `divider`.
- **Hero.** Es un banner de tipo `landing` en la locación `landing-<id>` (módulo `banners`).
- **Revalidación.** Tag ISR `landings` (y `site-config`, que el Control Center revalida en cada guardado).

**Motor del frontend** (`lib/landings/`, núcleo; todo es DATO y funciones: ninguna importa componentes):

| Pieza | Qué hace |
|---|---|
| `registry.ts` | `staticEntries()` (de `instanceRegistry.landings.staticEntries`), `parseEditorialRegistry`, `buildEditorialRegistry(registry, editorial?)` (las estáticas primero; las del panel con el molde estándar), `editorialLandingHref` / `editorialLandingPaths`, `findEditorialByPath` y `findEditorialByAnyPath` (acepta el tramo de otro idioma y dice si es el canónico: el 308 lo da la ruta). Una landing con `product` relleno sale del registro de URL libre cuando se pasa `editorial`. |
| `reserved-segments.ts` | Tramos de raíz que una landing no puede ocupar: las rutas del starter, los idiomas, `api` y `instanceRegistry.landings.reservedRootSegments`. Una landing de raíz sobre un tramo reservado **vuelve al área**; `tests/unit/landings-reserved-segments.test.ts` contrasta la lista con `app/[locale]/`. |
| `base-flow.ts`, `resolve-sections.ts` | El flujo: `buildBaseFlow(landing, entry)` → `resolveSections(base, entry)`. `consumesBase` filtra ANTES de resolver las anclas `after`; con 2+ únicas sobre la misma ancla manda el orden de declaración (contador `placed`). `unplacedAnchors` detecta una única cuya ancla está consumida (va al final, en silencio). |
| `band-tones.ts` | Alternancia de bandas `surface` / `background` sobre el flujo YA resuelto; `"other"` = banda con color propio que no corta la cadena. |
| `prose-blocks.ts`, `paragraph-split.ts` | `splitByH3` (intro + un bloque por `<h3>`, con los enlaces finales como CTA) y `splitFirstBlock` (para `mirror-pair`). |
| `cms-href.ts`, `editorial-href.ts` | Allow-list de enlaces del CMS (`/ruta`, `#ancla`, `http(s)`, `mailto`, `tel`, `editorial:<id>`), y `getEditorialHref` / `getEditorialHrefStrict` / `resolveCmsLink`: **la única vía** para enlazar una landing (la URL no es deducible del identificador). `Strict` devuelve `null` si no está registrada: para datos estructurados. |
| `editorial-landing.ts` | `getEditorialLanding(id, locale)`: del contenido de `config.landings.editorial.<id>` al modelo `EditorialLanding` (resuelto al idioma, saneado, con `locales` y `contentLang`). Nulo si el módulo está apagado, no está registrada o no está publicada. |
| `editorial-locales.ts`, `editorial-list.ts` | Idiomas en que existe (por traducción de los campos de peso: título SEO, entradilla, texto de cierre y cuerpos de sección) y listas para el índice, el sitemap y `/llms.txt`. |
| `editorial-product.ts`, `product-bound-landings.ts`, `product-landing-entry.ts` | La landing asociada a un producto: se sirve en `/products/<slug>/learn` con precedencia **landing de producto > editorial asociada > 404**; `getProductBoundLandingSlugs` es la unión de los dos mecanismos. |
| `learn-index.ts`, `learn-index-products.ts` | El índice del área: agrupa las guías de producto por categoría y respeta `instanceRegistry.landings.productGroupOrder`. |

- **Entradas con diseño propio** (`instanceRegistry.landings.staticEntries`): `label`, `path` / `paths`,
  `base`, `jsonLd`, `uniqueSections: [{ key, after }]`, `consumesBase`, `faqAside`, `seoFallback` y `hints`
  (pistas opacas para tus vistas). Deben coincidir con el filtro `hwe_landings_static_entries` del backend.
  El mapa `clave → pieza de código` vive en `components/landings/editorial/unique-sections.tsx` (tuyo): el
  núcleo nunca importa componentes. Consume lo que absorbes con `findConsumedSection(sections, "s3")`
  (por SLOT) y devuelve `null` para auto-ocultarte.
- **Rutas del starter:** `/learn` (índice), `/learn/[slug]` (área) y `/[landingPath]` (raíz) delegan en
  `components/landings/editorial/editorial-landing-route.tsx`: resuelve el tramo (el de cualquier idioma, con
  308 al canónico), 404 si el módulo está apagado o no está publicada, metadatos con hreflang SOLO de los
  idiomas con traducción, y JSON-LD (`BreadcrumbList`, `Article` o `Person` según el tipo, `FAQPage`).
- **Starter:** `landing-editorial-view.tsx` (hero, entradilla, secciones con las siete disposiciones, cita,
  preguntas, productos y cierre). Es **de la instancia**: reescríbela; el contrato es el de arriba.

### Landing de producto (módulo `product-landing`)

- **Qué hace el backend** (plugin `hwe-product-landing`): una landing por producto, editada en la ficha
  (caja «Landing del producto») y publicada con una casilla. Con el módulo apagado el plugin no registra
  nada y el frontend no lee ni pinta nada de esto.
- **GraphQL.** `Product.landing` (`ProductLanding`, **nulo salvo publicada**): escalares en camelCase en el
  idioma por defecto (`published`, `seoTitle`, `heroSubtitle`, `eduBody`, `accent1`, `videoUrl`…) y tres
  listas: `components` (`image icon name note`), `qualities` (`icon image title text`) y `adviceItems`
  (`heading body`). `ProductLanding.translation(locale)` y `<Fila>.translation(locale)` devuelven los
  campos traducibles de un idioma secundario (nulos = usar el del idioma por defecto). Los tipos son
  `ProductLanding`, `ProductLandingTranslation`, `ProductLandingComponent|Quality|Advice` y sus
  `…Translation`. `RootQuery.hweProductLandingsPublished: [String]` lista los slugs publicados (para
  prerender, sitemap y enlaces, sin N+1).
- **Límites.** `components` 11 filas, `qualities` 11 y `adviceItems` 20 (10 consejos —sin `heading`— y 10
  preguntas —con `heading`— por separado). El cuerpo de un consejo es **texto plano**; los campos con
  formato (`eduBody`, `aboutBody`, `adviceIntro`, `closingText`) son HTML que debe pasar por `sanitizeHtml`.
  `accent1`/`accent2` son `#rrggbb` o nulos y se validan antes de inyectarse como propiedad CSS.
  `closingCtaHref` es texto libre del editor: valídalo (`resolveLandingHref`: solo `/…` interno o
  `http(s)://`).
- **Íconos.** Las filas traen una **clave** de ícono (`icon`), no el dibujo. El set neutro tiene 12
  (`sparkles heart leaf shield sun moon droplets wind flame anchor compass sprout`); la instancia lo
  amplía con el filtro `hwe_product_landing_icons` y declara los mismos dibujos en su frontend.
- **Lectores** (`lib/landings/`, con `modules.product-landing = false` devuelven vacío sin llamar a
  WordPress): `getProductLanding(slug, locale)` → `ProductLanding | null` (modelo resuelto al idioma y
  saneado, con `locales` y `contentLang`), `getPublishedProductLandingSlugs()`,
  `getPublishedProductLandingLocales()`, `hasProductLanding(slug, locale)` y
  `getRelatedProductLandings(slug, locale, limit)` («Sigue aprendiendo»: un **anillo** con semilla fija,
  estable en ISR y con reparto uniforme). Un fallo de WordPress en `getProductLanding` se propaga (500);
  los demás degradan y quedan en el log.
- **Ruta y SEO.** `/products/[slug]/learn`: comparte el tramo de URL por idioma y el 308 de la ficha; 404
  si el módulo está apagado o la landing no está publicada. JSON-LD: `BreadcrumbList`, `Article` (con
  `about` = `#product` de la ficha), `Product` con el **mismo `@id`** que la ficha, `FAQPage` **solo** si
  hay preguntas con encabezado y respuesta (`advicePageFaq`) y `VideoObject` si hay video (con
  `uploadDate` solo si es una fecha válida). El sitemap la añade al segmento `catalogo`.
- **Sin traducción no se anuncia el idioma.** `landing.locales` (y `getPublishedProductLandingLocales`)
  dicen en qué idiomas existe: el por defecto siempre y uno secundario si trae traducción de algún campo de
  peso. Solo esos entran en el hreflang y el sitemap; los demás sirven el idioma por defecto con `lang`
  correcto.
- **Revalidación.** Tag ISR `products` (lectores y plugin). El plugin lo pide al guardar y al guardar un
  producto con landing publicada.
- **Starter:** `components/landings/product/landing-product-view.tsx` (hero, educativa, sobre el producto
  con componentes, cualidades, consejos y preguntas, CTA a la ficha, video como enlace, cierre y «Sigue
  aprendiendo») y `quality-icons.tsx`. La ficha enlaza a la guía (`products.learnMore`) solo si
  `hasProductLanding`. Es **de la instancia**: reescríbela; el contrato es el de arriba.

### Documento de identidad y términos (módulo `customer-identity`)

- **Qué pide** (`identityPolicy`, `lib/customer/policy.ts`): con el módulo apagado, nada. Encendido,
  siempre la aceptación de términos y, **solo si el paquete de país tiene tipos de documento**
  (`documentTypes`, ver «Paquetes por país»), el documento. Los tipos y sus patrones salen del
  paquete, no de una lista del framework.
- **Checkout** (`POST /api/store/checkout`): el cuerpo lleva `identity: { documentType,
  documentNumber }` y `terms_accepted: true`. `resolveCheckoutIdentity` decide: un **invitado**
  debe enviar ambos; con **sesión** manda lo que la cuenta ya tiene (documento bloqueado, términos
  no se repiten) y solo lo que falta se envía y se guarda en la cuenta **antes** de crear el pedido.
  422 por campo (`identity`, `terms_accepted`) si falta o no cuadra con el paquete; **409** si el
  documento es de otra cuenta o la cuenta ya tiene otro. La fecha de aceptación la pone el
  servidor: nunca se confía en el cliente.
- **El pedido nace con su documento:** el BFF lo manda en `extensions.hwe-identity` de la Store API
  (con efectivo el pedido pasa a «Procesando» en esa misma petición y el correo sale antes de que
  el BFF pueda escribir nada) y lo reescribe por wc/v3 como salvaguarda. `identity`,
  `terms_accepted` y `tracking` **no** viajan a la Store API tal cual.
- **Registro** (`POST /api/auth/register`): mismos campos. El documento se comprueba (único) **antes**
  de crear el usuario: 409 si es de otra cuenta, 503 si no se pudo comprobar. Tras crear la cuenta,
  si WordPress no guarda la identidad, la cuenta sigue siendo válida (se pedirá en el checkout o en
  la cuenta). Con el módulo apagado, los campos se ignoran y el paquete de país ni se consulta.
- **Cuenta** (`PUT /api/store/customer`): acepta `identity`; el documento se registra solo si la
  cuenta no tiene uno (escritura única) y va **primero**: si se rechaza (409), no se toca nada más
  del perfil. 422 si el módulo está apagado o el país no tiene tipos de documento.
- **Cobro:** `POST /api/payments/create` pasa el documento del pedido a la pasarela
  (`customer.document`, tipo en código del país; cada proveedor lo traduce).
- **Correos:** completo en los de la tienda, enmascarado (`***050`) en los del cliente.
- **Starter:** `components/forms/{document-field,terms-consent}.tsx`, usados por el checkout
  (`identity`), el registro (`identity`) y la cuenta (`ProfileForm identity`). Cada página pasa la
  prop **solo** si `identityPolicy(...).enabled`.

### Cuenta: pedidos, volver a pedir y reseñas

| Ruta | Qué hace | Reglas |
|---|---|---|
| `POST /api/store/orders/[id]/reorder` | «Volver a pedir»: añade al carrito lo que hoy se puede comprar y devuelve `{ cart, added[], skipped[] }`. | Solo el dueño (404 si no). Recorta la cantidad al stock; `skipped[].reason` = `unavailable` \| `out_of_stock`. |
| `POST /api/store/orders/[id]/received` | «Recibí mi pedido»: `processing` → `completed`. | Solo el dueño y solo desde `processing` (422 si no). Habilita calificar y reseñar. |
| `POST /api/store/orders/[id]/review` | Califica el pedido (reseña del **sitio**) y, con `products: true`, publica la misma reseña en sus productos. | Módulo `site-reviews` (404 si está apagado). Solo el dueño, solo `completed`, una por pedido (409). El fan-out a productos solo ocurre con `ecommerce.reviews_enabled` encendido; apagado, la reseña del pedido se guarda y `products` se ignora (`productsReviewed: 0`). |
| `GET /api/store/reviews/[productId]` | Elegibilidad para reseñar el producto: `{ canReview, reason, reviewerName?, order? }`. Nunca 401. | `reason`: `auth` · `not_purchased` · `not_received` · `already_reviewed` · `null`. |
| `POST /api/store/reviews/[productId]` | Reseña de producto. | Exige un pedido **completado** con el producto (403; `not_received` 409 si solo hay uno en preparación). Autor siempre del perfil de la sesión. Se publica aprobada y `verified`. `sameForOrder: true` la replica en los demás productos del pedido. |

- **Módulo `site-reviews`** (plugin `hwe-site-reviews`): la reseña del sitio vive en el CPT
  `hwe_site_review` y se refleja en el pedido como meta `_hwe_site_review`
  (`{ id, rating, content, date }`), que llega en el `meta_data` de wc/v3. El frontend decide
  qué ofrecer con `orderReviewState(order)` (`lib/account/order-review.ts`) y `isModuleEnabled`.
- **Estado del pedido en la UI:** `lib/account/order-status.ts` (claves `account.orderStatus.*`),
  `order-payable.ts` («Pagar ahora» solo en `pending`/`failed`/`on-hold` de pago en línea) y
  `order-support.ts` (ayuda por WhatsApp: cancelar en espera, problema en completado).
- Una línea de pedido con la meta `_hwe_not_reviewable` no se ofrece a reseñar (un producto
  compuesto cuya ficha no muestra reseñas): la pone quien arma la línea.

### Cupones: reglas, avisos y «Cupones disponibles»

- `POST /api/store/coupons` aplica con `cart/apply-coupon` y `DELETE` quita con
  `cart/remove-coupon` (devuelven el **carrito** completo). Con `ecommerce.coupons_enabled`
  apagado, `POST` responde 403, pero `DELETE` sigue abierto: un descuento ya aplicado no puede
  quedar sin poder quitarse. La sesión la exige cada cupón en WordPress, no el BFF.
- **Módulo `coupon-rules`** (plugin `hwe-coupon-rules`, ver su `README.md`/`MANUAL.md`):
  ventana de validez inclusiva con fecha de inicio, primera compra (con fecha de corte),
  «requiere sesión», alcance (todo / categorías / productos) y cupones **públicos**. El motivo
  de cada rechazo llega al cliente en texto plano.
- **Lectura (BFF → WordPress, solo con el secreto interno):** `GET hwe/v1/coupons/public`
  (lista compartida, ISR 1 h con el tag `coupons`; incluye los que empiezan en ≤ 3 h) y
  `GET hwe/v1/coupons/public/used-up?user_id=` (por cliente, sin caché). `lib/coupons/` decide
  dónde se anuncia cada cupón (`couponAppliesToProduct/Category/Cart`) y si se enseña
  (`shouldShowCoupon`, la misma regla en servidor y navegador).
- **`GET /api/coupons/used-up`** (módulo + `coupons_enabled`, si no 404): `{ codes, authenticated }`
  del propio cliente. Lo consulta `CouponGate` en el navegador porque fichas y categorías son
  ISR y no conocen al visitante.
- **Qué no viaja:** el código de un cupón que aún no empieza solo llega a las páginas ISR (ficha
  y categoría, oculto hasta su hora); carrito y checkout, que son dinámicos, reciben únicamente
  los vigentes. Un cupón que depende de quién compra (correos permitidos, marcas) no se anuncia.
- `completeStoreCart` añade `couponRef` (slug y categorías con ancestros) a cada línea del
  carrito cuando el módulo y los cupones están activos: es lo que usa «Cupones disponibles»
  (`CartCouponOffers`) para filtrar por alcance y gasto mínimo/máximo.

### Fidelización: monedero y referidos (módulo `loyalty`)

Un frontend que consuma el módulo `loyalty` (plugin `hwe-loyalty`) tiene este contrato. Todo se
apaga con `config.modules.loyalty` o con el interruptor de la función (`config.loyalty`), y el BFF
responde **404** como si la ruta no existiera:

| Ruta | Guarda | Devuelve |
|---|---|---|
| `GET /api/loyalty/summary` | Puntos | `{ participant, balance, earned, used, expiring: { points, date }, balance_value }`. `balance_value` es la conversión de presentación (unidades mayores); el descuento real lo calcula WordPress. |
| `GET /api/loyalty/transactions?page&per_page` | Puntos | `{ items, total, pages }`; cada movimiento lleva `id`, `type`, `points` (con signo), `data`, fechas y `description` ya compuesta en el idioma de la petición. `per_page` se recorta a 1..100. |
| `GET /api/loyalty/referrals?level=1\|2&page&per_page` | Referidos | `{ items: [{ name, date, status, points_generated }], total, pages, totals: { level1, level2, pending, earned } }`. Solo el nombre visible, nunca ids, email ni login. `level` fuera de `1\|2` es 400. |
| `GET /api/loyalty/referral-link` | Referidos | `{ code, url }`; `url = <config.brand.url>/?ref=<code>`. Si `brand.url` está vacío, la URL es relativa (`/?ref=…`), como el resto de URLs absolutas del núcleo. |
| `GET /api/loyalty/validate-code?code=` | Referidos, **pública** | `{ valid }` y nada más; rate-limit por IP. |
| `POST /api/loyalty/transfer` | Transferencias | `guardMutation` estricto + `Idempotency-Key` obligatoria. Devuelve `{ ok: true, sent, received, fee, recipient_name }` o `{ ok: false, error }`; los motivos de negocio son 422, `conflict` 409, `disabled` 404 y `failed` 502. |
| `POST /api/store/cart/points` | Puntos | Cuerpo `{ points }` (entero ≥ 0) → update callback de la extensión y carrito recalculado. Exige sesión (401). |
| `DELETE /api/store/cart/points` | Puntos | Quita el canje (envía 0) y devuelve el carrito. |

- **Sesión y privacidad:** salvo `validate-code`, todas exigen sesión (401 sin ella). El `user_id`
  sale **siempre** de la sesión verificada; la query y el cuerpo no pueden elegirlo. WordPress se
  llama por `wpFetch` a las rutas internas `hwe/v1/loyalty/*` (solo con el secreto interno). Un
  fallo de WordPress es un 5xx tipado (`code`: `unavailable` / `invalid_response`), nunca un saldo
  0 inventado.
- **Canje en el carrito:** el navegador solo declara la cantidad. La extensión
  `extensions["hwe-loyalty"]` publica `{ available, reason, balance, points_applied, discount,
  max_redeemable, min_redeem, full_payment_available }`. `discount` va en **unidades menores**
  (string): formatéalo con `formatStoreAmount`. `reason` es `null` si el canje puede aplicarse o
  `guest` \| `not_participant` \| `disabled` \| `below_minimum` \| `cart_below_cash_minimum`.
  WordPress recorta la cantidad a saldo, `max_redeem_per_order`, `max_redeem_percent` (porcentaje
  de la base sin envío) y el tope del carrito; con el pago
  completo apagado, el descuento no cubre el envío ni deja el total por debajo de `min_cash_due`.
- **Checkout:** si el saldo bajó entre el carrito y la creación del pedido, el checkout responde
  **409** con `reason: "loyalty_insufficient_points"` y **no crea el pedido**; el reintento cobra
  lo que el carrito muestra entonces (la cantidad que el cliente vio se descarta). Un fallo de
  confirmación del canje responde 409 con `loyalty_redeem_failed`. Un pedido en total 0 cubierto
  por puntos responde `paid_with_points: true`: no llames a `/api/payments/create` ni esperes link;
  la prueba es la meta `_hwe_loyalty_paid_with_points` del pedido, escrita contra el libro. Pedir
  pago completo con puntos y `shipping_method_ids` a la vez es **422**
  (`loyalty_full_payment_with_shipping_extras`). Cualquier otro carrito en total 0 (cupón del
  100 %, descuento de socio…) con extras de envío también es **422**, con
  `reason: "zero_total_with_shipping_extras"`: WooCommerce da por pagado un pedido en 0 y los
  extras, que se suman después, nunca se cobrarían.
- **Después de un canje confirmado** la cantidad pedida se vacía de la sesión de Woo: la siguiente
  compra no gasta puntos que nadie pidió para ella.
- **Registro y `?ref=`:** `proxy.ts` deja la cookie httpOnly `hwe_ref` (30 días, `SameSite=Lax`,
  `Secure` en producción) al llegar a una página con `?ref=<código válido>`; el último código
  reemplaza al anterior y la URL no cambia. `POST /api/auth/register` acepta `referral_code`
  opcional (el cuerpo gana a la cookie); tras crear la cuenta atribuye el referido y retira la
  cookie, y un fallo se registra sin romper el alta. Con el módulo o «Referidos» apagados, ni el
  cuerpo ni la cookie se leen.
- **Nombre visible:** la red, el recibo de una transferencia y los movimientos muestran el nombre
  visible de la otra persona, nunca su login ni su email. La regla está en
  `HWE_Loyalty_PublicName`: `display_name` solo si no es el login ni un email; si no, nombre de pila
  e inicial; si no, vacío.
- **Extensión de la tienda:** el módulo no trae UI. `lib/loyalty/` ofrece los tipos
  (`LoyaltySummary`, `LoyaltyTransactionPage`, `LoyaltyReferralPage`, `LoyaltyCartExtension`,
  `LoyaltyTransferResult`…), los helpers puros `pointsToMoney`, `formatPoints`, `maxRedeemable`
  (solo para mostrar), `parseLoyaltyCartExtension` y `describeEntry`, y los lectores server-only de
  las rutas. Los nombres de la unidad salen siempre de `config.loyalty.currency_name` /
  `currency_name_plural` / `currency_icon`; nunca los escribas a mano.

### Membresías: niveles, catálogo exclusivo y carné (módulo `memberships`)

Un frontend que consuma el módulo `memberships` (plugin `hwe-memberships`) tiene este contrato.
Todo se apaga con `config.modules.memberships`: sus rutas del BFF responden **404**, el catálogo
no pide el campo de GraphQL, la extensión de la Store API no se registra y no se engancha nada en
`loyalty` ni en `shipping`. Los datos guardados se conservan.

**Config pública** (`config.memberships`):
`card_enabled`, `card_verify_path` (ruta del QR, normalizada) y `member_number_prefix`. El resto de
ajustes son operativos de WordPress y no salen por la config. Entre ellos, «Los planes generan
puntos de fidelización» (`plans_earn_points`, apagado por defecto) decide si la línea de un plan
entra en la base de `loyalty`; el frontend no calcula puntos ganados (los abona WordPress), así
que no replica esa regla. El BFF expone los tipos y helpers en
`lib/memberships/` (`MembershipLevel`, `MembershipState`, `MembershipHistoryPage`,
`MembershipsCartExtension`, `MembershipVerification`…, `parseMembershipsCartExtension`,
`normalizeMembershipRequirement`, `describeBenefit`, `describeHistoryEntry`, `formatMemberNumber`,
`buildVerifyUrl`).

**Catálogo (GraphQL).** Con el módulo activo, `Product` y `ProductCategory` exponen:

```
hweMembership {
  requiredLevel { slug rank name }   # null si el producto/categoría es público
  opensAt                            # ISO 8601 UTC o null
  locked                             # true si esta petición ve la ficha de escaparate
}
```

En `ProductCategory` el nombre, la imagen y la descripción son públicos: la categoría publica su
requisito pero `locked` siempre es `false` (los productos que contiene sí se recortan). El campo
**no existe** en el esquema con el módulo apagado: pedirlo haría fallar toda la consulta.
Por eso `lib/catalog/products.ts` elige entre dos variantes de cada documento con
`membershipsCatalogEnabled()`, y `types/catalog.ts` tipa `membership` como
`MembershipRequirement | null`. Quien no cumple el requisito sigue viendo el producto en listados,
búsqueda y sitemap, pero solo con la ficha de escaparate: id, nombre, slug, tipo, imagen principal,
precio público (rango en variables), categorías, el requisito y `locked`. No llegan descripción,
descripción corta, galería, atributos, variaciones ni su stock, `hweFields`, landing, SEO ni
descargas. Una variación directa de un producto bloqueado se anula. Con algún requisito en el
catálogo, la búsqueda de productos (`where: { search }` de GraphQL, `?search=` de la REST de
WordPress y `?search=`/`?sku=` de la Store API) compara solo el nombre: no se puede buscar por la
descripción ni por el SKU de lo bloqueado. El recorte lo hace WordPress;
no lo reimplementes en el frontend.

**Rutas del BFF** (`app/api/memberships/*`). Todas exigen la guarda del módulo (404):
`requireModule(config, "memberships")` o, en el carné y su verificación,
`requireMembershipCard(config)`, que además exige `card_enabled`. Usan `wpFetch` con el secreto
interno. El `user_id` sale **siempre** de la sesión verificada, nunca de la query ni del cuerpo.

| Ruta | Acceso | Respuesta |
|---|---|---|
| `GET /api/memberships/levels` | pública | `{items: MembershipLevel[]}`. `Cache-Control: public, s-maxage=60, stale-while-revalidate=300` y `Vary` por idioma. Cada nivel lleva nombre/descripción/slug traducidos, `slugs` por idioma, `rank`, `icon`, `color`, `purchasable`, `min_account_age_days`, `benefits` (clave + datos + texto ya compuesto) y `products` de compra (`id`, `price`, `days`, `duration`). |
| `GET /api/memberships/me` | sesión (401) | `MembershipState`: `status` (`none`/`active`/`frozen`), `level` (`{id,rank}` o `null`), `ends_at`, `member_since`, `member_number`, `queue`, `benefits`, `gifts` (`max`/`used`/`remaining`) y `purchasable` (`MembershipPurchaseEligibility[]`). Sin caché. |
| `GET /api/memberships/history?page&per_page` | sesión (401) | `{items, total, pages}`. `per_page` se recorta a 1..100 (defecto 20). Cada evento lleva `type`, niveles, datos de lista blanca y `description` ya compuesta en el idioma de la petición. |
| `GET /api/memberships/prices?ids=1,2` | sesión **opcional** | `{items:[{id, regular, price, member_price, percent}]}` con decimales de WooCommerce. Sin sesión devuelve los precios públicos, sin inventar descuento. Tope de 50 ids: más es 400 `invalid_request`; ninguno es `{items:[]}`. |
| `GET /api/memberships/products/[slug]` | sesión (401) | Ficha completa si el socio cumple el requisito; la misma ficha de escaparate si no (la decide WordPress). Incluye `locked` y `hwe_membership`. Sin caché compartida: la misma URL devuelve dos formas. |
| `GET /api/memberships/card` | sesión + carné (404/401) | `{token, expires_at, verify_url}`; `verify_url` se compone con `config.brand.url` y `card_verify_path`, nunca con `home_url()`. Sin nivel: 403 `membership_card_unavailable`. Sin `brand.url` válido: 500 `invalid_config` (no se publica un QR a un dominio inventado). |
| `GET /api/memberships/verify/[token]` | pública + carné (404) | Rate-limit 30/60 s por IP. Token con formato hexadecimal de 64: `{valid:false}` o `{valid:true, level:{name,slug}, member_since, member_number, member_name, document?}`. Un token inexistente, caducado o revocado da exactamente `{valid:false}`. Nunca se cachea. |

Los fallos tipados de los lectores se traducen a 400/403/404/503/502 con `{error, code}`; un fallo
de WordPress nunca se convierte en un estado inventado.

**Escritura del carrito.** El navegador solo declara que quiere gastar un envío gratis; el importe
y la decisión son de WordPress:

| Ruta | Acceso | Respuesta |
|---|---|---|
| `POST /api/store/cart/free-delivery` | sesión (401) + `guardMutation` + módulo | Declara `free_delivery: true` y devuelve el carrito recalculado. |
| `DELETE /api/store/cart/free-delivery` | ídem | Retira la declaración y devuelve el carrito. |

Ambas ignoran el cuerpo y reenvían solo `{free_delivery: true|false}` al update callback de la
extensión con el `Cart-Token` y el JWT. Un rechazo de negocio responde **409** con `{error,
reason, ...details}` (`membership_free_delivery_unavailable`, `membership_required`…).

**Extensión de la Store API.** El carrito publica `extensions["hwe-memberships"]`:

| Campo | Contenido |
|---|---|
| `level` | `{id, slug, rank, name}` del nivel efectivo, o `null` sin membresía/congelada. |
| `member_discount` | `{total, lines}` con importes en **unidades menores** de la moneda, como string. Formatea con `formatStoreAmount`. |
| `free_delivery` | `{available, remaining, requested, applied, reason}`. `requested` es solo lo declarado por el cliente; `reason` es `membership_free_delivery`, `already_free`, `quota_exhausted`, `not_eligible`, `not_available` o `null`. |
| `blocked_items` | `{key, product_id, reason, min_rank, level_slug, level_name, opens_at}[]` de líneas que hoy no puede comprar. `reason` = `membership_required` o `membership_early_access`. |
| `purchase_notice` | `renewal`, `upgrade`, `scheduled` o `null` para la línea de membresía. |

`parseMembershipsCartExtension` tolera que la extensión no exista (módulo apagado) y motivos
futuros. Las líneas bloqueadas se muestran con sus datos estructurados, no se eliminan del carrito
por iniciativa del frontend.

**Checkout.** Si el nivel cambia con el carrito ya lleno (caduca, se congela, se retira, se apaga
el módulo) el checkout responde **409** y **no crea el pedido**. Los `reason` estables son
`membership_required`, `membership_early_access`, `membership_free_delivery_unavailable`,
`membership_login_required`, `membership_one_per_cart`, `membership_not_purchasable`,
`membership_account_too_new`, `membership_account_pending` y `membership_already_indefinite`. Los
detalles de la lista blanca son `days_left`, `min_account_age_days`, `account_age_days`,
`level_id`, `current_level_id`, `min_rank`, `level_slug`, `level_name`, `opens_at`, `product_id`,
`item_id`, `key` y `lines`. El campo `error` del 409 es un texto de respaldo compuesto con
`messages.memberships.checkout` en el idioma de la petición (`X-HWE-Locale`); la UI debe decidir por
`reason` y componer sus frases como prefiera (`messages.memberships.purchase` tiene las de la ficha).

**Carné y verificación.** El token dura `card_token_ttl_hours` (24 por defecto) y el BFF devuelve
la URL absoluta del QR; el frontend solo tiene que pintar el QR y enlazar la verificación. La
página de verificación puede ser pública: no necesita sesión, pero sí que el módulo y el carné
estén encendidos. El nombre del nivel se traduce con `/levels` y, si falla, se conserva el nombre
nativo (el contenido falla suave).

**Módulo apagado.** `/api/memberships/{levels,me,history,prices,card,products/[slug],verify/[token]}`
y los dos métodos de `/api/store/cart/free-delivery` responden 404. La pasada e2e sin módulos lo
comprueba en `frontend/tests/e2e/modules-off.spec.ts`.

### Analítica y medición

- **Servicios** (`config.integrations`: `analytics_provider` + `analytics_id`, `gtm_id`,
  `meta_pixel_id`, `tiktok_pixel_id`, `hubspot_portal_id`): cada uno se activa solo si su id no
  está vacío **y** tiene la forma esperada (`G-…`, `GTM-…`, dígitos…, o el dominio con Plausible);
  lo que no encaja no llega a ningún script. Con GTM, GA4 directo no se carga. Sin ningún
  servicio no hay banner ni «Preferencias de cookies». `lib/analytics/services.ts` es la fuente
  única: scripts, banner y qué dice.
- **Consentimiento:** ningún script carga sin «Aceptar» (`localStorage`, clave `cookie-consent`).
  Retirarlo borra las cookies de seguimiento y recarga. Los eventos se retienen (hasta 20) solo
  en el hueco de hidratación y solo con un «Aceptar» guardado; si no, se descartan. El valor es
  JSON (`lib/analytics/consent-storage.ts`): decisión, **generación** (`CONSENT_GENERATION`,
  subirla repregunta a todos), **alcance** (los servicios que el banner nombraba: uno nuevo en el
  panel repregunta solo) y, con el módulo `consent-log`, el comprobante y la cola de decisiones
  sin registrar. «Preferencias de cookies» (pie de página) permite cambiarla.
- **Registro y medición del consentimiento (módulo `consent-log`):** ver § «Consentimiento» más
  abajo. Sin el módulo, el banner funciona igual con el consentimiento local y no encola nada.
- **Eventos** (forma de GA4: `currency`, `value`, `items[]`, `transaction_id`): `view_item`,
  `add_to_cart` (lo añadido, leído del carrito del servidor), `begin_checkout`, `purchase`. Se
  traducen a cada proveedor (Meta: `ViewContent`/`AddToCart`/`InitiateCheckout`/`Purchase`;
  TikTok; Plausible como objetivos con ingresos). Nunca llevan el nombre del producto a Meta ni
  a TikTok.
- **Compra:** solo cuenta un pedido **pagado en línea** (`countsAsPurchase`); efectivo y link
  por WhatsApp no. El navegador y la Conversions API comparten `event_id = purchase_<pedido>`
  y Meta la cuenta una vez.
- **Conversions API** (servidor): `sendPurchaseToMeta` tras el webhook de pago verificado. Solo
  con el consentimiento que el pedido guardó al crearse (`_hwe_tracking_consent`, más cookies
  `_fbp`/`_fbc`, IP, navegador y URL de «gracias» solo si aceptó). Token y código de prueba por
  el canal interno `hwe/v1/integrations/meta-capi`; correo y teléfono van con SHA-256; una vez
  por pedido (`_hwe_meta_capi_purchase_sent`).
- **Formularios y HubSpot:** todo `<form>` lleva `data-hs-do-not-collect` salvo los que deben
  llegar al CRM (contacto); un test lo impone.
- **CSP:** `CSP_ANALYTICS_PROVIDERS` (lista o `none`) estrecha los hosts autorizados; por defecto
  están todos los que el framework sabe cargar.

### Consentimiento: registro y medición (módulo `consent-log`)

Solo existe con el módulo `consent-log` activo (plugin `hwe-consent-log`); apagado, las dos rutas
responden **404** y el banner no encola ni guarda comprobante.

| Ruta | Quién | Qué hace |
|---|---|---|
| `POST /api/consent` | navegador | Registra una decisión como **prueba** y responde `{ receipt }`; fija la cookie httpOnly `hwe_consent` (UUID del dispositivo, 400 días, `SameSite=Lax`). Cuerpo: `decision` (`accepted`/`declined`), `action` (`first`/`change`), `variant` (`bar`/`modal`), `locale`, `path`, `clientAt`, `services`, `generation`. El usuario, la IP (truncada /24 · /48), el user-agent y el texto que se enseñó los pone **el servidor**, nunca el cuerpo. Sin CSRF (el visitante de primera visita no tiene token): lo acotan Origin y un rate-limit por IP. 409 si no hay servicios que consentir; 503 si WordPress no lo guardó (el navegador conserva la decisión en cola y la reenvía). |
| `POST /api/consent-stats` | navegador | +1 en el contador anónimo de la hora (`shown`/`accepted`/`declined`, por variante). Sin IP, cookie ni identificador; Redis del BFF. Responde 204 también sin Redis. |
| `GET /api/consent-stats?days=&tz=` | WordPress | Informe por día local. Solo con `X-HWE-Internal-Secret`. |

- WordPress guarda la decisión en `hwe/v1/consent/record|latest|link` (solo BFF, por `wpFetch`).
  El BFF **vincula** el comprobante a la cuenta tras `login`, `register` y `verify-2fa`, y mete el
  comprobante en el pedido (`_hwe_consent_receipt`) para que la Conversions API consulte la
  decisión **vigente** antes de enviar la compra.
- La purga (años desde la última decisión de cada comprobante) se configura en Control Center →
  Integraciones → «Conservar el registro de consentimientos».
- Una instancia con su propio banner debe llamar a estas rutas con la misma forma; el hueco de
  hidratación y la cola los resuelve `lib/analytics/consent-sync.ts`.

### SEO y GEO

- **`/robots.txt`**: `Content-Signal` (`search`, `ai-input`, `ai-train`) coherente con
  `geo.ai_crawlers`; veta `/api/` y las vistas privadas por idioma, pero permite `/api/og` y
  `/api/icon`. Con la config degradada (WordPress no respondió) omite `Sitemap:` y no se cachea.
- **`/llms.txt`** por idioma: el canónico en la raíz y `/<idioma>/llms.txt` para los demás
  (`app/[locale]/llms.txt`, fuera del matcher del proxy). 404 si `geo.llms_txt_enabled` es falso o
  la config está degradada. Copy en el namespace `llms`; el nombre del cargo del responsable no
  se publica (no tiene traducción).
- **Sitemap segmentado**: `/sitemap.xml` es el índice y cada fichero vive en
  `/sitemap/<segmento>.xml` (`paginas`, `catalogo`, `blog`; ciclo de vida distinto, mismas fuentes
  de invalidación que las páginas). Sin `lastmod` si no hay fecha real; vacío con la config
  degradada; una entidad sin traducción solo emite su idioma por defecto. El segmento `catalogo`
  recorre el catálogo completo por cursor (no se trunca a 100) y no anuncia las fichas con
  visibilidad de catálogo «oculta» (`HIDDEN`), que siguen accesibles por URL.
- **Open Graph**: Next REEMPLAZA `openGraph` entero en cada página que lo declara. Los campos de
  sitio (`og:site_name`, `og:locale` con país, `og:locale:alternate`, imagen por defecto) salen de
  `siteOpenGraph(config, locale, canónica)`; el layout no fija `title` ni `description` (los
  resuelve cada página). La imagen por defecto es la del panel, la tarjeta de marca de la
  instancia (`og-cards.json`, opcional: `npm run og:generate`) o la dinámica `/api/og?locale=`.
- **Título**: `siteTitle` aplica la plantilla (`seo.title_template`), retira del final la marca
  que el título ya trae (`seo.alternate_names`) y quita el sufijo si el título pasa de 60
  caracteres, sin recortar nunca el texto.
- **Vistas derivadas/privadas**: noindex declarado por la propia vista (no solo `Disallow`); el
  layout raíz ya NO declara `alternates` (antes la canónica de la home la heredaba toda página sin
  canónica propia).
- **Negocio local**: una entidad `LocalBusiness` por sede (Legal → Sedes) en `/contact`, con el
  tipo de `seo.business_type`.

### Utilidades y rendimiento

- **Lecturas a WordPress** (`fetchGraphQL`, `fetchRemoteConfig`): plazo por intento y hasta 3
  intentos ante red / plazo / 5xx; un 4xx o un error de GraphQL no se reintenta. Una lectura
  fail-soft del CMS (banners) usa `fetchWithRetry`. Variables: `GRAPHQL_TIMEOUT_MS`,
  `CONFIG_TIMEOUT_MS`.
- **Imágenes del CMS**: `CmsImage` + `lib/wordpress/media` (`wpUploadsUrl`, `resolveCmsAssetUrl`,
  `wpMediaInternalUrl`, `isSvgAsset`). Un SVG no pasa por el optimizador (`<img>` tal cual).
- **Prerender**: `PRERENDER_LONG_TAIL=all|none`; `NEXT_STATIC_GENERATION_MAX_CONCURRENCY`,
  `NEXT_STATIC_GENERATION_MIN_PAGES_PER_WORKER`, `NEXT_STATIC_PAGE_GENERATION_TIMEOUT`.
- **`?return=`**: `safeInternalPath` valida la ruta de vuelta tras el login; `loginHref(ruta)` /
  `withReturn("/register", ruta)` construyen el enlace.

### Blog

- **Lecturas** (`lib/blog/posts.ts`, tag ISR `posts`): `getPosts({ tag, author, category, search,
  after, first })` por cursor, `getFeaturedPostOverride` (Entradas → Post Destacado, el campo
  GraphQL `hweFeaturedPost`), `getTopTags` (ordenadas por uso en el servidor), `getPostsIndex`
  (archivo; un fallo del CMS se propaga) y `getPostSlugs` (recorre el cursor). Categorías:
  `lib/blog/categories.ts`.
- **`GET /api/blog/posts`** (scroll infinito, cacheable `public, max-age=60`): `after`, `first`
  (tope 24), `tag` (lista con comas, tope 10), `author`, `category`, `search` (tope 100
  caracteres, con límite de 30 por minuto y por IP), `locale`. El cursor debe ser base64 de
  WPGraphQL; lo que no lo es se descarta. Un fallo de WordPress responde 502 sin el mensaje.
- **Disponibilidad de una categoría** (`categoryPageAvailable`): con entradas publicadas y que no
  sea un cajón (`uncategorized`, `sin-categoria`). Es el ÚNICO criterio: la página responde 404,
  el sitemap no la lista y el enlace vuelve al listado cuando no se cumple.
- **Vistas derivadas** (`?tag=`, `?author=`, `?search=`, `?after=`): `noindex, follow`, sin
  `alternates`. `?category=x` a secas redirige (308) a `/blog/category/x` cuando esa página existe.
- **Cuerpo de una entrada** (`lib/blog/post-view.ts`): los productos enlazados desde el cuerpo se
  resuelven en una consulta; el enlace heredado de WooCommerce (`/producto/x/`) se reescribe a
  `/products/x` si el producto existe; los encabezados reciben `id` y alimentan la TOC; la
  recomendación `<!-- hwe-rec type="product|post" slug="x" -->` se sustituye por su tarjeta. La
  prosa se sanea tramo a tramo (`sanitizeHtml`); un marcador con slug no válido se descarta.
- **Fechas**: `date`/`modified` salen en la hora del sitio sin zona; el JSON-LD y `article:*_time`
  usan la variante `*Gmt` con `+00:00` (`wpDateIso`). «Actualizado el…» solo con un día o más
  de diferencia (`showUpdatedDate`).

## A.3 Config pública dinámica: `GET /wp-json/hwe/v1/config`

Fuente única de verdad de marca/negocio, sin auth, cacheable. Forma exacta (ver
`backend/wp-content/mu-plugins/hwe-control-center/Schema.php` para el detalle de cada campo
y `frontend/src/lib/config/types.ts` para el tipo TypeScript equivalente):

```
brand:  { name, tagline, description, url, logo, locale, og_image }
social: { twitter, instagram, facebook, linkedin, youtube, wikipedia, wikidata }
legal:  { company, nif, email, phone, mobile, address, city, region, postal_code,
          locations: { location_1…location_5: { label, address, city, region, postal_code,
                       maps, phone, hours, latitude, longitude } } }   // hasta 5 sedes, todas opcionales
design: {
  colors:     { brand, brand_dark, brand_light, secondary, secondary_dark, accent, surface, background, foreground }
  typography: { font_sans, font_url, font_heading, font_heading_url }
}
ecommerce: { currency, price_decimals, country, products_per_page, reviews_enabled, wishlist_enabled,
             coupons_enabled, search_enabled, checkout_login_required, free_shipping, … }
i18n:    { locales }                      // idiomas secundarios, p. ej. "en, fr"
auth:    { phone_login_enabled, phone_default_dial_code }
modules: { banners, shipping, product-landing, landings, consent-log, site-reviews,
           coupon-rules, customer-identity, external-integration, loyalty,
           memberships }   // boolean cada uno
instance: { … }                           // lo que añada la instancia (vacío en el framework)
banners: { enabled }
loyalty: { points_enabled, referrals_enabled, currency_name, currency_name_plural, currency_icon,
           point_value, min_redeem, max_redeem_per_order, max_redeem_percent,
           full_payment_enabled, min_cash_due,
           expiry_days, cashback_enabled, cashback_percent,
           events: { registration, review, birthday },
           referral_signup_points_l1, referral_signup_points_l2, commission_first_percent,
           commission_next_percent, level2_enabled, commission_level2_percent,
           commission_duration_days, transfers_enabled, transfer_min, transfer_fee_percent,
           transfer_daily_cap }
memberships: { card_enabled, card_verify_path, member_number_prefix }
integrations: { analytics_provider, analytics_id }
seo: { title_template, robots, google_site_verification, default_og, og_image, product_brand,
       shipping_amount, return_days, return_category, return_method, return_fees,
       handling_days, transit_days, alternate_names, organization_logo, founding_date,
       knows_about, founder_name, founder_role, founder_url, business_type }
geo: { ai_crawlers, llms_txt_enabled, faq, content_signal }
```

Valores **calculados** (no se guardan en el panel): `modules.<id>` es `true` si el plugin
del módulo está activo, y `ecommerce.price_decimals` son los decimales de precio de
WooCommerce (`null` sin Woo). Con un módulo en `false`, su UI no se pinta y sus rutas del BFF
responden 404.

**Campos traducibles.** Los marcados como traducibles (`brand.tagline`, `brand.description`,
`geo.faq`, `geo.faq_payments` y los que marque la instancia) llegan con una variante por
idioma de `i18n.locales`: `tagline_en`, `tagline_pt_br`… Vacía = usa el texto del idioma por
defecto. Léelos con `pickLocalized(entity, "tagline", locale)`.

Solo se exponen aquí los campos marcados `'public' => true` en `Schema.php` — los grupos
`payments`/`integrations.smtp_*`/`backups` con campos `secret` **nunca** aparecen en esta
respuesta (se gestionan aparte, ver `docs/CONFIGURATION.md`).

**Flags de funcionalidad opcional** (`ecommerce.reviews_enabled`, `wishlist_enabled`,
`coupons_enabled`, `search_enabled`, configurables desde `wp-admin → HWE Config`): **cualquier
frontend que los consuma debe ocultar la funcionalidad correspondiente cuando el flag es
`false`**, no solo dejar de anunciarla en metadatos. Esto es parte del contrato, no un detalle
de implementación de la UI heredada. El BFF también los respeta (`requireFlag`, como
`requireModule` con los módulos): con el flag apagado responden **404** `/api/store/reviews/*`
(reseñas de producto), `/api/store/wishlist` y `/api/store/products?include=` (lista de deseos),
y `/api/store/products?search=` (búsqueda; el listado sin búsqueda sigue sirviéndose). El 403 de
`/api/store/coupons` con `coupons_enabled` apagado se mantiene. Con `reviews_enabled` apagado
tampoco hay fan-out de reseñas de producto desde `POST /api/store/orders/[id]/review`: la reseña
del pedido (módulo `site-reviews`, independiente del flag) se guarda igual, pero `products: true`
se ignora. El JSON-LD de la ficha deja de emitir `aggregateRating`/`review` con el flag apagado.

- `brand.logo` — URL del logo de cabecera (medioteca WP). Vacío = renderizar `brand.name`
  como texto.
- `banners.enabled` — activa/desactiva el renderizado de banners. La autoría de los banners
  vive en el plugin **HWE Banners** (CPT `hwe_banner`, REST `hwe-banners/v1/banners`). Lector
  core: `lib/banners/getBanner(type, location, locale)` con ISR tag `banners`.
- `loyalty.*` — configuración pública del módulo `loyalty` (nombres de la unidad, valor, topes
  —`max_redeem_per_order` y `max_redeem_percent`—, eventos, comisiones y transferencias).
  `allowed_roles` es operativo y **no** se publica. `cashback_percent` y los porcentajes de
  comisión son **valor**: WordPress los convierte a unidades con `point_value`
  (`floor(importe / point_value)`); el frontend no calcula ganancias, solo presenta el valor del
  saldo (`pointsToMoney`). Con el
  módulo apagado llegan los defaults y las rutas del BFF responden 404; con `points_enabled`
  apagado, las de puntos; con `referrals_enabled` o `transfers_enabled` apagados, las suyas.
- `memberships.*` — solo los tres campos públicos del carné (`card_enabled`, `card_verify_path`,
  `member_number_prefix`). El TTL del token, los días de aviso y la exigencia de correo verificado
  son operativos y **no** se publican. Los niveles y los requisitos de catálogo son contenido y se
  leen por su propia ruta. Con el módulo apagado llegan los defaults y el BFF responde 404.

### Banners — `GET /wp-json/hwe-banners/v1/banners[/{type}]` (módulo `banners`)

Servido por el plugin **HWE Banners** (módulo activable). Público y cacheable (el frontend lo
consume con ISR, tag `banners`, invalidado al editar un banner). Gateado en el frontend por el
módulo `banners` **y** por `config.banners.enabled`.

Cada banner ocupa una **celda** = **tipo** (`carousel`, `band`, `allies`, `page_hero`, `landing`, y los
que añada la instancia) × **locación** (`home` por defecto, y las que añada la instancia). Solo se sirve
**un** banner publicado por celda (gana el modificado más recientemente).

- `GET .../banners?location=home&lang=es` → `{ "<tipo>": { "intervalMs": number, "slides": Slide[] } }`
  (`location` omitido o desconocido → la primera declarada).
- `GET .../banners/carousel?location=home&lang=en` → `{ "intervalMs": number, "slides": Slide[] }`.
  Un tipo inexistente es un **404** (`invalid_banner_type`); un tipo válido que la locación no admite es
  un 200 vacío.

`Slide` (camelCase, resuelto al idioma de `lang`; cualquiera de los idiomas del sitio): `id, bannerType,
location, type` (plantilla), `order, image, imageMobile, imageAlt, title, subtitle, cta, ctaHref, cta2,
cta2Href, badge, link, hideOverlay, showCopy`, y el visual decorativo: `decorMode` (`none|default|custom`),
`decorSvg` (`custom`) y `decorPreset` (**clave**, no URL; `default`). Las URL (`ctaHref`, `cta2Href`,
`link`) son **una sola ruta interna** por slide con el slug del idioma por defecto.

Lector core en `frontend/src/lib/banners/`:

- `getBanner(type, location, locale, { enabled })` y `getBanners(locale, location, { enabled })`;
  `getPageHeroSlide(location, locale)` y `getLandingHeroSlide(location, locale)`. Con el módulo apagado
  (`enabled: false`) devuelven vacío sin llamar a WordPress.
- `localize`: lleva los enlaces `/products/<slug>` y `/categories/<slug>` al slug del idioma pedido
  (cualquier idioma del sitio; nada que consultar en el idioma por defecto). Fail-soft y registrado.
- `decor-presets`: `resolveDecor(slide)` resuelve la **clave** del visual decorativo al archivo y a su
  política de inversión en tema oscuro, con los presets que la instancia declara en
  `InstanceRegistry.decorPresets` (el primero es el respaldo). **Las claves deben coincidir con las del
  filtro PHP `hwe_banners_decor_presets`**; un test de paridad lo comprueba con un fixture compartido.
- El carrusel del starter (`HeroCarousel`) pinta fotos: la home descarta los slides sin imagen. Una
  plantilla con texto solo o con visual decorativo la añade la instancia.

## A.4 Rutas públicas de la instancia (`InstanceRegistry.publicPaths`)

El framework escribe sus rutas en inglés porque son las carpetas de `app/[locale]/`
(`/products`, `/account`, `/checkout/return`…). Una instancia que sirve la tienda en otro idioma
no tiene que renombrar esas carpetas ni editar núcleo: declara el mapa en
`frontend/src/instance/registry.ts` con **solo datos**.

```ts
export const instanceRegistry: InstanceRegistry = {
  revalidationTags: [],
  publicPaths: {
    "/products": "/productos",
    "/categories": "/categorias",
    "/account": "/cuenta",            // /account/orders pasa a /cuenta/orders (hereda)
    "/checkout": "/pago",             // /checkout/return pasa a /pago/return
    "/cart": "/carrito",
    "/login": "/entrar",
    "/register": "/registro",
    // Por idioma, si un idioma conserva el tramo interno:
    // "/blog": { es: "/novedades", en: "/blog" },
  },
};
```

Reglas:

- La clave es la ruta **interna** (formato de `pathnames` de next-intl: `/products/[slug]` o un
  prefijo suyo). Un hijo sin entrada propia **hereda** el prefijo del padre; la entrada más
  larga manda.
- El valor es el tramo público, igual para todos los idiomas o por idioma. Un idioma sin entrada
  conserva el tramo interno.
- Se validan al arrancar (al cargar `i18n/routing.ts`): dos rutas a la misma URL, una ruta
  pública que sea la interna de otra vista, `api` o un código de idioma como primer tramo, o una
  entrada mal formada **abortan con un mensaje claro** en vez de servir URLs rotas.
- Sin `publicPaths` todo es identidad: las URL son las de siempre.

Qué hace el núcleo con el mapa: `localizedPath` (canónicas, `hreflang`, sitemap, JSON-LD,
`llms.txt`, enlaces de los correos) y la navegación (`Link`, `redirect`, `useRouter`,
`getPathname`) traducen el href interno al público; `proxy.ts` **reescribe** la URL pública a la
carpeta interna y responde **308** de la interna antigua a la pública (sin URL duplicada). Los
lectores que reconocen enlaces del CMS (banners, cuerpo del blog) aceptan las dos formas. No se
declaran `pathnames` de next-intl: solo localizan un href que sea una clave exacta del mapa, así
que una ficha concreta (`/products/vela-roja`) saldría sin traducir.

### Páginas propias y exclusiones del sitemap (`InstanceRegistry.sitemap`)

Opcional, y solo datos. Permite añadir al segmento `paginas` las vistas propias de la instancia y
retirar del sitemap y de `llms.txt` rutas del núcleo, de legal o de landings **sin editar
`app/_sitemap/entries.ts` ni `_llms/build.ts`**:

```ts
export const instanceRegistry: InstanceRegistry = {
  revalidationTags: [],
  sitemap: {
    // Rutas INTERNAS (formato de `pathnames`): se expanden a todos los idiomas con
    // alternates y x-default. La URL pública sale de `localizedPath`, así que
    // `publicPaths` sigue mandando. `changeFrequency` (def. "monthly") y
    // `priority` (def. 0.5, entre 0 y 1) son opcionales.
    pages: [{ path: "/example-page", changeFrequency: "yearly", priority: 0.3 }],
    // Rutas INTERNAS exactas, o un prefijo con /* (incluye fichas y landings).
    exclude: ["/legal/cookies", "/products/*"],
  },
};
```

Reglas:

- `pages` solo entra en `paginas`; una ruta que ya está en el núcleo no se duplica (gana la del
  núcleo) **salvo que la instancia la excluya y la redeclare**, en cuyo caso gana su declaración.
- Las `pages` declaradas **no se filtran por `exclude`**: la declaración explícita gana y la
  página se anuncia aunque también case con una exclusión (exacta o de prefijo).
- `exclude` se aplica a las entradas de los tres segmentos antes de expandir por idioma,
  comparando contra la ruta **interna**. `/products/*` excluye `/products`, sus fichas y sus
  landings, pero no `/products-extra`.
- En `llms.txt` se aplica a las páginas principales, a los documentos legales y a las guías;
  categorías y datos de negocio no cambian.
- Validación **fail-soft**: una entrada mal formada (sin barra inicial, con consulta o ancla,
  prioridad fuera de rango, frecuencia desconocida) se descarta y se registra con el evento
  `instance.sitemap.invalid_entry`; el sitemap sigue generándose. Sin el campo, todo es idéntico
  al comportamiento anterior.

### Redirecciones de migración (`InstanceRegistry.redirects`)

Lista de `{ from, to, permanent? }` que el **proxy** aplica antes de next-intl
(`lib/instance/redirects.ts`): `from` es la ruta pública tal como llega, exacta o un prefijo
con `/*`; `to`, una ruta del sitio o una URL `http(s)` (con `*` para copiar el resto del
prefijo). Responde **301** (302 con `permanent: false`) y conserva la consulta salvo que el
destino traiga la suya. Validación: sin `/api` ni `/_next`, sin consulta en `from`, sin bucles,
sin destinos que no sean rutas o `http(s)`, tope de 2000 reglas; una regla inválida se descarta
con el evento `instance.redirect_invalid` y `tests/unit/proxy-redirects.test.ts` compila el
registro real en modo estricto (CI falla). El matcher del proxy no cubre rutas con extensión.

## A.5 Design tokens (paleta/tipografía dinámica)

`design.colors.*`/`design.typography.*` (§A.3) están pensados para mapearse 1:1 a variables
CSS (`--color-brand`, `--color-brand-dark`, `--color-brand-light`, `--color-secondary`,
`--color-secondary-dark`, `--color-accent`, `--color-surface`, `--background`, `--foreground`,
`--font-sans`, `--font-heading`) para que cambiar la paleta en `wp-admin` no requiera tocar
código. El mapeo campo → variable vive en `frontend/src/lib/config/tokens.ts`; el mecanismo de
inyección en runtime (`components/ui/theme-tokens.tsx`) es parte de la UI heredada (Parte B),
pero el **contrato de qué 9 colores + 2 tipografías existen** es estable.

## A.6 Webhooks salientes (WordPress/WooCommerce → tu frontend)

- **Pedido creado/actualizado**: `POST {frontend}/api/webhooks/woocommerce/order-{created,updated}`,
  firmado con `WC_WEBHOOK_SECRET` (HMAC). Payload = el de WooCommerce; el estado real se
  calcula por diff contra el último conocido (Redis), porque el payload solo trae el estado
  actual.
- **Revalidación de catálogo**: `POST {frontend}/api/revalidate`, firmado con
  `HWE_REVALIDATION_SECRET` (cabecera `X-HWE-Signature`).
- **Pago**: `POST {frontend}/api/payments/webhook/[provider]` (Wompi, Aval Pay; `noop` solo si es
  la activa). La firma, el importe y la moneda los verifica el provider (`lib/payments/`); la
  pasarela se configura en el Control Center (ver «Pagos» en A.2).

## A.7 Menús de navegación (WordPress → cualquier frontend)

Los menús se gestionan en **wp-admin → Apariencia → Menús** y se exponen por WPGraphQL.
El tema `hwe-headless-base` registra cuatro *locations*; asignar un menú a una location
es la "selección del menú activo":

| Location | Enum WPGraphQL | Uso |
|---|---|---|
| `primary` | `PRIMARY` | Navegación principal (locale por defecto, `es`) |
| `primary_en` | `PRIMARY_EN` | Navegación principal en `/en` |
| `footer` | `FOOTER` | Columnas del pie (locale por defecto) |
| `footer_en` | `FOOTER_EN` | Columnas del pie en `/en` |

**Regla de contenido:** los items deben ser **enlaces personalizados con rutas relativas del
frontend** (`/products`, `/blog`, `/contact`…). No uses páginas/entradas de WP: sus permalinks
apuntan a WordPress, no al frontend.

Query de referencia:

```graphql
query MenuByLocation($location: MenuLocationEnum!) {
  menuItems(where: { location: $location }, first: 100) {
    nodes { id parentId label uri }
  }
}
```

En el frontend heredado ya hay un consumidor listo: `lib/navigation/menu.ts`
(`getMenu(area, locale)` — ISR 5 min, tag `menus`, fail-soft a `null`). Un item raíz **con
hijos** se renderiza como dropdown (header) o columna (footer). Si la location no tiene menú
asignado, el frontend heredado usa su navegación local por defecto.

---

# Parte B — Lo heredado al clonar (código de instancia, NO mantenido por el framework)

> Todo lo de aquí abajo describe el estado de `components/**` (salvo `ui/`) y
> `app/[locale]/*` **al momento de escribir esto**. Úsalo como inventario de tu punto de
> partida, no como referencia de una librería que vaya a actualizarse. Si lo reescribes por
> completo, este documento deja de aplicar a tu proyecto — eso es exactamente lo esperado.

## B.1 Inventario de páginas (`frontend/src/app/[locale]/`)

Leyenda: ✅ **completa** (usa datos reales, sin componentes de relleno) · 🧱 **mínima**
(funciona pero es un esqueleto — buen punto de partida, no un diseño terminado).

| Ruta | Estado | Componentes clave | Notas |
|---|---|---|---|
| `/` (home) | 🧱 | `HeroCarousel` (gateado por `banners.enabled`), `PostCard`, `FaqSection` | Título/descripción + carrusel de banners (Banner Manager, §A.3) + últimos posts + FAQ (`config.geo.faq`). No usa `PageHero`, `TrustBar`, `CategoryCard` — disponibles, no conectados aquí. Primer candidato a rediseñar por negocio. |
| `/products` | 🧱 | `InfiniteProductGrid` | Filtro con un `<form>` HTML plano (`search`/`category`/`minPrice`/`maxPrice`/`sort` por query string). No usa `FilterChips`, `SortDropdown`, `SearchModal`, `CategoryCard` — todos disponibles, ninguno conectado. |
| `/products/[slug]` | ✅ | `ProductActions`, `VariationSelector`, `ReviewForm` (gateado), `ProductGrid` (relacionados) | Ficha de producto completa: imagen, precio, variantes, JSON-LD `Product` + `BreadcrumbList`. |
| `/products/[slug]/learn` | ✅ | `LandingProductView` | Landing del producto (módulo `product-landing`, §A.2): 404 con el módulo apagado o sin publicar; JSON-LD `Article` + `Product` + `FAQPage`. |
| `/learn` | ✅ | — | Índice del área educativa (módulo `landings`, §A.2): guías del panel y guías de producto agrupadas por categoría. 404 con el módulo apagado. |
| `/learn/[slug]`, `/[landingPath]` | ✅ | `LandingEditorialView` | Landing editorial del área y de la raíz (módulo `landings`, §A.2): 308 al tramo canónico de cada idioma, JSON-LD `Article` o `Person` + `FAQPage`. |
| `/categories/[slug]` | ✅ | `ProductGrid` | Listado por categoría con JSON-LD breadcrumb. |
| `/blog` | ✅ | `PostCard` | Listado de entradas. |
| `/blog/[slug]` | ✅ | — | Entrada individual, JSON-LD `Article`. |
| `/about` | ✅ | — | 100% `getSiteConfig()` (descripción, fundador, áreas de expertise, JSON-LD `Person`) — sin componentes de layout nuevos, buen ejemplo de vista "de config pura". |
| `/contact` | ✅ | `ContactForm` | Usa `config.legal.email` + `config.social.*`. Las sedes (`legal.locations`) y el teléfono (`businessPhone(config)`) **sí están en la config** y alimentan el JSON-LD `LocalBusiness` (`getConfiguredLocations(config)`), pero la vista no las pinta: pásalas a `ContactForm` (`branches`, `phone`) si el negocio quiere mostrarlas. |
| `/cart` | ✅ | `CartView` | — |
| `/checkout`, `/checkout/return` | ✅ | `CheckoutForm` | Cupón gateado por `coupons_enabled` (ver A.3). Selector de medio de pago (`paymentChoices`) y aviso de modo de pruebas (`gatewaySandbox`), ver «Pagos» en A.2. |
| `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email` | ✅ | `*-form.tsx` de `components/auth/` | Flujo de auth completo (JWT + 2FA opcional). |
| `/account/*` | ✅ | `components/account/*` | Perfil, pedidos, direcciones, wishlist (gateado), 2FA, cambio de contraseña. |
| `/legal/[slug]` | ✅ | — | Páginas legales (privacidad/cookies/términos/devoluciones) desde `i18n/messages`. |

**Lectura clave**: lo que resuelve funcionalidad de cuenta/carrito/checkout/auth está completo
y probado — normalmente no necesitas tocarlo. Lo que suele reescribirse por completo por
instancia son las **vistas de marketing/descubrimiento** (home, listado de productos): ahí es
donde vive la mayoría del trabajo de "construir el frontend de un negocio concreto".
`docs/FRONTEND_BUILD.md` ayuda a decidir qué construir ahí.

## B.2 Catálogo de componentes por dominio

Formato por componente: **qué hace** — *de dónde saca datos* — estado de conexión.

### `components/layout/` — armazón de página

- **`SiteHeader`** — nav + carrito + selector de idioma + modo oscuro. *Traducciones (`nav`,
  `site`) + `useCart()`.* ✅ Conectado en `app/[locale]/layout.tsx` (todas las páginas).
- **`SiteFooter`** — columnas de enlaces + redes sociales + copyright. *`getSiteConfig()`
  (tagline, `config.social.*`) + traducciones (`footer`, `nav`, `site`).* Acepta `columns?:
  FooterColumn[]` opcional — por defecto usa las páginas ya existentes (tienda/blog/sobre
  nosotros/contacto/legal), sin inventar categorías de catálogo. Pásalo explícito si el
  negocio necesita otra estructura (p. ej. columnas por categoría real de producto). ✅
  Conectado en `layout.tsx`.
- **`TrustBar`** — franja de 4 badges de confianza bajo el header. *Traducciones
  (`trustBar.*`: `natural`, `freeShipping`, `returns`, `securePayment`).* 🧱 **Disponible,
  no conectada a ningún layout.** Las 4 etiquetas son copy de ejemplo (p. ej. "100% Natural"
  no encaja con cualquier vertical) — si la usas, ajusta las labels/i18n al negocio o
  sustituye alguna por otra genérica (envío/devoluciones/pago seguro si encajan mejor).
- **`HeroCarousel`** — carrusel de slides con imagen/título/CTA. *Recibe `slides: HeroSlide[]`
  por prop — no hace fetch propio.* ✅ Conectado en la home vía Banner Manager
  (`config.banners.*` + `parseBanners`, gateado por `banners.enabled`).
- **`PageHero`** — cabecera de página con breadcrumb opcional, título/subtítulo, fondo con o
  sin overlay. *Recibe todo por props.* 🧱 Disponible, no conectada — buen candidato para dar
  identidad a `/products`, `/blog`, `/about` en vez del `<h1>` plano actual.
- **`Breadcrumb`** — usado internamente por `PageHero`; también usable suelto.

### `components/marketing/` — tarjetas de contenido editorial

- **`AllyCard`** — tarjeta de "aliado/partner" (logo, nombre, descripción, link). *Recibe
  todo por props.* 🧱 Disponible, no conectada. Si el negocio tiene partners/aliados reales,
  necesitas una fuente de datos propia (un CPT vía mu-plugin, o un array estático) — eso es
  100% código de tu instancia, no algo que el framework provea.
- **`VideoCard`** — tarjeta de vídeo (thumbnail, canal, vistas, duración). *Recibe todo por
  props.* 🧱 Disponible, no conectada.

### `components/products/` — catálogo

- **`ProductCard`** — tarjeta vertical (grid). *`CatalogProduct` por prop + `getSiteConfig()`
  interno para gatear wishlist.* ✅ Usada en `ProductGrid`/`InfiniteProductGrid`.
- **`ProductCardHorizontal`** — tarjeta horizontal compacta (pensada para resultados de
  búsqueda/listas densas). *Recibe `product: CatalogProduct` + rating/reviews opcionales por
  prop.* 🧱 Disponible; usada internamente por `SearchModal`.
- **`ProductGrid`** / **`InfiniteProductGrid`** — grid con/sin scroll infinito. ✅ Conectadas.
- **`CategoryCard`** — tarjeta de categoría con 4 variantes de color temático
  (`brand`/`secondary`/`accent`/`surface` — mapeadas a los design tokens, ver A.4). *Recibe
  `href`/`label`/`image` por prop.* 🧱 Disponible, no conectada — candidata natural para la
  home o el header de `/products`.
- **`FilterChips`** — chips de filtro activo/inactivo. *Controlado: recibe `options`/`active`/
  `onChange` por prop, sin lógica de URL propia.* 🧱 Disponible, no conectada — `/products`
  sigue usando un `<form>` HTML plano.
- **`SortDropdown`** — desplegable de ordenación. *Igual de controlado que `FilterChips`.* 🧱
  Disponible, no conectado.
- **`SearchModal`** — modal de búsqueda global (productos vía `ProductCardHorizontal` + otros
  resultados). *Presentacional puro: recibe `productResults`/`otherResults` ya cargados por el
  padre; `onSearch` es un callback, el componente NO llama a ninguna API.* 🧱 **Tiene un TODO
  explícito en el código**: conectar `onSearch` a la Store API (productos) y a WPGraphQL
  (posts/páginas). Si lo conectas, gátalo con `config.ecommerce.search_enabled`.
- **`ProductActions`**, **`VariationSelector`**, **`ReviewForm`**, **`WishlistButton`** — ✅
  conectados en la ficha de producto.

### `components/forms/` — formulario de contacto

- **`ContactForm`** — formulario + panel de "canales de contacto". Props: `email?`, `phone?`,
  `socials?: { facebook?, instagram?, youtube? }`, `branches?: Branch[]`. **Todo opcional y
  con default vacío** (una sección se oculta si no le pasas datos). Envía a `POST /api/contact`
  (§A.2; el BFF lo reenvía a `hwe/v1/contact`, que lo entrega por email). ✅ Conectado en
  `/contact`. `country-selector.tsx` y `contact-form.data.ts` (`REQUEST_TYPES`/`COUNTRIES`)
  son genéricos, sin datos de negocio.

- **`geo-fields.tsx`** (`CityField`, `DivisionField`) — ciudad con sugerencias y división
  (select de lista cerrada o texto libre) según el paquete de país; ver «Paquetes por país» en
  §A.2. ✅ Conectados en el checkout y en `/account/addresses`.

### `components/ui/` — primitivas (esto sí es núcleo del framework, ver `AGENTS.md §1.1`)

`Button`, `Input`, `Select`, `Textarea`, `Checkbox`, `Card`, `Badge`, `Modal`, `Alert`,
`Skeleton`, `Spinner`, `Paginator`, `QuantityCounter`, `DarkModeToggle`. Sin dependencia de
negocio — vocabulario visual base. A diferencia del resto de `components/`, estas sí se
consideran parte del framework: úsalas en vez de reinventar botones/inputs sueltos.

### Resto (funcionalidad de producto, normalmente no se rediseña por instancia)

`components/cart/`, `components/checkout/`, `components/account/`, `components/auth/`,
`components/analytics/`, `components/i18n/`, `components/blog/`, `components/seo/` —
implementan flujos del BFF (carrito, checkout, cuenta, auth). Aunque técnicamente son
"heredados" igual que el resto de `components/`, en la práctica cambian poco entre
instancias porque siguen de cerca el contrato de la Parte A. Tócalos solo si el negocio pide
una funcionalidad de cuenta/carrito distinta al estándar.

## B.3 Cómo componer una vista (si partes de lo heredado)

1. **Server Component** (la página en `app/[locale]/…/page.tsx`): llama a
   `getSiteConfig()` / `lib/catalog`/`lib/blog` según lo que necesites, con `await Promise.all(...)`.
2. **Pasa los datos como props** a componentes de `components/` — no dupliques fetch en
   componentes cliente si el padre ya tiene el dato.
3. **Gatea funcionalidad opcional** con los flags de `config.ecommerce.*` (§A.3) — nunca la
   muestres incondicionalmente si tiene un flag correspondiente.
4. **i18n**: añade las keys que uses en `i18n/messages/es.json` **y** `en.json` en paralelo
   (mismo namespace/estructura). Usa `Link`/`redirect` de `@/i18n/navigation`, no de `next/*`.
5. **Diseño**: usa las clases utilitarias de marca (§A.5: `brand`, `brand-dark`, `brand-light`,
   `secondary`, `secondary-dark`, `accent`, `surface`, `font-sans`, `font-heading`) en vez de
   colores/fuentes hardcodeados.
6. **SEO** (si la página es pública e indexable): `generateMetadata` con `pageMetadata(...)`
   (`lib/seo/page-metadata.ts`: título con la plantilla del sitio, descripción por palabra
   completa, canónica + hreflang y los campos de sitio de Open Graph), la ruta en
   `InstanceRegistry.sitemap.pages` si debe indexarse (**no** edites `app/_sitemap/entries.ts`),
   y JSON-LD si aplica (`lib/seo/jsonld.ts`:
   `Product`, `Article`/`BlogPosting`, `Person`, `BreadcrumbList`, `CollectionPage`,
   `LocalBusiness`, `VideoObject`, `Service`, `WebSite`/`Organization`; siempre serializado con
   `safeJsonLd`). Una vista privada declara `privateMetadata()`; una vista derivada de un
   listado (`?search=`, `?after=`) va `noindex, follow` y sin `alternates`. Ver § «SEO y GEO».
7. **`setRequestLocale(locale)`** en páginas estáticas; `force-dynamic` solo si depende del
   usuario/sesión (ver `AGENTS.md §7`).
8. Verifica con `npx tsc --noEmit`, `npm run test`, y `npx next build` antes de dar la vista
   por terminada (`AGENTS.md §8`).

## B.4 Si NO partes de lo heredado (frontend nuevo desde cero)

Todo lo de la Parte A sigue aplicando igual — es el contrato, no importa el stack. Lo único
que necesitas construir tú mismo:
- Un cliente GraphQL contra WPGraphQL/WooGraphQL (§A.1).
- Llamadas `fetch` al BFF con `credentials: "include"` + el flujo CSRF (§A.2).
- Lectura de `/wp-json/hwe/v1/config` y aplicación de los design tokens (§A.5) al sistema de
  diseño que elijas.
- Manejo de los flags de funcionalidad opcional (§A.3) en tu propia UI.

No hay SDK ni cliente oficial más allá del código Next.js heredado — si lo extraes como
referencia de "cómo se hizo una vez", perfecto; si no, la Parte A es toda la documentación que
necesitas.
