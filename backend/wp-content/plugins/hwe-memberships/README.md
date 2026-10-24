# HWE Memberships (módulo `memberships`)

**Membresías** para el framework: niveles y beneficios editables, carné digital con verificación
por QR, renovaciones y regalos por referido. Es un módulo opcional del catálogo, como `loyalty` o
`shipping`, y no trae vistas: entrega el contrato de configuración y la lógica del backend.

> **Estado: backend del módulo en desarrollo.** Ya existen los ajustes, los niveles, las
> concesiones, las compras, el carné, la privacidad, los beneficios conectados con `loyalty`, la
> extensión de la Store API con el bloqueo de compra por requisito y el descuento de miembro en
> el carrito y el pedido. Las rutas internas y la UI del frontend llegan en entregas posteriores;
> este README y `MANUAL.md` se completan con ellas.

## Qué aporta hoy

| Pieza | Detalle |
|---|---|
| `hwe-memberships.php` | Clase `HWE_Memberships`: comprueba que WooCommerce esté activo y engancha los ajustes a `plugins_loaded`. Sin WooCommerce no arranca y lo registra. |
| `includes/Settings.php` | Clase `HWE_Memberships_Settings`: puebla el grupo reservado `memberships` del Control Center y expone la lectura saneada. |
| `includes/Benefits/` | Registro de tipos de beneficio y los que dependen de `loyalty`: puntos por activación, comisión extra de referidos y regalo de membresía al referido. |
| `includes/LoyaltyBridge.php` | Único punto de conversación con el contrato de `loyalty` (disponibilidad, tipos de movimiento, abono y reversión). |
| `includes/StoreApi.php` | Extensión `hwe-memberships` del carrito y bloqueo de compra por requisito de acceso, en el alta y al crear el pedido. |
| `includes/Benefits/MemberDiscount.php` | Beneficio `member_discount`: porcentaje y alcance (todo el catálogo o categorías con herencia). |
| `includes/Pricing.php` | Descuento de miembro en el precio de línea, metas del pedido, fila de la caja y `priceFor()` para el BFF. |

## Beneficios conectados con `loyalty`

Los tres beneficios son opcionales y **dependen de `loyalty`**: si el módulo está apagado o la
función concreta no lo está, no existen para el cliente, su configuración se conserva y no se
engancha nada en los hooks de `loyalty`.

| Clave | Campos por nivel | Depende de | Qué hace |
|---|---|---|---|
| `activation_points` | `points` (≥ 0) | `loyalty:points` | Abona puntos cuando se **cobra** una concesión de origen `order` (compra). |
| `referral_bonus` | `percent` (0–100) | `loyalty:referrals` | Añade al referidor N1 una comisión extra sobre la base de `loyalty`. |
| `referral_gift` | `level`, `days` (≥ 1), `max_per_period` (≥ 1) | `loyalty:referrals` | Regala una membresía al **referido**, con tope por periodo del referidor. |

### Puntos por activación

- Se abonan una sola vez por línea de pedido. La clave deriva de la concesión
  (`order:<pedido>:item:<línea>` → `membership:order:<pedido>:item:<línea>`), así que
  `processing` → `completed`, un webhook repetido o una ida y vuelta de estado no duplican.
- No abonan los regalos (`origin = referral_gift`), las asignaciones de administrador
  (`admin`) ni los lotes (`bulk`).
- Revocar la concesión (reembolso total o cancelación, que `Purchase` resuelve con
  `Grants::revokeByKey()`) revierte el abono con `hwe_loyalty_reverse_credit()`, limitado al
  saldo e idempotente. Una revocación administrativa de una compra también lo revierte: la
  concesión deja de existir y el abono se corrige.
- Si «Puntos» está apagado en el cobro, no se abona, **no se reintenta después** y queda un
  evento `points_skipped` en el historial del socio más un `error_log`. Para poder dejar esa
  constancia, el tipo escucha sus propios eventos (`hwe_membership_*`) con `loyalty` cargado
  aunque «Puntos» esté apagado; nunca engancha nada en los hooks de `loyalty`.

### Comisión extra de referidos

- Se añade al filtro `hwe_loyalty_order_earnings` como un abono del tipo
  `membership_referral_bonus` (declarado como `credit` con `hwe_loyalty_entry_types`), con
  `ref = ['type' => 'user', 'id' => <comprador>]` y `data` con el pedido, el nivel y el
  porcentaje. `loyalty` la abona y la revierte con el pedido como cualquier otra ganancia.
- El nivel que cuenta es el del referidor **en el momento de cobrarse el pedido**; congelado, sin
  el beneficio o sin referidor, el array de ganancias sale intacto. El redondeo es hacia abajo,
  igual que en `loyalty`.
- `loyalty` no publica la lectura de la atribución: al confirmarse
  (`hwe_loyalty_attribution_confirmed`) se guarda el id del referidor N1 en la meta
  `_hwe_membership_referrer`. Por eso la comisión requiere que la atribución se confirmara con
  este módulo activo; no se reconstruye hacia atrás.

### Regalo al referido

- Lo recibe **el referido**, no el referidor. Campos del nivel del referidor: nivel a regalar,
  días y máximo por periodo (siempre hay tope).
- **Periodo**: cada concesión activa del referidor en ese nivel tiene su propio contador
  (`grants.referral_gifts`); al renovar (concesión nueva) vuelve a empezar. Al agotarse, el
  referido se registra con normalidad, no hay regalo y queda un evento
  `referral_gift_limit_reached` en el historial del **referidor**.
- **Cuándo**: con `referral_gift_requires_email_verification` encendido (por defecto), solo al
  verificar el correo (`hwe_email_verified`) y con atribución existente; una cuenta que nunca
  verificó —incluidas las creadas en wp-admin— no lo recibe. Apagado, se concede al confirmarse
  la atribución. Si el correo ya estaba verificado al confirmarse la atribución, también se
  concede.
- **Una sola vez por referido** (clave `gift:<referido>`), sin puntos por activación. El consumo
  del tope y la concesión se escriben en la **misma transacción** con las filas del referidor y
  del referido bloqueadas en orden estable, así que dos verificaciones simultáneas no pasan del
  tope. Si el referido ya tiene ese nivel o uno superior, el regalo se encola con la línea de
  tiempo (no se pierde).
- Con el módulo apagado en ese momento no hay regalo retroactivo; si el referidor está congelado
  o sin el beneficio, tampoco.

### Hooks propios publicados

| Hook | Firma | Cuándo |
|---|---|---|
| `hwe_membership_points_skipped` | `( int $userId, int $grantId, int $levelId )` | Un cobro de origen `order` no abonó puntos porque «Puntos» estaba apagado. El `data` del evento lleva `points`, `reason` y la clave. |
| `hwe_membership_referral_gift_limit_reached` | `( int $referrerId, int $grantId, int $levelId )` | El referidor agotó el tope de regalos de su concesión activa. El `data` lleva el referido, el nivel regalado, el tope y los usados. |

Los dos dejan fila en `hwe_memberships_events` y se disparan fuera de la transacción, igual que
el resto del ciclo (`hwe_membership_activated`, `_revoked`…).

El grupo raíz `memberships` lo declara vacío el núcleo (`hwe-control-center/SchemaGroups/Memberships.php`)
y este plugin lo rellena con el filtro `hwe_control_center_schema`. Así el panel, la API pública
`/wp-json/hwe/v1/config`, `wp hwe setup` y la revalidación de `site-config` comparten una sola
configuración.

## Ajustes (`config.memberships`)

Los tres primeros son **públicos** y forman el espejo de TypeScript en
`frontend/src/lib/config/{types,defaults}.ts`. Los demás son operativos del backend y **no** salen
por la API pública.

| Campo | Tipo | Default | Público | Qué hace |
|---|---|---|---|---|
| `card_enabled` | booleano | `false` | sí | Enciende el carné digital con su verificación por QR. |
| `card_verify_path` | texto | `/verify` | sí | Ruta del frontend a la que apunta el QR. Se normaliza a una ruta absoluta desde la raíz. |
| `member_number_prefix` | texto | vacío | sí | Texto corto que precede al número de socio. Vacío = sin prefijo. |
| `card_token_ttl_hours` | entero | `24` | no | Horas de vigencia del token del carné. Se recorta a [1, 720]. |
| `expiring_notice_days` | entero | `7` | no | Días de antelación del aviso de caducidad. Negativo se recorta a 0. |
| `referral_gift_requires_email_verification` | booleano | `true` | no | El regalo al referido solo se concede cuando confirma su correo. |

### Lectura saneada

`HWE_Memberships_Settings::get()` devuelve los tipos nativos y nunca falla:

- cada valor se lee con `\HWE\ControlCenter\Storage::get`; sin Control Center (o si la lectura
  lanza) se usan los defaults de `DEFAULTS`;
- el TTL fuera de rango se recorta a 1–720 horas y los días de aviso negativos a 0;
- una ruta vacía cae a `/verify` y una sin `/` inicial se normaliza (`verify` → `/verify`); las
  barras iniciales repetidas se colapsan para que no pueda convertirse en una URL de otro dominio.

### Interruptores

`moduleActive()` exige que el plugin esté cargado y, si el Control Center está disponible, que
aparece en `active_plugins`: incluir el archivo a mano no enciende el módulo. `cardActive()` exige
además `card_enabled`. `participantRoles()` devuelve `customer` y `subscriber` por defecto y es
filtrable con `hwe_memberships_participant_roles`; un filtro que no devuelve una lista se registra
y se ignoran sus valores.

## Catálogo exclusivo (ficha de escaparate)

El módulo puede exigir un nivel de membresía por producto o categoría y una fecha de apertura.
Quien no cumple el requisito **sí ve el producto** en listados, búsqueda y sitemap, pero solo con
la ficha de escaparate: id, nombre, slug, tipo, imagen principal, precio público (rango en
variables), categorías, el requisito y la marca `locked`. No salen descripción, descripción
corta, galería, atributos, variaciones ni el stock de las variaciones, campos de producto del
núcleo (`hweFields`), la landing, el SEO ni enlaces de descarga.

El recorte se hace **en WordPress**, nunca en el frontend:

| Vía | Implementación |
|---|---|
| GraphQL (`Product`, `SimpleProduct`, `VariableProduct`…, listados, búsqueda, relacionados, venta cruzada, `ProductCategory`) | `includes/Graphql.php`: campo `hweMembership { requiredLevel { slug rank name } opensAt locked }` y lista blanca en `graphql_resolve_field`. Las variaciones directas (`productVariation`, `node(id:)`) se anulan si el padre es teaser. |
| Store API `wc/store/v1/products` (listado y ficha), variaciones y carrito | `includes/Teaser.php`: `rest_request_after_callbacks` acotado a esas rutas. Del carrito se conservan clave, cantidad, precios, totales y extensiones; se quitan atributos, resumen, descripciones y SKU. |
| WC REST `wc/v3/products` y `wc/v3/products/<id>/variations` | `woocommerce_rest_prepare_product_object` / `…_variation_object`: si la petición trae el secreto interno del BFF se aplica la ficha como visitante (esa respuesta alimenta el endpoint público de la lista de deseos). El panel y las integraciones con `ck/cs` siguen viendo la ficha completa. |

En la respuesta de `wc/v3` para el BFF se conservan, además, los campos operativos `status`,
`purchasable`, `stock_status`, `manage_stock`, `stock_quantity` y `backorders_allowed` del
producto: no son contenido (§6.2 prohíbe las variaciones y su stock, no la disponibilidad del
propio producto) y son los que necesita «volver a pedir» para decidir la cantidad. La Store API
mantiene la lista estricta: allí la autorización real del carrito la hacen la extensión
`hwe-memberships` (bloqueo por requisito) y las reglas de compra de la membresía. Las variaciones
siguen reducidas a su identificador.

En las respuestas REST el requisito viaja en `hwe_membership.required_level` (slug, rank y name)
y `hwe_membership.opens_at` (ISO 8601 UTC o null), y la marca en `locked`. En GraphQL el mismo
dato se llama `hweMembership`.

Otras rutas REST propias que devuelven producto se revisaron y **no se tocan** porque no exponen
contenido: `hwe/v1/best-sellers` (id, slug y unidades), cupones públicos de `hwe-coupon-rules`
(slugs de producto/categoría), `hwe-shipping/v1/free-shipping/scope` (nombre y slug de categoría
o producto), `hwe-loyalty` (contadores y ajustes) y `hwe-site-reviews` (crea reseñas de pedido, no
devuelve productos). Si una instancia añade una ruta nueva que devuelva descripción, galería,
atributos, variaciones o campos de producto, debe aplicar `HWE_Memberships_Teaser` en su
respuesta.

## Compra y bloqueo en la Store API

Una línea cuyo requisito de acceso no cumple el usuario se rechaza al añadirla al carrito y otra
vez al crear el pedido, porque el nivel puede caducar, congelarse o retirarse con la línea ya en
el carrito. Los motivos son estables (`membership_required`, `membership_early_access`) y el
conflicto responde **409** con `reason`, `min_rank`, `level_id`, `level_slug`, `level_name` y
`opens_at` en los datos del error. Una variación se evalúa con el requisito de su producto padre.
El texto que ve el cliente lo compone el BFF; WordPress solo devuelve el motivo y los datos.

En `extensions.hwe-memberships` del carrito viajan:

| Campo | Contenido |
|---|---|
| `level` | `{id, slug, rank, name}` del nivel efectivo, o `null` (sin membresía o congelado). |
| `member_discount` | `{total, lines}` con importes en unidades menores de la moneda, como string. Lo completa el beneficio de descuento de miembro. |
| `free_delivery` | `{available, remaining, requested, applied, reason}`. Lo completa el beneficio de envíos gratis; `requested` refleja solo lo que declaró el cliente. |
| `blocked_items` | Lista de `{key, product_id, reason, min_rank, level_slug, level_name, opens_at}` de las líneas que hoy no puede comprar. |
| `purchase_notice` | `renewal`, `upgrade`, `scheduled` o `null` para la línea de membresía: misma referencia, subida de rango o compra que espera detrás de la cobertura actual o de la cola. |

El navegador **solo declara que quiere usar un envío gratis** en el update callback
(`{free_delivery: bool}`); el valor se guarda en la sesión de WooCommerce y cualquier otra clave o
un valor no booleano se ignora y se registra. El filtro interno
`hwe_memberships_store_api_cart_data` permite a las tareas de descuentos y envíos aportar sus dos
ramas; `level`, `blocked_items` y `purchase_notice` siempre se recalculan en el módulo para que un
filtro no pueda retirar una línea bloqueada.

## Descuento de miembro

El nivel puede configurar un **porcentaje** (0–100) y el **alcance**: todo el catálogo o una lista
de categorías. Elegir una categoría cubre también sus descendientes. El descuento se aplica solo
al socio con el beneficio activo y nunca a las líneas de membresía.

La mecánica rebaja el **precio de línea** del carrito en `woocommerce_before_calculate_totals`
(prioridad 999, después de WooCommerce y de los plugins que fijan precios), partiendo siempre del
precio que la tienda cobraría hoy (oferta incluida) y nunca del ya rebajado. Así los impuestos se
calculan sobre lo rebajado, el cupón se aplica después y la base de ganancias de `loyalty` lo
refleja sola. En cada recálculo se restaura el precio original si el socio perdió el nivel, el
beneficio se apagó o la línea dejó de estar en el alcance (Foco 3), sin encadenar rebajas.

`HWE_Memberships_Pricing::priceFor( int $userId, int $productId, ?int $variationId = null ): array`
es el precio de referencia que usan el carrito y el BFF. Devuelve strings decimales de WooCommerce
(`wc_get_price_decimals()`):

| Campo | Qué es |
|---|---|
| `regular` | Precio regular del producto. |
| `price` | Precio que el carrito cobraría hoy (oferta incluida). |
| `member_price` | Precio con el descuento de miembro; igual a `price` si no hay descuento. |
| `percent` | Porcentaje aplicado (0 si no hay descuento). |

En el pedido, cada línea guarda el precio original y el descuento **por unidad**
(`_hwe_membership_line_original`, `_hwe_membership_line_discount`), y el pedido el nivel y el
porcentaje (`_hwe_membership_discount_level`, `_hwe_membership_discount_percent`). La caja del
pedido muestra una sola fila con el nivel y el porcentaje mediante el filtro
`hwe_memberships_order_box_rows`. La extensión `hwe-memberships` del carrito publica el total y el
detalle por línea en `member_discount`, en unidades menores.

## Con el módulo apagado

`config.modules.memberships` es `false`, no hay ajustes activos, no se registra la extensión de la
Store API, no se valida ninguna línea y las rutas del BFF responderán 404 cuando existan. Los datos
que se hayan guardado se conservan para cuando vuelva a activarse.

## Verificación

Desde `backend/`:

```bash
composer test                                  # suite completa
vendor/bin/phpunit tests/Unit/Memberships       # solo este módulo
```

La comprobación en vivo de los módulos (`backend/scripts/verify-modules.sh`) exige que el plugin
tenga su archivo de entrada, `README.md`, `MANUAL.md` y esté montado en los tres servicios de
WordPress de `docker-compose.yml`.
