# HWE Memberships (módulo `memberships`)

**Membresías** para el framework: niveles y beneficios editables, carné digital con verificación
por QR, compras y renovaciones, catálogo exclusivo y regalos por referido. Es un módulo opcional
del catálogo, como `loyalty` o `shipping`, y **no trae vistas**: entrega la configuración, las
rutas internas que consume el BFF, la extensión de la Store API y la lógica del backend. La UI
pública la construye el frontend de cada instancia con el contrato de
`docs/FRONTEND_CONNECT.md` (Parte A).

> **Estado: completo.** Incluye niveles, beneficios, concesiones y su ciclo (alta, renovación,
> mejora, cola, caducidad, congelación, retirada y revocación), compra ligada a pedidos, carné
> digital, privacidad, operaciones en masa, administración en wp-admin y las rutas internas de
> lectura. El BFF del núcleo expone `/api/memberships/*`, `/api/store/cart/free-delivery` y la
> extensión `hwe-memberships` del carrito.

## Qué aporta

| Pieza | Detalle |
|---|---|
| `hwe-memberships.php` | Clase `HWE_Memberships`: comprueba WooCommerce y arranca el módulo en `plugins_loaded` (prioridad 20). Sin WooCommerce no arranca y lo registra. |
| `includes/Settings.php` | Puebla el grupo reservado `memberships` del Control Center y expone la lectura saneada (`get()`, `moduleActive()`, `cardActive()`, `participantRoles()`). |
| `includes/Schema.php` | Cinco tablas InnoDB y migración perezosa por `hwe_memberships_db_version`. |
| `includes/Levels.php` | CPT `hwe_membership_level` con rango, icono, color, precio comprable, antigüedad mínima y beneficios por nivel. |
| `includes/Timeline.php` | Línea de tiempo de concesiones: solapes, cola sin fecha, duraciones indefinidas y desplazamiento por congelación. |
| `includes/Grants.php` | Servicio transaccional único de escritura: alta, renovación, mejora, retirada, congelación, revocación, cupos de envío y contador de regalos. |
| `includes/Events.php` | Historial (`hwe_memberships_events`) y hooks del ciclo, disparados **después** del COMMIT. |
| `includes/Members.php` | Lectura del estado efectivo del socio (tabla `hwe_memberships_members`). |
| `includes/Purchase.php` | Reglas de compra y activación al cobrar el pedido; reversión por cancelación o reembolso total. |
| `includes/Lifecycle.php` | Ciclo de vida del plugin: retira de la venta los productos de membresía al desactivar, los restaura al activar y deja programada la conciliación de los pedidos cobrados durante el apagón. |
| `includes/Products.php` | Vínculo producto ↔ nivel y duración; pestaña «Membresía» de la ficha y requisitos de acceso. |
| `includes/Access.php` | Única fuente del requisito efectivo de un producto o categoría y del acceso anticipado. |
| `includes/Teaser.php` | Ficha de escaparate en Store API, carrito y WC REST (`wc/v3`). |
| `includes/Graphql.php` | Campo `hweMembership` en `Product` y `ProductCategory`, con recorte por lista blanca. |
| `includes/Benefits/` | Registro de tipos de beneficio y los siete del núcleo. |
| `includes/Pricing.php` | Descuento de miembro en el carrito y el pedido, y `priceFor()` para el BFF. |
| `includes/StoreApi.php` | Extensión `hwe-memberships` del carrito y bloqueo de compra por requisito. |
| `includes/Card.php` + `includes/Rest/CardRoutes.php` | Carné digital: emisión, hash del token, caducidad, revocación y verificación pública. |
| `includes/Rest/ReadRoutes.php` | Rutas internas de niveles, estado, historial, precios y ficha. |
| `includes/Bulk.php` + `includes/Admin/` | Operaciones en masa, pantallas, perfil de usuario, columna, caja del pedido y exportación CSV. |
| `includes/Privacy.php` | Exportador y eliminador de datos personales, y limpieza al borrar la cuenta. |
| `includes/LoyaltyBridge.php` | Único punto de conversación con el contrato de `loyalty`. |

## Arranque, datos y ajustes

El plugin arranca en `plugins_loaded` con prioridad tardía, como `HWE_Loyalty::boot()`: si
WooCommerce no está activo, deja un aviso en wp-admin y no registra nada. La activación crea o
repara las tablas y programa la tarea diaria; `init` repara ambos si la instalación llegó por
otra vía o una tabla se borró a mano.

**Tablas** (prefijo `{$wpdb->prefix}`):

| Tabla | Para qué |
|---|---|
| `hwe_memberships_grants` | Cada trozo de tiempo de un nivel que tiene un usuario. Clave de idempotencia **única**, origen y referencia, duración, fechas efectivas, estado, cupo de envíos, regalos y congelación. |
| `hwe_memberships_members` | Una fila por socio: nivel efectivo, fin de cobertura, congelación y número de socio **único**. |
| `hwe_memberships_events` | Historial: tipo, niveles anterior y nuevo, actor, datos estructurados y fecha. |
| `hwe_memberships_card_tokens` | Tokens del carné guardados como **hash** SHA-256, caducidad y revocación. Nunca el token en claro. |
| `hwe_memberships_batches` | Estado reanudable de una operación en masa: selección, parámetros, totales, cursor y estado. |

**Ajustes** (`config.memberships`). Los tres primeros son públicos y forman el espejo TypeScript
de `frontend/src/lib/config/{types,defaults}.ts`; los demás son operativos del backend.

| Campo | Tipo | Default | ¿Público? | Qué hace |
|---|---|---|---|---|
| `card_enabled` | booleano | `false` | sí | Enciende el carné digital y sus rutas. |
| `card_verify_path` | texto | `/verify` | sí | Ruta del frontend a la que apunta el QR. Se normaliza a una ruta absoluta desde la raíz. |
| `member_number_prefix` | texto | vacío | sí | Prefijo del número de socio. Vacío = sin prefijo. |
| `card_token_ttl_hours` | entero | `24` | no | Horas de vigencia del token, recortadas a 1–720. |
| `expiring_notice_days` | entero | `7` | no | Días de antelación del aviso de caducidad; un valor negativo se recorta a 0. |
| `referral_gift_requires_email_verification` | booleano | `true` | no | El regalo al referido solo se concede al verificar su correo. |
| `plans_earn_points` | booleano | `false` | no | Los planes generan puntos de fidelización (base, cashback, comisiones y bono). Apagado, la línea del plan no puntúa; sus puntos de activación se abonan igual. |

`HWE_Memberships_Settings::get()` nunca falla: lee cada valor con
`\HWE\ControlCenter\Storage::get` y, sin Control Center, usa `DEFAULTS`. `moduleActive()` exige el
plugin cargado y, si el Control Center está disponible, que el módulo figure en `active_plugins`.
`cardActive()` exige además `card_enabled`.

## Niveles y beneficios

Un nivel es un CPT **no público con UI** (`hwe_membership_level`). No hay niveles, rangos,
nombres ni colores predefinidos: los crea el editor. Los campos del nivel son
`_hwe_membership_rank` (entero ≥ 1, único entre publicados), `_hwe_membership_icon` (URL o id de
la medioteca), `_hwe_membership_color`, `_hwe_membership_purchasable`,
`_hwe_membership_min_account_age_days` y `_hwe_membership_benefits` (configuración por tipo). El
nombre, la descripción y el slug usan los campos nativos y son traducibles con
`hwe-content-i18n` (`hwe_translatable_fields`).

Cada tipo de beneficio declara su clave, sus campos por nivel, su dependencia (`loyalty`,
`loyalty:points`, `loyalty:referrals`, `shipping` o ninguna), sus datos públicos y sus hooks. Los
del núcleo:

| Clave | Campos | Depende de | Qué hace |
|---|---|---|---|
| `info_perk` | `title`, `text` (traducibles) | — | Ventaja informativa para la ficha y la comparativa. |
| `early_access` | — | — | Permite comprar antes de la fecha de apertura de un producto. |
| `member_discount` | `percent` (0–100), `scope` (`all`/`categories`), `categories` | — | Descuento de socio en el carrito y el pedido. |
| `free_deliveries` | `count` (≥ 0) | — | Cupo de envíos gratis por concesión. |
| `activation_points` | `points` (≥ 0) | `loyalty:points` | Abona puntos al cobrar una concesión de origen `order`. |
| `referral_bonus` | `percent` (0–100) | `loyalty:referrals` | Comisión extra para el referidor N1. |
| `referral_gift` | `level`, `days` (≥ 1), `max_per_period` (≥ 1) | `loyalty:referrals` | Regala una membresía al referido, con tope por periodo. |

Una configuración de un tipo cuya dependencia está apagada **se conserva** y deja de ofrecerse al
cliente. Un tipo que este despliegue no conoce también se conserva al guardar otro nivel. El
registro es ampliable con `hwe_memberships_benefit_types`; el ejemplo vivo está en
`examples/instance-minimal/backend/wp-content/mu-plugins/instance/70-example-membership-benefit.php`.

## Catálogo exclusivo (ficha de escaparate)

El requisito efectivo combina el mayor rango de la cadena de categorías con el del producto y,
opcionalmente, una fecha de apertura (`_hwe_membership_opens_at`, UTC). Quien no cumple **sí ve**
el producto en listados, búsqueda y sitemap, pero solo con la ficha de escaparate: id, nombre,
slug, tipo, imagen principal, precio público (rango en variables), categorías, el requisito y la
marca `locked`. No salen descripción, descripción corta, galería, atributos, variaciones ni su
stock, campos de producto (`hweFields`), landing, SEO ni enlaces de descarga.

El recorte se hace **en WordPress**, nunca en el frontend:

| Vía | Implementación |
|---|---|
| GraphQL (`Product`, `SimpleProduct`, `VariableProduct`…, listados, búsqueda, relacionados, venta cruzada, `ProductCategory`) | `includes/Graphql.php`: `hweMembership { requiredLevel { slug rank name } opensAt locked }` y lista blanca en `graphql_resolve_field`. Las variaciones directas (`productVariation`, `node`) se anulan si el padre es teaser. |
| Store API `wc/store/v1/products` (listado y ficha), variaciones y carrito | `includes/Teaser.php` con `rest_request_after_callbacks`. Del carrito se conservan clave, cantidad, precios, totales y extensiones. |
| WC REST `wc/v3/products` y `…/variations` | `woocommerce_rest_prepare_product_object` / `…_variation_object`. Con el secreto interno del BFF se aplica la ficha como visitante; el panel y las integraciones con `ck/cs` ven la ficha completa. |
| REST de WordPress `wp/v2/product` (WooCommerce lo registra con `show_in_rest`) | `rest_prepare_product`: lista blanca (id, fechas, slug, estado, enlace, título, imagen destacada, categorías y etiquetas) más el requisito; `content`, `excerpt`, `meta` y lo que añada otro plugin se descartan. El `context=edit` de quien puede editar el producto no se toca. |
| Búsqueda (GraphQL `where: { search }`, `wp/v2/product?search=`, Store API `?search=` y `?sku=`) | Comparar contra la descripción, el extracto o el SKU de un producto bloqueado sería un oráculo para sondear su contenido. Con algún requisito en el catálogo (`HWE_Memberships_Access::anyRequirementExists()`), `post_search_columns` limita la búsqueda de productos al **nombre** y `graphql_product_connection_query_args` reencamina la búsqueda de WooGraphQL (que compara descripción, extracto, SKU y slug) por la búsqueda de WordPress. En la Store API, `pre_get_posts` hace lo mismo con sus parámetros propios `search` y `sku` (WooCommerce los compara contra el SKU además del título). Sin requisitos en el catálogo, la búsqueda no cambia. |

En REST el requisito viaja en `hwe_membership.required_level` (slug, rank y name) y
`hwe_membership.opens_at`; en GraphQL se llama `hweMembership`. En `wc/v3` para el BFF se
conservan además `status`, `purchasable`, `stock_status`, `manage_stock`, `stock_quantity` y
`backorders_allowed` (son disponibilidad operativa, no contenido).

**Seguridad falla cerrada:** si el requisito no se puede evaluar, el producto se trata como
bloqueado y se registra. Un rango sin nivel publicado también bloquea a todos, sin fingir que es
público. El personal de la tienda no tiene atajos en la tienda pública. El filtro
`hwe_memberships_product_requirement` solo puede **endurecer** el requisito; los niveles se
recalculan y no se aceptan del filtro.

Otras rutas propias que devuelven producto se revisaron y no se tocan porque no exponen
contenido: `hwe/v1/best-sellers`, cupones públicos, alcance del envío gratis, `hwe-loyalty` y
`hwe-site-reviews`. Una ruta nueva de una instancia que devuelva contenido debe aplicar
`HWE_Memberships_Teaser`.

## Compra, activación y reversión

Un producto de membresía es un producto **simple y virtual** ligado a un nivel y una duración
(`_hwe_membership_level_id`, `_hwe_membership_days`; `0` = indefinida). Puede haber varios por
nivel (mensual, anual…). El producto no guarda beneficios: viven en el nivel. Si el nivel no es
comprable, el producto queda en borrador para no venderse.

Reglas de compra (Store API al añadir al carrito y al crear el pedido; checkout clásico como
respaldo): exigen sesión aunque la tienda permita invitados, una sola línea de membresía con
cantidad 1, un nivel comprable, la antigüedad mínima, una cuenta no pendiente
(`hwe_account_is_pending`) y que el usuario no tenga ya ese nivel o uno superior **indefinido**.
Los motivos estables son `membership_login_required`, `membership_one_per_cart`,
`membership_not_purchasable`, `membership_account_too_new`, `membership_account_pending` y
`membership_already_indefinite`; el `RouteException` usa `hwe_<motivo>`.

La membresía se activa **solo al cobrar** (`processing`/`completed` con `is_paid()` o
`payment_complete`); los medios que cobran al entregar (por defecto `cod`) esperan a `completed`,
ampliables con `hwe_memberships_collected_on_completion`. Cada línea deja una concesión con la
clave `order:<pedido>:item:<línea>` y guarda su id en la línea. Esa clave y la meta del pedido
hacen que `processing` → `completed` → `payment_complete`, un webhook repetido o una ida y vuelta
de estado **no concedan dos veces**. `cancelled` o `refunded` **total** revoca las concesiones;
un reembolso parcial no revierte. Un pedido de invitado no activa nada. Comprar estando
congelado se permite: la concesión se coloca en la línea de tiempo y se desplaza al descongelar.

## Extensión de la Store API

`extensions.hwe-memberships` del carrito publica:

| Campo | Contenido |
|---|---|
| `level` | `{id, slug, rank, name}` del nivel efectivo o `null` (sin membresía o congelado). |
| `member_discount` | `{total, lines}` con importes en unidades menores de la moneda, como string. |
| `free_delivery` | `{available, remaining, requested, applied, reason}`. `requested` refleja solo lo que declaró el cliente. |
| `blocked_items` | Lista de `{key, product_id, reason, min_rank, level_slug, level_name, opens_at}` de las líneas que hoy no puede comprar. |
| `purchase_notice` | `renewal`, `upgrade`, `scheduled` o `null` para la línea de membresía. |

El navegador **solo declara que quiere usar un envío gratis** en el update callback
(`{free_delivery: bool}`); cualquier otra clave o un valor no booleano se ignora y se registra. El
filtro interno `hwe_memberships_store_api_cart_data` permite a otras piezas aportar sus ramas;
`level`, `blocked_items` y `purchase_notice` siempre se recalculan en el módulo para que un filtro
no pueda retirar una línea bloqueada. Con el módulo apagado no se registra la extensión.

## Descuento de miembro

El nivel configura un porcentaje (0–100) y el alcance: todo el catálogo o una lista de categorías
(con sus descendientes). Se aplica solo al socio con el beneficio activo y nunca a las líneas de
membresía. La mecánica rebaja el **precio de línea** en
`woocommerce_before_calculate_totals` (prioridad 999), partiendo del precio que la tienda cobraría
hoy (oferta incluida), nunca del ya rebajado. Así los impuestos se calculan sobre lo rebajado, el
cupón se aplica después y la base de ganancias de `loyalty` lo refleja sola. En cada recálculo se
restaura el precio original si el socio perdió el nivel, el beneficio se apagó o la línea dejó de
estar en el alcance.

`HWE_Memberships_Pricing::priceFor( int $userId, int $productId, ?int $variationId = null ): array`
devuelve `regular`, `price`, `member_price` y `percent` (strings decimales de WooCommerce). En el
pedido, cada línea guarda `_hwe_membership_line_original` y `_hwe_membership_line_discount` por
unidad; el pedido guarda `_hwe_membership_discount_level` y `_hwe_membership_discount_percent`.
La caja del pedido añade su fila con `hwe_memberships_order_box_rows`.

## Envíos gratis por periodo

El nivel configura `free_deliveries.count`. El cupo es de la **concesión**: una renovación empieza
con el suyo y lo no usado muere con la concesión anterior. Los contadores
`shipping_quota_used` / `shipping_quota_reserved` solo los mueve `HWE_Memberships_Grants`, siempre
con la fila del socio bloqueada y en la misma transacción que comprueba el disponible.

| Momento | Qué pasa |
|---|---|
| Carrito | El cliente declara `free_delivery: true`. Si el beneficio está activo y queda cupo, la tarifa elegible baja a 0 y la extensión publica el estado. |
| Crear el pedido | Se **reserva** una unidad. Sin cupo, el checkout responde 409 `hwe_membership_free_delivery_unavailable`. El pedido guarda `_hwe_membership_free_delivery_grant`, `_hwe_membership_free_delivery_state` y `_hwe_membership_free_delivery_user`. |
| Cobrar | Se **consume** la reserva. Las notificaciones repetidas, incluso simultáneas, no consumen dos veces: el pedido se relee con la fila del socio bloqueada. |
| Cancelar, fallar antes de cobrar, reembolsar del todo, o borrar un pedido que aún solo tenía la reserva | Se **devuelve** la reserva o el consumo. El borrado cubre los borradores de la Store API que WooCommerce elimina a las 24 h (`woocommerce_before_delete_order`). |

Con el módulo `shipping` activo, esta clase no toca las tarifas: pide el envío gratis por el
filtro estable `hwe_shipping_free_reason` y `shipping` aplica su lógica (solo opciones elegibles,
nunca la reserva) y escribe `_hwe_free_shipping_reason`. Sin `shipping`, pone a 0 las tarifas que
no son de recogida por el **id del método** (`local_pickup`, `pickup_location`), nunca por la
etiqueta. Si el envío ya salía a 0 por otra regla, no se reserva cupo (`reason = already_free`).
Motivos estables: `membership_free_delivery`, `already_free`, `quota_exhausted`, `not_eligible` y
`not_available`; los textos los compone el BFF.

**Caché de tarifas de WooCommerce.** WooCommerce guarda en la sesión las tarifas ya filtradas
(`woocommerce_package_rates`) con un hash del paquete como llave, y ese hash no incluye la petición
del socio ni su cupo. Por eso `woocommerce_cart_shipping_packages` añade `hwe_free_delivery`
(`requested` si pidió el envío gratis y queda cupo, `no` en el resto) a cada paquete: al pedirlo,
quitarlo o agotar el cupo, las tarifas se recalculan en vez de reutilizar las anteriores.

## Beneficios conectados con `loyalty`

Los tres beneficios de fidelización son opcionales y dependen de `loyalty`. Si el módulo está
apagado o la función concreta no lo está, no existen para el cliente, su configuración se
conserva y no se engancha nada en los hooks de `loyalty` (salvo los tipos que necesitan dejar
constancia de su propio ciclo, que escuchan solo hooks `hwe_membership_*`).

- **Puntos por activación.** Se abonan una sola vez por línea, con clave
  `membership:order:<pedido>:item:<línea>`. No abonan regalos, asignaciones de administrador ni
  lotes. Revocar la concesión revierte el abono con `hwe_loyalty_reverse_credit()`, limitado al
  saldo e idempotente. Si `loyalty` no está cargado cuando llega la revocación, la reversión se
  deja en la cola `hwe_memberships_pending_point_reversals` con la misma clave y se aplica cuando
  vuelve (al cargar y en la tarea diaria); el reintento es idempotente y una entrada sin abono que
  revertir se descarta tras tres intentos con constancia en el log. Si «Puntos» está apagado en el
  cobro, no se abona, no se reintenta y queda `points_skipped` en el historial más un `error_log`.
  Una compra aplazada se abona (u omite) al cobrarse, aunque su concesión se active más tarde: la
  activación de la tarea diaria llega con `HWE_Memberships_Events::CONTEXT_DAILY` y no vuelve a
  intentarlo.
- **Comisión extra de referidos.** Se añade al filtro `hwe_loyalty_order_earnings` como abono del
  tipo `membership_referral_bonus`, declarado con `hwe_loyalty_entry_types`. Cuenta el nivel del
  referidor **al cobrarse el pedido**; sin referidor o sin beneficio, el array sale intacto. El
  referidor se resuelve en ese mismo momento con `hwe_loyalty_referrer_of()`, el contrato de
  `loyalty`: **solo cuenta una atribución confirmada**. Si `loyalty` reasigna el referidor, lo
  anonimiza o desvincula la red, la comisión extra sigue la atribución vigente en el siguiente
  pedido; membresías no guarda una copia en metas de usuario. No se reconstruye hacia atrás.
- **Regalo al referido.** Lo recibe el referido, no el referidor. El cupo es de la concesión
  activa del referidor (`grants.referral_gifts`) y siempre hay tope. Al agotarse, el referido se
  registra con normalidad y queda `referral_gift_limit_reached` en el historial del referidor.
  Con `referral_gift_requires_email_verification` encendido, solo al verificar el correo
  (`hwe_email_verified`); apagado, al confirmarse la atribución. Una sola vez por referido
  (`gift:<referido>`), sin puntos por activación. El consumo del tope y la concesión se escriben
  en la misma transacción con las filas del referidor y del referido bloqueadas en orden estable:
  dos verificaciones simultáneas no pasan del tope. Si el referido ya tiene ese nivel o uno
  superior, el regalo se encola.

`loyalty` publica `hwe_loyalty_available`, `hwe_loyalty_credit` y
`hwe_loyalty_reverse_credit`; membresías es su consumidor y **nunca** llama a clases internas de
`loyalty`. `shipping` publica `hwe_shipping_free_reason` y la constante
`HWE_SHIPPING_FREE_REASON_FILTER`; membresías es su consumidor.

- **Los planes no generan puntos.** El puente engancha `hwe_loyalty_line_earns` y devuelve
  `false` para la línea de un producto de membresía (también para una variación, que hereda el
  vínculo de su padre). Así el precio del plan no entra en la base de `loyalty` ni paga el bono
  por producto. Es coherente con el descuento de socio, que tampoco se aplica a un plan: sus
  puntos de activación son el beneficio del nivel, no un cashback por comprarlo. La instancia
  puede revertirlo con «Los planes generan puntos de fidelización» (`plans_earn_points`,
  apagado por defecto). Sin `loyalty` cargado no se registra el filtro.

## Carné digital

`HWE_Memberships_Card::issue()` emite un token aleatorio de 32 bytes (hex), guarda **solo su
hash** en `hwe_memberships_card_tokens` con `expires_at` y devuelve `token`, `expires_at` y
`verify_path`. La verificación pública devuelve los datos mínimos del socio (nivel, nombre, número
y, si `customer-identity` está activo, el documento enmascarado) o `{ valid: false }`: un token
inexistente, caducado y revocado dan **exactamente la misma respuesta**, sin distinguir el motivo
ni revelar la causa. El rate-limit por IP vive en el BFF. Emitir un carné revoca el anterior; el
token se revoca al caducar, congelarse, revocarse o retirarse la membresía, y se borra con los
datos personales. Cada emisión inserta una fila (la anterior se revoca, no se borra): la tarea
diaria retira los tokens caducados hace más de 7 días (`HWE_Memberships_Card::purgeExpired()`,
por trozos de 1.000) y el BFF limita la emisión a 10 por minuto y socio.

## Rutas internas

Namespace `hwe/v1`; **solo el BFF**, con `X-HWE-Internal-Secret` (las cierra `hwe-bff-only`).
Todas fallan cerradas si el secreto o el módulo no se pueden comprobar. `user_id` es obligatorio
y entero > 0 en las que dependen del socio; un usuario inexistente devuelve el estado vacío, no
un error que permita enumerar cuentas.

| Ruta | Lee | Devuelve |
|---|---|---|
| `GET memberships/levels?locale` | Niveles publicados | `{items: [{id, name, description, slug, slugs, rank, icon, color, purchasable, min_account_age_days, benefits, products}]}` |
| `GET memberships/me?user_id` | Estado del socio | `{status, level:{id,rank}\|null, ends_at, member_since, member_number, queue, benefits, gifts, purchasable}` |
| `GET memberships/history?user_id&page&per_page` | Historial | `{items:[{id,type,level_from,level_to,data,created_at}], total, pages}` |
| `GET memberships/prices?user_id&ids` | Precio de miembro | `{items:[{id, regular, price, member_price, percent}]}` |
| `GET memberships/product?user_id&id\|slug&locale` | Ficha completa o recortada | Ficha del catálogo con `locked` y `hwe_membership` |
| `GET memberships/card?user_id` | Carné | `{token, expires_at, verify_path}` o 403 `membership_card_unavailable` |
| `GET memberships/verify?token` | Verificación pública | `{valid:true, level, member_since, member_number, member_name, document?}` o `{valid:false}` |

Las dos rutas del carné solo existen con `card_enabled`; sin él responden el mismo 404 que una
ruta inexistente. Las rutas de lectura existen con el módulo activo; con el módulo apagado no se
registran.

## Administración

Menú propio **Membresías** (`hwe_memberships_view` para leer; `hwe_memberships_manage` para
escribir), concedido a `administrator` y `shop_manager` en la activación y autorreparado si
falta:

- **Resumen** con métricas de socios, niveles y operaciones.
- **Niveles** (tabla con sus accesos) y **Todos los niveles** (listado nativo del CPT).
- **Beneficios** por nivel y **Comparativa** de niveles × beneficios, generadas desde el registro
  de tipos.
- **Socios**: filtros (activos, congelados, por caducar, caducados), ordenación, paginación y
  acciones por fila (congelar, descongelar, quitar).
- **Perfil de usuario**: estado, cola, cupo, número y últimos eventos; acciones de conceder,
  extender, quitar, congelar y descongelar, con clave de idempotencia por render.
- **En masa**: asignar, reemplazar, quitar, congelar o descongelar por lista pegada o criterio;
  vista previa sin escribir, confirmación con nonce y lote reanudable por Action Scheduler.
- **Historial** del socio y exportación CSV (la descarga exige `hwe_memberships_manage`; con
  solo lectura no se ofrece).
- **Caja del pedido**: nivel comprado, concesión, descuento de miembro y envío gratis, ampliable
  con `hwe_memberships_order_box_rows`.

## Hooks estables

**Eventos del ciclo** (`hwe_membership_*`), disparados después del COMMIT y con fila en el
historial. Siempre ids numéricos, nunca datos personales:

| Hook | Firma |
|---|---|
| `hwe_membership_activated`, `_renewed`, `_upgraded`, `_deferred`, `_granted_by_admin`, `_referral_gift_granted`, `_revoked` | `( int $userId, int $grantId, int $levelId )` |
| `hwe_membership_removed_by_admin` | `( int $userId, 0, int $oldLevelId )` |
| `hwe_membership_expired` | `( int $userId, int $grantId, int $levelId )` |
| `hwe_membership_expiring` | `( int $userId, int $grantId, int $levelId, int $days )` |
| `hwe_membership_frozen` / `_unfrozen` | `( int $userId, int $actorId )` |
| `hwe_membership_level_changed` | `( int $userId, int $oldLevelId, int $newLevelId )` (0 = sin nivel) |
| `hwe_membership_referral_gift_limit_reached` | `( int $referrerId, int $grantId, int $levelId )` |
| `hwe_membership_points_skipped` | `( int $userId, int $grantId, int $levelId )` |

**Filtros de extensión:**

| Hook | Firma | Para qué |
|---|---|---|
| `hwe_memberships_benefit_types` | `( array<string,HWE_Memberships_Benefit> $types ): array` | Añade tipos de beneficio de instancia. |
| `hwe_memberships_product_requirement` | `( array $requirement, int $productId ): array` | Endurece el requisito efectivo (nunca lo relaja). |
| `hwe_memberships_grant_origins` | `( string[] $origins ): string[]` | Añade orígenes de concesión. |
| `hwe_memberships_participant_roles` | `( string[] $roles ): string[]` | Roles que participan (por defecto `customer`, `subscriber`). |
| `hwe_memberships_store_api_cart_data` | `( array $data, array $context ): array` | Aporta ramas a la extensión del carrito; `$context` = `cart`, `user_id` y `state`. |
| `hwe_memberships_order_box_rows` | `( array $rows, WC_Order $order ): array` | Añade filas a la caja del pedido. |
| `hwe_memberships_collected_on_completion` | `( string[] $methods ): string[]` | Medios que esperan a `completed` (defecto `cod`). |

**Consume:** `hwe_email_verified`, `hwe_loyalty_attribution_confirmed`,
`hwe_loyalty_entry_types`, `hwe_loyalty_order_earnings`, `hwe_loyalty_line_earns`,
`hwe_loyalty_referrer_of`,
`hwe_shipping_free_reason`, `hwe_control_center_schema`, `hwe_translatable_fields` y
`hwe_revalidation_tags`.

## Límites y comportamientos que conviene conocer

- **Un pedido cancelado y reabierto no reactiva la membresía.** La concesión revocada queda como
  tal y `Purchase` descarta los pedidos en estado `reversed` para no resucitarla con una clave
  idempotente que ya existió. Si el pedido vuelve a estar cobrado, la membresía se concede a mano
  desde el perfil del socio (conceder el nivel, con sus días).
- **La comisión extra de referidos no aplica la ventana de comisión de `loyalty`** (ni su
  comprobación de participación del referidor): se paga cuando llega el pedido, igual que el resto
  de la ganancia del referidor. Si la instancia quiere limitarla por tiempo, debe hacerlo en el
  filtro `hwe_loyalty_order_earnings` o desactivar el beneficio.
- **Las reseñas de un producto bloqueado son visibles** (`wc/store/v1/products/reviews`): el
  recorte de la ficha de escaparate cubre la descripción, la galería, las variaciones, los
  atributos y los campos del producto, no el texto de sus reseñas. Si una instancia necesita
  ocultarlas, debe filtrar esa ruta por su cuenta.

## Privacidad

Exportador y eliminador de las herramientas de WordPress. El exportador entrega solo los datos
del propio sujeto (estado, concesiones e historial), nunca datos de terceros; de la meta
heredada `_hwe_membership_referrer` solo exporta el id numérico del referidor, sin su nombre,
email ni login. El eliminador conserva las concesiones de origen pedido y su historial como
contabilidad, vacía notas y datos libres y desvincula actores; el resto se borra, junto con los
tokens del carné, la fila del socio y esa meta heredada (también al borrar la cuenta). El número
de socio no se reutiliza. Borrar la cuenta termina la membresía. Todo va en transacciones con
bloqueo y por lotes.

## Con el módulo apagado

`config.modules.memberships` es `false`; el catálogo no tiene requisitos (todo público, sin
recortes), los lectores devuelven vacío o nulo, las rutas del BFF responden 404, la extensión de
la Store API no se registra y no se engancha ningún filtro sobre `loyalty` ni sobre `shipping`.
Los datos guardados (niveles, concesiones, requisitos, carné) **se conservan** para cuando vuelva
a activarse.

Además, la desactivación del plugin **retira de la venta** los productos de membresía publicados
(los pasa a borrador y recuerda su estado en `hwe_memberships_hidden_products`): sin la compuerta
de compra del módulo, un producto ligado a un nivel se cobraría sin conceder nada. La activación
restaura exactamente esos productos si siguen en el estado que puso el plugin —un cambio manual
del administrador no se pisa— y **deja programada** la conciliación de los pedidos cobrados
durante el apagón, con las claves de idempotencia por línea de `Purchase`. La conciliación corre en
una petición posterior con el plugin ya arrancado (evento único de WP-Cron; la tarea diaria la
retoma si queda pendiente), porque en el hook de activación WordPress todavía no ha escrito
`active_plugins`. El barrido se acota por la fecha de cobro y por un tope por ejecución; si algo
falla, se registra con `[hwe] memberships: …` sin bloquear la activación.

## Verificación

Desde `backend/`:

```bash
composer test                                  # suite completa
vendor/bin/phpunit tests/Unit/Memberships       # solo este módulo
```

En vivo, con la pila levantada:

```bash
sh backend/scripts/verify-modules.sh toggle
```

El script crea un nivel y un producto de prueba, comprueba las cinco tablas, las rutas internas
con y sin el secreto, la extensión de la Store API y el recorte en GraphQL y la Store API, y
borra el fixture al salir. WP-CLI se ejecuta dentro de un contenedor que ya está en marcha; si
no hay ninguno, el respaldo usa el proyecto de la pila y `--no-deps`, así que no recrea
servicios con otro entorno. El contrato de frontend está en `docs/FRONTEND_CONNECT.md` (Parte A).
