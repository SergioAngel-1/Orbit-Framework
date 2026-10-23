# Envío por distancia en el módulo `shipping` (`hwe-shipping`) — diseño

> Fecha: 2026-10-23 · Estado: aprobado en conversación, pendiente de plan.
> Origen: refactorización completa de un plugin de envío por distancia hecho para una instancia
> anterior (`/home/sergi/Documentos/Proyectos/Flores Inc/backend/app/public/wp-content/plugins/floresinc-distance-shipping`,
> ~4.600 líneas, más su consumo en el tema y el frontend de esa instancia).
> Se replican sus **flujos**, no su código. **Nada** del nombre, slugs, textos, dominios, país ni
> supuestos de esa instancia entra en el repo (puerta anti-instancia; esta carpeta
> `docs/superpowers/` está exenta, el resto no).

## 1. Intención

Que una tienda del framework pueda cobrar el envío **según la distancia real** entre un punto de
salida y el punto de entrega, ciudad por ciudad, conviviendo con las tarifas fijas que ya tiene el
módulo `shipping`. Ejemplo: en una ciudad el envío se cobra por km desde la tienda, en otra hay una
tarifa fija, y el resto del país paga el envío de reserva.

Es una funcionalidad **para implementaciones nuevas**: el modelo de tarifas por ciudad se rediseña
limpio, **sin migración** de los datos del modelo actual (decisión del usuario).

El framework es backend + arquitectura (`AGENTS.md §1.1`): esto **no diseña UI**. Entrega el
plugin con su administración, el contrato público, las rutas del BFF y la lógica de `lib/`. El mapa
del checkout, el selector de opciones y el botón «Cambiar» los construye cada instancia.

## 2. Decisiones del usuario

1. **Convive con las tarifas fijas, ciudad por ciudad.** Cada ciudad configurada tiene **un único
   tipo**: *Tarifa fija* o *Por distancia*. Nunca los dos para la misma ciudad. Las ciudades sin
   configurar pagan el envío de reserva (como hoy).
2. **Una ciudad por distancia tiene una dirección de referencia** (el punto de salida), elegida por
   ciudad.
3. **Opciones de entrega por ciudad, repetibles sin límite fijo** (p. ej. «Estándar», «Express»).
   Cada ciudad define las suyas. En una ciudad por distancia cada opción tiene su **precio por km**
   (con mínimo y máximo); en una ciudad fija, su **importe**.
4. **El envío gratis se elige por opción de entrega**, sin importar si la ciudad es fija o por
   distancia: la regla de envío gratis deja a 0 solo las opciones marcadas.
5. **Proveedores:** se mantienen los tres del origen: Google (Routes + Geocoding v4),
   OpenRouteService y línea recta (Haversine) + Nominatim, que es además el respaldo automático.
6. **Ubicación del cliente:** la marca en un mapa **la primera vez**; queda guardada con la
   dirección y se reutiliza sin nuevas peticiones; para cambiarla hay una acción explícita
   («Cambiar»).
7. **Sin migración**: no hay que convertir tarifas existentes.
8. **El pin es obligatorio** en las ciudades por distancia: sin ubicación marcada no hay tarifa ni
   pedido. No hay geocodificación de la dirección escrita como sustituto.
9. **La clave de mapas vive en Control Center → Integraciones** (junto al resto de integraciones),
   no en Envío. Todas las claves se usan solo en el servidor.

## 3. Objetivos, no-objetivos y anti-objetivos

**Objetivos**
- Paridad de flujos con el origen (§4) dentro del modelo por ciudad, sin sus defectos (§11).
- **El precio lo calcula WooCommerce en el servidor**, dentro del cálculo de tarifas: el navegador
  nunca envía ni firma un importe. La Store API devuelve las tarifas ya calculadas.
- Agnóstico de país: el país es `ecommerce.country`; nada asume un país concreto.
- Mínimas peticiones a APIs de pago: pin guardado, cachés, y el propio hash de paquete de
  WooCommerce para no recalcular sin cambios.
- Degradación suave y visible: si el proveedor falla, línea recta con incremento, marcada y
  registrada; nunca un envío a 0 por error.

**No-objetivos**
- UI de tienda (mapa, selector, «Cambiar»): la instancia la construye con el contrato (§8).
- Estrategia multisede (sede más cercana / más barata) y tope de sedes por consulta: con una sola
  dirección de referencia por ciudad desaparecen.
- Modos `hybrid` / `override` / `distance_only` del origen: el tipo por ciudad los sustituye.
- Migrar tarifas del modelo actual.
- Widget de estadísticas en el escritorio y la columna «Distancia» en el listado de pedidos
  (decisión propia, §13).

**Anti-objetivos (sería un fracaso aunque «funcione»)**
- Que el importe del envío salga del navegador o dependa de un token que el navegador reenvía.
- Que la API key viaje al navegador, salga en una URL o en un log.
- Que se pueda cerrar un pedido que necesita envío **sin** tarifa (destino fuera de cobertura o sin
  ubicación) o con un envío a 0 por un fallo.
- Que la lógica de precio exista en dos sitios (el origen la tenía en tres: motor, validación de
  pedido y frontend).
- Restos de la instancia de origen o de su país en código, textos o valores por defecto.

## 4. Flujos a replicar (paridad funcional)

| # | Flujo del origen | Cómo queda |
|---|---|---|
| F1 | Configurar proveedor, API key, modo de cálculo (conducción/caminando/línea recta), incremento de línea recta, TTL de caché | Proveedor y clave en Control Center → Integraciones; cálculo en Control Center → Envío (§6) |
| F2 | Sedes con dirección + «Validar» (geocodifica y rellena lat/lng) | Dirección de referencia en cada ciudad por distancia, con «Validar» (§5.2) |
| F3 | Radio de cobertura (global + por sede) | Radio de cobertura **por ciudad** (§5.1) |
| F4 | Precio por km por método de entrega, con mín./máx. | Opciones de entrega por ciudad (§5.1) |
| F5 | Geocodificar la dirección del cliente / aceptar coordenadas del frontend | Pin del cliente, obligatorio; la geocodificación solo ayuda a colocarlo (§7) |
| F6 | Calcular distancia y coste; respaldo Haversine si el proveedor falla | Método de envío nativo de WooCommerce (§5.3) |
| F7 | Cotización mostrada en el checkout y revalidada al crear el pedido | Store API `shipping_rates` (el cálculo es el mismo en carrito y pedido) |
| F8 | Fuera de cobertura / dirección no encontrada bloquea el checkout | Sin tarifa + estado explicado en `extensions` (§8.3); el pedido no se puede crear (§9) |
| F9 | Envío gratis aplicado sobre el envío por distancia | Envío gratis por opción (§5.4) |
| F10 | Datos de distancia en el pedido (km, sede, proveedor, respaldo) visibles en wp-admin | Metas de la línea de envío (§5.5) |
| F11 | Diagnóstico de la API key («Probar API key») | «Probar conexión» simplificado (§10) |
| F12 | Caché de distancias y geocodificaciones; limpieza periódica | Transients sobre la caché de objetos (Redis), sin tabla ni cron (§5.6) |
| F13 | Backoff de Nominatim ante 429/5xx/red | Se mantiene (§5.3) |

## 5. Modelo y backend (`hwe-shipping`)

### 5.1 Ciudad (CPT `hwe_shipping_rate`, rediseñado)

Sigue siendo el registro «Tarifa de envío» cuyo **título es la ciudad** (coincidencia sin
mayúsculas ni tildes, solo para el país base: `ShippingCost::normalizeCity` / `baseCountry`). Sus
datos pasan a ser:

| Campo | Tipo | Notas |
|---|---|---|
| Tipo | `fixed` \| `distance` | Uno solo por ciudad. |
| Opciones de entrega | lista repetible | Al menos una. Cada opción: **id estable** (generado al crear, no cambia al renombrar), **nombre**, **aplica envío gratis** (bool) y su precio: `amount` (ciudad fija) o `price_per_km` + `min_cost` + `max_cost` (ciudad por distancia; 0 = sin límite). |
| Dirección de referencia | texto + `lat`/`lng` + dirección resuelta | Solo `distance`. Obligatoria para publicar como `distance`. |
| Radio de cobertura (km) | número > 0 | Solo `distance`. Obligatorio (un radio 0 = «sin límite» no se admite: la cobertura es lo que hace segura la ubicación del cliente, §7.3). |

Se eliminan la meta de importe único y la casilla de envío gratis **de la ciudad** (pasan a las
opciones). El guardado valida y sanea todo en el servidor; una ciudad incompleta no se publica y el
editor ve por qué. Dos registros con la misma ciudad normalizada: el guardado lo impide.

### 5.2 Dirección de referencia

Botón «Validar» en el editor de la ciudad: geocodifica con el proveedor configurado (por un
endpoint REST **solo para administradores** del propio plugin, con nonce de WP) y muestra la
dirección resuelta y la coordenada; el editor puede corregir lat/lng a mano. Se guardan las
coordenadas, no se vuelve a geocodificar en cada cálculo. La dirección de referencia **no se expone**
por ninguna API pública ni en el pedido (solo el nombre de la ciudad).

### 5.3 Cálculo: método de envío nativo

- Un **método de envío propio de WooCommerce** (clase que extiende `WC_Shipping_Method`) sustituye
  al `flat_rate` en la **zona de reserva** que ya gestiona `ReserveZone` (que pasa a añadir este
  método en lugar de `flat_rate`). En `calculate_shipping($package)`:
  1. Destino fuera del país base → nada (igual que hoy).
  2. Ciudad sin registro → **una** tarifa de reserva (nombre y coste de `shipping.reserve_*`).
  3. Ciudad `fixed` → una tarifa por opción con su `amount`.
  4. Ciudad `distance` → resuelve el punto de entrega (§7), calcula la distancia desde la
     referencia, comprueba el radio y añade **una tarifa por opción**:
     `coste = clamp(round(km × price_per_km), min_cost, max_cost)`. Sin pin válido para ese
     destino → ninguna tarifa (`needs_location`); fuera de radio → ninguna tarifa
     (`out_of_coverage`). El estado lo explica (§8.3).
- **Zona del módulo.** Con el módulo activo, `ReserveZone` mantiene **siempre** su zona (país base)
  con este método, sustituyendo el `flat_rate` que ponía antes. `shipping.reserve_enabled` pasa a
  significar «enviar también a las ciudades sin configurar»: apagado, esas ciudades no tienen
  tarifa y solo se vende a las configuradas. Como es un método nativo, el comerciante puede
  añadirlo además a otras zonas suyas; el cálculo no depende de en qué zona esté.
- **Datos del modelo anterior.** Sin migración: una ciudad guardada con el modelo anterior (sin
  tipo) no produce tarifa y wp-admin muestra un aviso que las lista para reconfigurarlas.
- Ids de tarifa estables por opción (p. ej. `<método>:<id de opción>`), para que la selección del
  cliente sobreviva a recálculos.
- **Una sola implementación del precio** (una clase pura, testeable sin WordPress). Nada la
  duplica en el frontend.
- El filtro actual `applyCityRate` desaparece (la tarifa por ciudad ya la produce el método).
  `applyCouponFreeShipping` se mantiene: cupón con «envío gratuito» → todas a 0.
- Proveedores detrás de una interfaz (`calcular distancia`, `geocodificar`, `geocodificación
  inversa`): Google (Routes `computeRouteMatrix` con `TRAFFIC_AWARE` en conducción; Geocoding v4,
  eligiendo el resultado de mejor granularidad), OpenRouteService (directions + geocode) y línea
  recta (Haversine × (1 + incremento %), duración estimada por velocidad media; geocodifica con
  Nominatim con backoff: 30 s en error de red, 2 min en 5xx, 15 min en 429). Región/país de las
  peticiones = `ecommerce.country`; idioma = idioma principal del sitio.
- **Respaldo:** si Google/ORS falla al calcular la distancia → línea recta, la tarifa queda marcada
  como respaldo con el motivo y se registra (`error_log('[hwe] shipping: …')`). Si la
  geocodificación del proveedor falla (buscador del mapa, «Validar» del admin) → Nominatim. Nunca
  un importe inventado. La «tarifa de
  respaldo» fija del origen desaparece (decisión propia, §13).
- Normalización de direcciones para el geocodificador: **por datos del paquete de país**, no por
  código. El paquete (`hwe_geo_packs`) gana una clave opcional de abreviaturas de vía
  (abreviatura → forma completa) y de tokens que se descartan; el paquete `CO` lleva las del
  origen (Cl/Cll, Cra/Kra, Ak, Ac, Dg, Tv, «#», «BIS», cardinales…), el `generic` ninguna.

### 5.4 Envío gratis por opción

- `FreeShipping` deja de mirar la casilla de la ciudad y mira la de **cada opción**: con la regla
  cumplida (mínimo y alcance, sin cambios), las tarifas cuyas opciones estén marcadas pasan a 0 y
  llevan la meta visible «Envío gratis: …» (como hoy); las demás se cobran.
- Estado de ciudad para la regla: `eligible` si la ciudad de destino tiene al menos una opción
  marcada; la reserva nunca es gratis (como hoy).
- `extensions.hwe_free_shipping` añade **qué opciones** califican (ids de tarifa) además de lo que
  ya publica; `free-shipping/scope` sigue listando ciudades con al menos una opción gratis.

### 5.5 Datos en el pedido

Las tarifas llevan meta que WooCommerce copia a la **línea de envío** del pedido: ciudad, opción,
tipo, km, duración estimada, proveedor, respaldo (sí/no + motivo), punto de entrega (lat/lng)
y un enlace a mapa del punto para quien reparte. Visible en
wp-admin y en los correos según convenga (las metas técnicas, ocultas con prefijo `_`). Nada de la
dirección de referencia.

### 5.6 Cachés

- Distancia: clave = referencia (4 decimales) + destino (4 decimales) + proveedor + modo +
  incremento; TTL `cache_ttl_hours` (resultado normal) o 15 min (resultado de respaldo).
- Geocodificación: dirección normalizada + proveedor, 7 días; inversa por coordenada.
- Transients (respaldados por la caché de objetos/Redis del framework). Sin tabla propia, sin
  cron, sin flag de esquema.
- El punto de entrega entra en el **paquete** (`woocommerce_cart_shipping_packages`) para que el
  hash de paquete de WooCommerce cambie al cambiar el pin y no se recalcule sin cambios.
- Al guardar una ciudad o el panel: se invalidan la caché de ciudades y la versión del transient de
  envíos de WooCommerce (como hoy), no las distancias (dependen de coordenadas, no de precios).

## 6. Control Center

### 6.1 Integraciones → Mapas (núcleo)

El grupo `integrations` del núcleo (`SchemaGroups/Integrations.php`) gana una integración
**genérica de mapas**, reutilizable por cualquier módulo:

| Campo | Tipo | Notas |
|---|---|---|
| `maps_provider` | select | `none` \| `google_maps` \| `openrouteservice`. `public: false`. |
| `maps_api_key` | `secret` | Cifrada con `SecretsStorage` (AES-256-GCM), `public: false`. |

La usa WordPress (el módulo `shipping` hace las llamadas a los proveedores); la clave no sale por
ninguna API, ni pública ni interna del BFF (el BFF no llama a los proveedores, §8.2).

### 6.2 Envío → Distancia (módulo)

El grupo `shipping` (lo puebla el módulo) suma un bloque **«Distancia»** con nota de que solo
aplica a las ciudades por distancia (el Control Center no tiene campos condicionales; no se añaden
al núcleo):

| Campo | Tipo | Notas |
|---|---|---|
| `distance_method` | select | `maps` (el proveedor de Integraciones → Mapas) \| `straight_line`. Con `maps` y sin proveedor/clave válidos, se calcula en línea recta y wp-admin avisa. |
| `distance_travel_mode` | select | `driving` \| `walking`. |
| `distance_straight_line_increment` | número 0–200 | % sobre la línea recta (también cuando es respaldo). |
| `distance_cache_ttl_hours` | número ≥ 1 | Por defecto 24. |

`reserve_*` se mantienen (con el significado nuevo de `reserve_enabled`, §5.3). Ninguno de estos
campos sale por `/wp-json/hwe/v1/config` salvo lo de §8.1.

## 7. Punto de entrega (pin)

### 7.1 Principio

El pin **es** el punto de entrega: quien reparte va ahí. Por eso no hace falta firmarlo ni
cotejarlo con la dirección escrita: mover el pin para pagar menos solo cambia dónde se entrega. Lo
único que se exige es que caiga **dentro del radio** de la ciudad de destino.

### 7.2 Cómo llega al cálculo

- **Cliente con cuenta:** la dirección guardada en la libreta (`SavedAddress`) gana un campo
  opcional `location: { lat, lng }`. Se marca una vez en el mapa y se guarda con la dirección; en
  las compras siguientes se reutiliza sin peticiones. «Cambiar» = la instancia vuelve a abrir el
  mapa y guarda la nueva ubicación. Editar la dirección escrita (calle o ciudad) **borra** la
  ubicación guardada: hay que volver a marcarla (decisión propia).
- **Invitado:** el pin vive en la sesión del carrito.
- **Transporte:** el BFF fija el destino del carrito (`/cart/update-customer` con `address_1`,
  ciudad, estado, país) y el punto por la Store API con una **extensión del módulo**
  (`woocommerce_store_api_register_update_callback`, el mismo patrón que `hwe-loyalty`), que lo
  valida (rango numérico) y lo guarda en la sesión de WooCommerce junto a una huella de la
  dirección. El método solo usa el punto si la huella coincide con el destino del paquete.
- **Sin pin:** no hay tarifa para una ciudad por distancia; el estado (§8.3) dice
  `needs_location`. La dirección escrita no se geocodifica para calcular: la geocodificación solo
  sirve para **ayudar a colocar** el pin (buscador del mapa, §8.2). Una instancia que active
  ciudades por distancia tiene que construir el mapa.

### 7.3 Validación

- Coordenadas numéricas en rango; dentro del radio de la ciudad → válido. Fuera → sin tarifa,
  `out_of_coverage`.
- Al crear el pedido no hay revalidación aparte: WooCommerce recalcula las tarifas con el mismo
  paquete y la misma lógica.

## 8. Contrato con el frontend

### 8.1 Configuración pública

`config.modules.shipping` (como hoy). Se añade lo mínimo para que una instancia sepa si debe
ofrecer mapa, sin secretos: si existe al menos una ciudad por distancia (entonces el mapa es
obligatorio para esas ciudades; el buscador siempre está disponible, con el proveedor de
Integraciones → Mapas o con Nominatim). Las ciudades y su tipo salen por `GET hwe-shipping/v1/rates`
(solo nombres + tipo; nunca importes ni referencias).

### 8.2 BFF

| Ruta | Qué hace |
|---|---|
| `PUT /api/store/shipping/destination` (existe) | Acepta además `address_1` y `location` opcional; envía el destino y el punto (extensión) en una sola actualización de carrito. `guardMutation` + `requireModule`. |
| `POST /api/store/addresses` / `PUT` (existen) | Aceptan `location` opcional en la dirección; se invalida al cambiar calle o ciudad. |
| `POST /api/shipping/geocode` (nueva) | Para el buscador del mapa: dirección → candidatos con coordenada. Clave solo en el servidor (WordPress hace la llamada; el BFF habla con WordPress con `X-HWE-Internal-Secret`). `guardMutation` con rate-limit propio, `requireModule`. |
| `POST /api/shipping/reverse-geocode` (nueva) | Coordenada → dirección legible, para confirmar el pin. Mismas guardas. |

Reglas de las dos rutas de geocodificación: **búsqueda al enviar**, no en cada tecla (la política de
uso de Nominatim lo prohíbe y con Google cuesta): se documenta en el contrato y el rate-limit lo
hace cumplir. Con sesión, límite por usuario; sin sesión, solo si la tienda admite compra de
invitado (`ecommerce.checkout_login_required` apagado), con límite por IP; si no, 401. Respuestas
cacheadas en WordPress (§5.6); con Nominatim, su backoff se respeta y el error se devuelve claro.

Los mapas en sí (teselas, librería, clave de navegador si se usa Google en el cliente) son
decisión de la instancia y se documentan en `FRONTEND_CONNECT.md` como guía, no como código.

### 8.3 Estado del envío en el carrito

`cart.extensions.hwe_shipping` (nuevo) dice qué pasa con el destino actual, para que la UI explique
en vez de mostrar «sin tarifas»:

- `mode`: `fixed` | `distance` | `reserve` | `none`.
- `status`: `ok` | `needs_address` | `needs_location` | `out_of_coverage`. Todo lo que no es `ok`
  significa **sin tarifas** para ese destino.
- Si `distance` y `ok`: `distance_km`, `duration_min`, `fallback` (bool).

Cada tarifa de `shipping_rates` lleva en su meta el id de opción, el tipo y, si aplica, km y
respaldo. Los importes en unidades menores, como el resto de la Store API.

### 8.4 `lib/`

`lib/shipping/` suma tipos y normalizadores del estado (§8.3), de las metas de tarifa y de
`location` en direcciones (`lib/validation/address.ts`); `destinationFromAddress` incluye
`address_1` y la ubicación. Sin lógica de precio.

## 9. Checkout sin tarifa

Un carrito que necesita envío y no tiene tarifa (fuera de cobertura, sin pin) **no
puede convertirse en pedido**. El plan debe verificar si la Store API ya lo rechaza; si no, el BFF
(`/api/store/checkout`) lo rechaza con un error explícito antes de crear el pedido (409 con el
`status` de §8.3). Test que lo pruebe.

## 10. Administración (wp-admin)

- **Envío → Tarifas de envío:** editor de ciudad con tipo, repetidor de opciones, dirección de
  referencia con «Validar» y radio. Columnas del listado: tipo y número de opciones.
- **Control Center → Envío → Distancia:** campos de §6 + **«Probar conexión»**: una llamada real al
  proveedor configurado (geocodificar una dirección de prueba editable, sin valor por defecto de
  ningún país) y una respuesta clasificada: correcta / clave rechazada / API no habilitada /
  cuota / red, con una pista accionable. Sin la clave en ninguna URL, sin sondeos a servicios de
  IP pública. Solo administradores.
- **Pedido:** las metas de la línea de envío (§5.5), con el enlace al mapa del punto.
- Aviso de admin si el proveedor necesita clave y no hay (o no se puede descifrar).

## 11. Defectos del origen que no se replican

1. **Precio en el navegador + firmas HMAC + revalidación con tolerancias** (5 % / 15 %, ventana de
   15 min, token de dirección de 24 h): sobra entero con el cálculo dentro de WooCommerce.
2. **Lógica de precio triplicada** (motor, validación de pedido, frontend) con resoluciones de
   precio/límites ligeramente distintas: una sola implementación.
3. **País cableado** (límites de coordenadas de un país, `countrycodes`, `regionCode`,
   normalizador de direcciones en código): país de la tienda y datos del paquete de país.
4. **Cifrado propio** de la API key (AES-CBC sin autenticar con `AUTH_KEY`): `SecretsStorage`.
5. **API key en la URL** del diagnóstico (Geocoding v3) y filtro de redacción del log que no
   redactaba: solo cabeceras.
6. **Bloqueo con `usleep`** en la petición para coalescer cálculos: se elimina; la caché y el hash
   de paquete bastan.
7. **Tabla propia + cron + flag de esquema + `ALTER TABLE` en caliente**: transients.
8. **Dependencias de la instancia** (clases del tema para precios por método, beneficio de
   domicilios gratis, JWT propio, helper de IP): nada de eso.
9. **Rutas duplicadas** (`quote` y `calculate-distance` hacían lo mismo) y código muerto (selector
   de geocodificación legado, campos ocultos de precio global).
10. **Widget de escritorio con escaneo de metas sin índice**: fuera.
11. **Coordenadas del pedido de confianza a medias** (aceptadas si venía un token, ignoradas si
    no): el pin es el punto de entrega y se valida contra la cobertura.
12. **Fallo de cálculo que podía dejar una tarifa de respaldo fija** sin que el cliente supiera
    nada: sin tarifa y estado explicado.

## 12. Restricciones del framework (obligatorias)

- Secretos solo en WordPress (`SecretsStorage`); el navegador nunca ve la clave.
- Todo lo que escribe pasa por el BFF con `guardMutation`; WordPress solo atiende al BFF
  (`hwe-bff-only`, `X-HWE-Internal-Secret`); los endpoints REST nuevos de geocodificación para el
  BFF exigen el secreto interno, los de wp-admin exigen capacidad + nonce.
- Módulo apagado → lector vacío, rutas 404 (`requireModule`), extensión no registrada.
- Ningún fallo en silencio: `error_log('[hwe] shipping: …')` en WP, `logger` con `event` en el BFF.
  La seguridad falla cerrada (sin punto o sin cobertura → sin tarifa); el contenido, suave.
- i18n: textos de API/errores para el cliente en el BFF con claves en `es.json` y `en.json`.
- Compatibilidad HPOS (no se toca la tabla de pedidos directamente).
- PHP 8, namespace `HWE\Shipping`, autoload PSR-4 del plugin, estilo de los archivos vecinos.
- Puerta anti-instancia: nada del origen (ni su país en valores por defecto, ni direcciones de
  ejemplo de una ciudad concreta).

## 13. Decisiones propias (revisables)

1. Método de envío nativo en la zona de reserva en lugar de filtrar `flat_rate` (necesario para
   N tarifas por ciudad).
2. Sin «tarifa de respaldo» fija: si no se puede calcular, no hay tarifa.
3. Fuera de radio en una ciudad por distancia → sin tarifa (no cae a la reserva), como el origen.
4. El envío gratis por regla nunca aplica a la reserva (como hoy).
5. Editar calle o ciudad de una dirección guardada borra su ubicación.
6. Radio de cobertura obligatorio y > 0 en ciudades por distancia.
7. Sin columna «Distancia» en el listado de pedidos ni widget de escritorio; los datos van en la
   línea de envío.
8. «Probar conexión» simplificado, sin sondeo de IP pública ni Geocoding v3.
9. Normalización de direcciones como datos del paquete de país.
10. Transients en lugar de tabla propia.
11. Endpoints de geocodificación para el mapa expuestos por el BFF (la clave del proveedor no sale
    del servidor).
12. La zona del módulo existe siempre con el módulo activo; `reserve_enabled` = enviar a ciudades
    sin configurar (§5.3).
13. Ciudades del modelo anterior: sin tarifa + aviso en wp-admin; la sincronización sustituye el
    `flat_rate` de la zona. La versión del framework se decide al publicar.
14. Geocodificación para el mapa: al enviar, cacheada, invitados solo si la tienda admite compra
    de invitado (§8.2).
15. Mapas como integración genérica del núcleo (Integraciones → Mapas) con `distance_method` en
    Envío; la clave solo la usa WordPress.

## 14. Extensibilidad (hooks estables)

Publicados en el `README.md` del módulo y en `docs/PLUGIN_DEVELOPMENT.md`:
- `hwe_shipping_distance_providers` (filtro): registrar o sustituir proveedores por id.
- `hwe_shipping_rate_cost` (filtro): ajustar el coste final de una tarifa (contexto: ciudad,
  opción, km, tipo) antes del envío gratis y el cupón.

## 15. Pruebas y verificación

- **PHPUnit + Brain Monkey** (`backend/tests/Unit/Shipping/`): precio (clamp, redondeo), las tres
  ramas del método (reserva/fija/distancia), `reserve_enabled` apagado, ciudad del modelo anterior,
  radio, respaldo a línea recta, punto de sesión con y sin huella coincidente, sin pin →
  sin tarifa, envío gratis por opción, cupón, proveedores
  con HTTP simulado (éxito y cada clase de error), backoff de Nominatim, guardado y validación de
  la ciudad, «Probar conexión», extensión de Store API.
- **Vitest:** rutas BFF nuevas y ampliadas (guardas, 404 con módulo apagado, validación de
  `location`), normalizadores de `lib/shipping`, rechazo del checkout sin tarifa.
- **CI:** stub de WordPress con una ciudad por distancia para el e2e; la segunda pasada sin
  módulos sigue verde. `next build` antes de cerrar.
- **En vivo** (pila levantada): cada proveedor con clave real, fuera de cobertura, respaldo forzado,
  pedido con la línea de envío y sus metas. Lo no probado en vivo se dice.

## 16. Documentación a actualizar

`hwe-shipping/README.md` y `MANUAL.md` (modelo por ciudad, opciones, distancia, proveedores),
`docs/FRONTEND_CONNECT.md` («Envío»: estado, metas, pin, rutas, guía de mapa),
`docs/CONFIGURATION.md` (campos de §6, incluida Integraciones → Mapas), `docs/PLUGIN_DEVELOPMENT.md` (hooks de §14), paquete de
país en `FRONTEND_CONNECT.md` («Paquetes por país»: abreviaturas), `CHANGELOG.md` (cambio del
modelo de tarifas del módulo `shipping`, sin migración).

## 17. Lo que queda al implementador

- Forma exacta del repetidor de opciones en el editor y del almacenamiento (meta JSON u otra).
- Nombres de clases, rutas REST internas y claves de meta.
- Formato de la huella de dirección y de la clave de sesión del punto.
- Textos del manual y de los mensajes de «Probar conexión».
- Orden de tareas y commits.
