# HWE Memberships (módulo `memberships`)

**Membresías** para el framework: niveles y beneficios editables, carné digital con verificación
por QR, renovaciones y regalos por referido. Es un módulo opcional del catálogo, como `loyalty` o
`shipping`, y no trae vistas: entrega el contrato de configuración y la lógica del backend.

> **Estado: base del módulo.** Hoy existen el arranque, la comprobación de WooCommerce y el grupo
> de ajustes del Control Center. Todavía no hay tablas, rutas internas, extensión de la Store API
> ni UI; este README y `MANUAL.md` se completan a medida que se implementa el resto.

## Qué aporta hoy

| Pieza | Detalle |
|---|---|
| `hwe-memberships.php` | Clase `HWE_Memberships`: comprueba que WooCommerce esté activo y engancha los ajustes a `plugins_loaded`. Sin WooCommerce no arranca y lo registra. |
| `includes/Settings.php` | Clase `HWE_Memberships_Settings`: puebla el grupo reservado `memberships` del Control Center y expone la lectura saneada. |

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
mantiene la lista estricta: allí la autorización real del carrito la hace la Store API con el JWT
del socio (Tarea 11). Las variaciones siguen reducidas a su identificador.

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

## Con el módulo apagado

`config.modules.memberships` es `false`, no hay ajustes activos y las rutas del BFF responderán
404 cuando existan. Los datos que se hayan guardado se conservan para cuando vuelva a activarse.

## Verificación

Desde `backend/`:

```bash
composer test                                  # suite completa
vendor/bin/phpunit tests/Unit/Memberships       # solo este módulo
```

La comprobación en vivo de los módulos (`backend/scripts/verify-modules.sh`) exige que el plugin
tenga su archivo de entrada, `README.md`, `MANUAL.md` y esté montado en los tres servicios de
WordPress de `docker-compose.yml`.
