# HWE Shipping (módulo `shipping`)

Envío por ciudad con dos tipos de tarifa (**fija** o **por distancia real**), opciones de entrega
repetibles por ciudad, envío gratis por opción, extras aditivos y una zona de reserva de WooCommerce
para las ciudades sin configurar. Versión **2.0.0**: cambio de modelo sin migración (ver «Modelo
anterior»). Genérico: el país base es el de la tienda (`ecommerce.country`) y el idioma de los
proveedores es el principal del sitio (`brand.locale`); el plugin no contiene nada de ninguna
instancia.

## Qué hace

| Pieza | Detalle |
|---|---|
| CPT `hwe_shipping_rate` | Menú **Envío → Tarifas de envío**. Cada registro es una ciudad: el título es el nombre (la coincidencia ignora mayúsculas, tildes y espacios dobles). Tipo `fixed` o `distance`, opciones de entrega y, en distancia, dirección de referencia y radio. |
| Opciones de entrega | Una o más por ciudad, con id estable (no cambia al renombrar), nombre y casilla «aplica envío gratis». Fijas: `amount`. Por distancia: `price_per_km`, `min_cost` y `max_cost` (0 = sin límite). |
| CPT `hwe_shipping_method` | Submenú **Métodos de envío**. Extras opcionales y **aditivos** (no alternativas de entrega). |
| Método `hwe_city_rate` | Método de envío propio de WooCommerce (`WC_Shipping_Method`). Produce **una tarifa por opción** de la ciudad de destino y, si la ciudad no está configurada, una tarifa de reserva. Vive en la zona de reserva. |
| Envío gratis por regla (`FreeShipping`) | Prioridad 25. Deja a 0 solo las tarifas de las **opciones marcadas**. |
| Cupón con «Permitir envío gratuito» | Deja a 0 todas las tarifas (prioridad 30, después de la regla). Los extras no se ven afectados. |
| Zona de reserva (`ReserveZone`) | Zona de WooCommerce del país base con el método `hwe_city_rate`. Con el módulo activo existe siempre. |
| Distancia (`Distance\*`) | Proveedores intercambiables, caché en transients y respaldo automático a línea recta. Ver «Envío por distancia». |
| Punto de entrega (`DeliveryPoint`) | El pin del cliente viaja por la Store API y se guarda en la sesión de WooCommerce. Ver «Punto de entrega». |
| REST pública | `GET /wp-json/hwe-shipping/v1/{methods, rates, free-shipping/scope}`. `rates` solo expone **nombres y tipo** de ciudad, nunca importes ni referencias. |
| REST interna (BFF) | `POST /wp-json/hwe-shipping/v1/{geocode, reverse-geocode}`, solo con `X-HWE-Internal-Secret`. Ver «Geocodificación para el BFF». |
| REST wp-admin | `POST /wp-json/hwe-shipping/v1/admin/{geocode, maps-test}`: capacidad `manage_woocommerce` y nonce `wp_rest`. Los usa «Validar» y «Probar conexión». |
| Tag ISR `shipping` | Se registra en `hwe_revalidation_tags`. Al guardar una ciudad o el panel se invalidan la caché de ciudades y la versión del transient de envíos de WooCommerce (los carritos abiertos recalculan); las distancias no se invalidan (dependen de coordenadas, no de precios). |

El coste **lo calcula WooCommerce en el servidor**, dentro del cálculo de tarifas. El navegador
nunca envía ni firma un importe: la Store API devuelve las tarifas ya calculadas.

## Tipos de ciudad

**Fija.** Una tarifa por opción con su `amount`. Sin distancia, sin pin.

**Por distancia.** Requiere una dirección de referencia (el punto de salida, con coordenadas válidas)
y un radio de cobertura mayor que 0 km. Para un destino en el país base:

1. Sin punto de entrega válido para ese destino → ninguna tarifa (`needs_location`).
2. Punto fuera del radio → ninguna tarifa, **no** cae a la reserva (`out_of_coverage`). Si ya está fuera en
   línea recta (con un pequeño margen) se descarta sin pedir la distancia al proveedor de mapas.
3. Dentro del radio → distancia desde la referencia y **una tarifa por opción**:
   `coste = clamp(round(km × price_per_km), min_cost, max_cost)`.

La resolución de precio es una sola clase (`Rates\Pricing`), pura y testeable sin WordPress. Redondea
a los decimales de la moneda de WooCommerce (`wc_get_price_decimals()`).

**Sin configurar.** La ciudad no tiene registro: con `shipping.reserve_enabled` activo paga **una**
tarifa de reserva (`shipping.reserve_name` y `shipping.reserve_cost`); apagado, no tiene envío.

**Modelo anterior.** Una ciudad guardada sin tipo (modelo 1.x) no produce tarifa. wp-admin lo avisa
con la lista de ciudades para reconfigurarlas.

## Validación y guardado

El guardado valida y sanea todo en el servidor. Una ciudad incompleta **no se publica**: queda en
borrador y el editor ve el motivo (`CityErrors`). Se rechaza, entre otros casos:

- título vacío, o una ciudad cuyo nombre normalizado ya existe en otro registro (sin tildes ni
  mayúsculas: el duplicado se impide al guardar);
- tipo ausente o distinto de `fixed`/`distance`;
- ciudad sin opciones, o una opción sin nombre;
- importes negativos o no numéricos; `min_cost` mayor que `max_cost` (cuando ambos son mayores que 0);
- ciudad por distancia sin dirección de referencia con coordenadas válidas, o con radio ≤ 0.

Un id de opción existente se conserva al renombrarla: los ids de tarifa (`hwe_city_rate:<optionId>`)
no cambian y la selección de un carrito abierto sobrevive al cambio.

## Envío por distancia

### Ajustes

En **Control Center → Envío → Distancia** (`shipping.*`):

| Campo | Por defecto | Qué hace |
|---|---|---|
| `distance_method` | `straight_line` | `maps` usa el proveedor de **Integraciones → Mapas**; `straight_line` no llama a ningún proveedor. Con `maps` y sin proveedor o clave válidos se calcula en línea recta y wp-admin avisa. |
| `distance_travel_mode` | `driving` | `driving` o `walking`. Afecta a Google, a ORS y a la duración de la línea recta (30 km/h conduciendo, 5 km/h caminando). |
| `distance_straight_line_increment` | 30 | 0–200 %. Se aplica sobre la línea recta, también cuando es respaldo. |
| `distance_cache_ttl_hours` | 24 | ≥ 1. Caducidad de las distancias normales. |

La clave del proveedor y el proveedor viven en **Integraciones → Mapas** (`integrations.maps_provider`
y `integrations.maps_api_key`, cifrada): este módulo los lee con `Storage::get` y
`SecretsStorage::get`, con fallback. Ninguno sale por la configuración pública ni por el BFF.

### Proveedores

| Id | Distancia | Geocodificación |
|---|---|---|
| `google_maps` | Routes `computeRouteMatrix` (con `TRAFFIC_AWARE` en conducción). Clave en `X-Goog-Api-Key`. | Geocoding v4; elige el resultado de mejor granularidad. |
| `openrouteservice` | Directions. Clave en `Authorization`. | Geocode search y reverse. |
| `straight_line` | Haversine × (1 + incremento %), con duración estimada por velocidad media. | Nominatim, con `countrycodes` = país base y `User-Agent` con la URL del sitio. |

Los tres implementan `Distance\Provider` (`id()`, `distance()`, `geocode()`, `reverse()`). Un proveedor
sin clave devuelve `provider_unauthorized` sin hacer ninguna petición.

**Respaldo.** Si el proveedor de mapas falla al calcular la distancia (clave rechazada, cuota,
indisponible, sin ruta, o una respuesta absurda: distancia ≤ 0, cuerpo no JSON, sin elementos), se
usa la línea recta **marcada como respaldo** con su motivo. Se registra con
`error_log('[hwe] shipping: …')`. Nunca se cobra un importe inventado ni un 0 por error: un respaldo
normal se cachea 15 minutos y el resto según `distance_cache_ttl_hours`.

**Geocodificación.** Si el proveedor de mapas no geocodifica (búsqueda del mapa o «Validar»), cae a
Nominatim. Nominatim tiene backoff: **30 s** tras un error de red, **2 min** tras un 5xx y **15 min**
tras un 429; durante la pausa no sale ninguna petición y la llamada devuelve `geocoding_backoff`.

**Códigos de error** (`WP_Error`): `provider_unauthorized`, `provider_rate_limited`,
`provider_unavailable`, `route_unavailable`, `geocoding_no_results`, `geocoding_backoff`. La
`ProviderHttp` pone el estado HTTP en `data.status` y, en Google, el motivo corto en `data.reason`.

**Normalización de direcciones.** `Distance\AddressNormalizer` aplica las reglas del paquete de país
(`hwe-geo`, clave `geocoding`: abreviaturas de vía y tokens descartables). Sin reglas, solo limpia
espacios. El paquete `generic` no trae ninguna.

### Cachés

- Distancia: clave = referencia y destino a 4 decimales + proveedor + modo + incremento.
- Geocodificación (directa por consulta normalizada y proveedor, inversa por coordenada): 7 días.
- Los errores no se cachean. Todo son transients (la caché de objetos del framework, Redis si está).
  No hay tabla propia, cron ni flag de esquema.

## Punto de entrega

El pin es el punto de entrega. Llega por la Store API con el update callback de namespace
`hwe_shipping` (`cart/extensions`):

- `{ action: 'set_point', lat, lng, address_1, city, country }` valida el rango numérico (NaN,
  cadenas, fuera de rango y 0,0 se rechazan) y exige la calle. Guarda el punto en la sesión de
  WooCommerce (`hwe_shipping_delivery_point`) junto a su **huella**: calle, ciudad normalizada y país,
  en minúsculas, sin tildes y con los espacios colapsados.
- `{ action: 'clear_point' }` borra el punto. El BFF solo lo envía si el cliente lo pide de forma explícita
  (`location: null`); con `location` ausente no toca el punto, porque la huella ya lo invalida si cambian
  calle, ciudad o país.
- `DeliveryPoint::forDestination()` devuelve el punto **solo si la huella coincide con el destino**.
  Si el cliente cambia la calle (aunque siga en la misma ciudad), el punto deja de valer y el carrito
  pasa a `needs_location`. Cambiar solo mayúsculas o tildes no lo invalida.
- El filtro `woocommerce_cart_shipping_packages` añade el punto vigente al destino del paquete
  (`destination['hwe_point']`), así que el hash de paquete de WooCommerce cambia con el pin y no se
  recalcula sin cambios. Sin sesión de WooCommerce no rompe nada: no hay punto.

## Estado del envío

`ShippingStatus::decide()` es la única decisión (la comparten el método y la extensión de la Store
API) y se memoiza por petición:

- `mode`: `fixed` | `distance` | `reserve` | `none`.
- `status`: `ok` | `needs_address` | `needs_location` | `out_of_coverage`. Todo lo que no es `ok`
  significa **sin tarifas** para ese destino.
- Si `distance` y `ok`: `distance_km`, `duration_min` y `fallback`.

Se publica en `cart.extensions.hwe_shipping` (primer paquete; registrada por `StoreApiExtension`).

## Metas de la tarifa y del pedido

WooCommerce copia las metas de la tarifa a la **línea de envío** del pedido.

| Meta | Valores | Para quién |
|---|---|---|
| `hwe_option_id` | id de la opción | Frontend (`readRateDetails`) |
| `hwe_city_type` | `fixed` \| `distance` \| `reserve` | Frontend |
| `hwe_distance_km` | número en texto, 2 decimales | Frontend (solo en distancia) |
| `hwe_fallback` | `yes` \| `no` | Frontend (solo en distancia) |
| `_hwe_distance_provider` | id del proveedor | wp-admin (solo distancia) |
| `_hwe_fallback_reason` | motivo corto (solo si hay respaldo) | wp-admin |
| `_hwe_duration_min` | minutos | wp-admin (solo distancia) |
| `_hwe_delivery_point` | `lat,lng` | wp-admin y repartidor (solo distancia) |
| `_hwe_free_shipping_reason` | motivo del envío gratis (lo pone `FreeShipping`) | wp-admin |

La Store API publica en `shipping_rates[].meta_data` **solo las metas sin `_`**: el cliente recibe
únicamente las cuatro del contrato. Las de operación (con `_`) no salen del servidor; `OrderShippingMeta`
las pinta en la ficha del pedido de wp-admin con etiquetas traducidas al mostrarlas (las claves son
estables), junto con un enlace a OpenStreetMap del punto, y oculta allí las del contrato como claves en
crudo. No aparecen en los correos ni en la cuenta del cliente. La dirección de referencia **no** se copia
al pedido.

## Envío gratis por opción

La regla la entrega el Control Center por el filtro `hwe_shipping_free_rule`:

```php
apply_filters( 'hwe_shipping_free_rule', [] );
// [ 'enabled' => bool, 'min_amount' => float, 'categories' => slug[], 'products' => slug[] ]
```

El envío es gratis cuando se cumplen estas condiciones:

1. `enabled` y la ciudad de destino tiene **al menos una opción marcada** («aplica envío gratis»).
2. La suma de los artículos del alcance que necesitan envío llega a `min_amount` (0 = sin mínimo).
   Una categoría cubre su descendencia; sin categorías ni productos cuenta todo el carrito.
3. El destino está en el país base. La reserva **nunca** es gratis.

Cuando se cumple, las tarifas de las **opciones marcadas** pasan a 0 con la meta visible
**«Envío gratis: Compra mínima de $X alcanzada»**. Las demás opciones se cobran. Los ids de esas
tarifas se publican en `extensions.hwe_free_shipping.rate_ids`.

El mismo cálculo (`FreeShipping::evaluate`) se publica en `extensions.hwe_free_shipping`, con los
importes en **unidades menores**: `enabled`, `threshold`, `eligible_subtotal`, `remaining`,
`qualifies`, `city` (`eligible` | `not_eligible` | `unknown`), `all_cities` y `scoped`, más
`rate_ids`.

`GET /free-shipping/scope` devuelve a qué aplica la regla, con nombres: `categories` (cada una con
`children`), `products` y `cities` (solo las ciudades con al menos una opción marcada). Cada categoría
y producto lleva `translations` (idioma → `{ name, slug }`) de `hwe-content-i18n`.

## Métodos como extras aditivos

Los métodos NO son alternativas de entrega. Son extras que el cliente marca en el checkout y que el
BFF suma al pedido como una línea de cargo (`fee_line`) tras crearlo (`POST /api/store/checkout`).
El importe se lee fresco (`no-store`) en ese momento. Como se suman después, un carrito en total 0 (puntos, cupón del 100 %,
descuento de socio) no admite extras: el checkout responde **422** antes de crear el pedido.

## Zona de reserva

`ReserveZone` crea y mantiene la zona del país base con el método `hwe_city_rate`. Con el módulo
activo la zona existe **siempre**, aunque `reserve_enabled` esté apagado. Si la zona tenía el
`flat_rate` que creó el plugin antes, lo sustituye. Idempotente; no toca las zonas del comerciante.

- `shipping.reserve_enabled`: **enviar también a las ciudades sin configurar.** Apagado, esas
  ciudades no tienen tarifa y solo se vende a las configuradas.
- `shipping.reserve_name` y `shipping.reserve_cost`: nombre y coste de la tarifa de reserva.

El método es nativo: el comerciante puede añadirlo además a otras zonas suyas. El cálculo no depende
de la zona en la que esté.

**Ojo con el orden de zonas.** WooCommerce usa la **primera** zona que coincide con el destino, y una
zona **sin regiones** coincide con todo. Si una zona propia del comerciante (o una vacía) va antes que la
de reserva y no lleva `hwe_city_rate`, el destino nunca llega a este método y las tarifas por ciudad no
aparecen (el estado `extensions.hwe_shipping` sí se calcula). Solución: añadir `hwe_city_rate` a esa
zona, quitarle el país base o moverla detrás de la de reserva (WooCommerce → Ajustes → Envío).

Se sincroniza al guardar el panel, al activar el plugin y con `wp hwe setup`; al desactivar el
plugin, la zona se retira (`ReserveZone::remove()`) para no dejar una zona sin métodos por delante de
las del comerciante.

## Contrato con el frontend

- **Módulo** `shipping` en `config.modules` (activo = plugin activo). Con el módulo apagado: las rutas
  `/api/store/shipping/destination`, `/api/store/free-shipping/scope`, `/api/shipping/geocode` y
  `/api/shipping/reverse-geocode` responden 404; el checkout no ofrece extras ni exige pin.
- **Config pública**: `shipping.distance_enabled` (boolean) = hay al menos una ciudad por distancia.
  Se publica por el filtro núcleo `hwe_control_center_public_config` (`PublicConfig`). Ningún otro
  campo de envío sale por `/wp-json/hwe/v1/config`.
- **Checkout sin tarifa**: el BFF rechaza con **409** `{ error, status }` (antes 422), con el `status`
  de `extensions.hwe_shipping` (`null` si el módulo está apagado).
- Lector y BFF en `frontend/src/lib/shipping/` y `frontend/src/app/api/shipping/`; ver
  `docs/FRONTEND_CONNECT.md` («Envío»).

## Geocodificación para el BFF

Rutas internas de WordPress para el buscador y la confirmación del mapa del checkout. Solo con
`X-HWE-Internal-Secret` (`hwe_has_internal_secret`): sin secreto, fallan cerradas (401).

| Ruta | Entrada | Salida |
|---|---|---|
| `POST hwe-shipping/v1/geocode` | `{ query }` (máx. 200 caracteres) | `{ results: [{ lat, lng, label }] }` (máx. 5) |
| `POST hwe-shipping/v1/reverse-geocode` | `{ lat, lng }` | `{ label }` |

Errores: `{ code }` con **422** (`no_results`, `invalid_query`, `invalid_point`) o **503**
(`geocoding_backoff`, `provider_unavailable`). No se filtran mensajes del proveedor. La respuesta no
incluye el proveedor de pago ni la clave, y lleva `Cache-Control: no-store`.

La clave del proveedor nunca sale de WordPress: el BFF no llama a los proveedores.

## Administración (wp-admin)

- **Envío → Tarifas de envío**: editor de ciudad con tipo, repetidor de opciones, dirección de
  referencia con **«Validar»** (geocodifica con el proveedor configurado y rellena lat/lng y la
  etiqueta, que el editor puede corregir) y radio. Columnas: tipo y número de opciones.
- **Control Center → Envío → Distancia**: campos de «Ajustes» y **«Probar conexión»** (una llamada real
  al proveedor configurado, con una dirección de prueba que escribe el administrador; sin valor por
  defecto de ningún país). Clasifica el resultado en `ok`, `missing_key`, `unauthorized`,
  `api_not_enabled`, `rate_limited`, `network` o `no_results`, con una pista en español. Nunca
  devuelve la clave ni parte de ella. Solo para `manage_woocommerce`.
- **Avisos** (`MapsNotices`, solo para `manage_woocommerce`): `distance_method = maps` sin proveedor o
  clave válidos (se calculará en línea recta), y clave guardada que no se puede descifrar.
- **Ciudades del modelo anterior** (`LegacyNotice`): lista de ciudades sin tipo, con enlace a cada una.
- **Pedido**: las metas de la línea de envío (ver «Metas»).

## Hooks estables

Publicados aquí y en `docs/PLUGIN_DEVELOPMENT.md`:

- `hwe_shipping_free_rule` (filtro): la regla de envío gratis (ver arriba).
- `hwe_shipping_free_reason` (filtro): `( ?string $reason, array $package, array $context ): ?string`.
  Otro módulo pide el envío gratis con un motivo propio y este plugin lo aplica con su lógica: deja
  a 0 solo las tarifas de las opciones marcadas como elegibles de la ciudad (nunca la reserva) y
  escribe el motivo en `_hwe_free_shipping_reason`. `$context` = `qualifies` (`bool`; la regla de
  la tienda se cumple), `mode` (`fixed`|`distance`|`reserve`|`none`) y `rate_ids` (`string[]`; ids
  de las tarifas elegibles del destino, vacío si no hay ninguna). Si devuelve un texto no vacío se
  aplica; cualquier otro valor se descarta y se registra. **La regla de la tienda tiene
  prioridad**: si ya califica, el filtro no se consulta. La constante
  `HWE_SHIPPING_FREE_REASON_FILTER` anuncia que esta versión lo admite; un módulo comprueba
  `defined()` antes de engancharse. El primer consumidor es `memberships`: pide el envío gratis
  del cupo de la membresía con el motivo `membership_free_delivery`; `shipping` decide qué
  tarifas elegibles bajan a 0 y nunca toca la reserva.
- `hwe_shipping_distance_providers` (filtro): `( array<string,Provider> $providers ): array`. Añade o
  sustituye proveedores por id. Un valor que no implemente `Provider` se descarta y se registra.
- `hwe_shipping_rate_cost` (filtro): `( float $cost, array $context ): float`. `$context` = `city`,
  `option_id`, `type` (`fixed`|`distance`|`reserve`) y `km` (o `null`). Ajusta el coste antes del envío
  gratis y del cupón. Un valor no numérico, infinito o negativo omite la tarifa y se registra.

## Modelo anterior (sin migración)

La versión 2.0.0 cambia el modelo de tarifas del módulo. **No hay migración**: las ciudades del
modelo anterior no producen tarifa hasta que se reconfiguran, y wp-admin lo avisa. En una instalación
nueva no hay nada que convertir.

- Cada ciudad tiene un único tipo y sus importes pasan a las opciones de entrega.
- Desaparecen la meta de importe único y la casilla de envío gratis de la ciudad (pasan a las opciones).
- El filtro `applyCityRate` desaparece: la tarifa la produce el método `hwe_city_rate`.
- La zona de reserva usa el método `hwe_city_rate` en lugar de `flat_rate`.
- `shipping.reserve_enabled` cambia de significado (ver «Zona de reserva»).
- El checkout sin tarifa responde 409 en lugar de 422.

## Pruebas

`backend/tests/Unit/Shipping/` (PHPUnit 12 + Brain Monkey): `PricingTest`, `CitiesTest`, `SaveCityTest`,
`CityAdminTest`, `CityRateMethodTest`, `ReserveZoneTest`, `ShippingTest` (envío gratis, cupón,
reserva y REST), `ShippingStatusExtensionTest`, `DeliveryPointTest`, `DistanceSettingsTest`,
`HaversineTest`, `ProvidersTest` (HTTP simulado: éxito y cada clase de error), `DistanceServiceTest`
(caché, respaldo y backoff), `AddressNormalizerTest`, `MapsAdminApiTest`, `MapsAdminUiTest`,
`GeocodeApiTest`, `OrderShippingMetaTest` (metas de la línea de envío en wp-admin).
