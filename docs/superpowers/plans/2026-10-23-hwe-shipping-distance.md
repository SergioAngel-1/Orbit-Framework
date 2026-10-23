# Envío por distancia en el módulo `shipping` — plan de implementación

> **Para agentes:** SUB-SKILL REQUERIDA: usa superpowers:subagent-driven-development (recomendado) o
> superpowers:executing-plans para ejecutar este plan tarea por tarea. Los pasos usan casillas
> (`- [ ]`) para el seguimiento.
>
> **Estilo del plan (preferencia del usuario):** sin código literal. Cada paso dice qué lograr,
> dónde, con qué nombres y cómo se comprueba; el cómo concreto es criterio del agente, siempre
> dentro del spec, de este plan y, sobre todo, de `AGENTS.md`/`CLAUDE.md`. Ante una duda que el
> spec no resuelve, manda la convención del proyecto (mira cómo lo hace el archivo vecino); si
> tampoco la resuelve, decide y anótalo en el mensaje del commit.

**Objetivo:** que el módulo `shipping` cobre el envío ciudad por ciudad con tarifa fija o por
distancia real desde una dirección de referencia, con opciones de entrega repetibles por ciudad,
envío gratis por opción y pin del cliente obligatorio, sin UI.

**Arquitectura:** WooCommerce calcula todo en el servidor: un método de envío nativo del plugin
`hwe-shipping`, en la zona que ya gestiona `ReserveZone`, produce una tarifa por opción de entrega
de la ciudad de destino. Las distancias salen de proveedores intercambiables (Google, OpenRouteService,
línea recta + Nominatim) con caché en transients y respaldo automático a línea recta. El pin del
cliente viaja por una extensión de la Store API y se guarda en la sesión de WooCommerce. El BFF
fija destino y pin, guarda la ubicación en la libreta y sirve geocodificación para el mapa a través
de WordPress. La clave de mapas vive en Control Center → Integraciones y solo la usa WordPress.

**Stack:** PHP 8 + WordPress/WooCommerce (Store API, `WC_Shipping_Method`, transients), PHPUnit 12 +
Brain Monkey; Next.js 16 (Route Handlers), TypeScript, Zod, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-hwe-shipping-distance-design.md` (léelo entero antes
de la primera tarea; las referencias `§N` apuntan a él). El plugin de origen está en
`/home/sergi/Documentos/Proyectos/Flores Inc/backend/app/public/wp-content/plugins/floresinc-distance-shipping/`:
**solo como referencia de flujos y de las APIs de los proveedores** (formas de petición/respuesta,
clasificación de errores); no se copia código ni textos.

## Restricciones globales

- Docs, comentarios y mensajes en **español**; código y nombres técnicos en **inglés**.
- Puerta anti-instancia: ningún nombre, slug, dominio, texto, país por defecto ni dirección de
  ejemplo de la instancia de origen fuera de `docs/superpowers/` (`bash .github/scripts/check-anti-instance.sh`
  en verde al cerrar cada tarea; el patrón también caza palabras comunes que contengan un token
  prohibido: usa sinónimos).
- Plugin: `backend/wp-content/plugins/hwe-shipping/`, namespace `HWE\Shipping`, autoload PSR-4 ya
  existente (`includes/Foo/Bar.php` ↔ `HWE\Shipping\Foo\Bar`), estilo de tabs y arrays de los
  archivos vecinos. Sube la cabecera a `Version: 2.0.0` y `HWE_SHIPPING_VERSION` (cambio de
  modelo sin migración).
- Prefijos: metas `_hwe_shipping_`, transients `hwe_shipping_`, hooks `hwe_shipping_`, extensión de
  carrito y namespace Store API `hwe_shipping` (además del existente `hwe_free_shipping`), método
  de envío `hwe_city_rate`, ids de tarifa `hwe_city_rate:<optionId>` y `hwe_city_rate:reserve`.
- País = `ShippingCost::baseCountry()` (de `ecommerce.country`); nunca un país escrito a mano.
  Idioma de las peticiones a proveedores = idioma principal del sitio (`brand.locale`).
- Ajustes leídos con `\HWE\ControlCenter\Storage::get` / `SecretsStorage::get` y fallback; nunca
  fatal sin el Control Center.
- La clave de mapas **nunca** sale de WordPress: ni config pública, ni API interna del BFF, ni URL,
  ni log. Peticiones a Google con `X-Goog-Api-Key`, a ORS con `Authorization`.
- Rutas REST de WP para el BFF: solo con `X-HWE-Internal-Secret` (`hwe_has_internal_secret`),
  fallan cerradas. Rutas de wp-admin: capacidad `manage_woocommerce` + nonce `wp_rest`.
- BFF: `requireModule(config, "shipping")` al inicio de cada ruta del módulo (404); `guardMutation`
  en toda escritura; WordPress siempre vía `wpFetch`/`storeFetch`; `import "server-only"` en módulos
  de servidor; navegación y textos según `CLAUDE.md`.
- Importes de la Store API en unidades menores; costes redondeados a la precisión de la moneda de
  Woo. **Una sola implementación del precio** (`Pricing`), nada en el frontend.
- Ningún fallo en silencio: `error_log('[hwe] shipping: …')` en WP; `logger.*` con `event` en el
  BFF. Seguridad falla cerrada (sin pin, sin cobertura, sin secreto → sin tarifa / 401 / 404);
  contenido falla suave.
- Un commit por tarea terminada, en verde, con `git add` de rutas explícitas; mensaje en español
  estilo Conventional Commits terminado en `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
  Rama: `feat/hwe-shipping-distance` (créala desde `main` antes de la Tarea 1).
- Verificación por tarea: backend `cd backend && composer test`; frontend `cd frontend && npm run
  lint && npm run type-check && npm run format:check && npm run i18n:check && npm run test`; y
  **`npx next build`** antes de cerrar cualquier tarea que toque `frontend/`.
- Sé honesto sobre lo no verificado en vivo (proveedores reales, Store API real, checkout): dilo en
  el resumen de la tarea si no levantaste la pila.

## Foco de revisión

1. **El cliente cambia la calle (misma ciudad) después de marcar el pin**: la huella ya no coincide,
   el carrito pasa a `needs_location` y no conserva la tarifa vieja → test en Tarea 6.
2. **Proveedor que responde 200 con datos absurdos** (sin ruta, distancia 0 o negativa, duración
   rara) o que agota el tiempo: respaldo a línea recta marcado, nunca coste 0 por error → test en
   Tarea 4.
3. **Coordenadas basura** (NaN, cadenas, fuera de rango, lat/lng invertidas, 0,0): 422 en el BFF e
   ignoradas en WordPress → tests en Tareas 6 y 10.
4. **Variantes del nombre de ciudad** (mayúsculas, tildes, espacios dobles) entre el registro y el
   destino, y dos registros de la misma ciudad: coinciden igual y el guardado impide el duplicado
   → test en Tarea 2.
5. **Opción renombrada o borrada con una tarifa ya seleccionada en un carrito abierto**: el id de
   la opción no cambia al renombrar; al borrarla, WooCommerce cae a otra tarifa sin error → test
   en Tarea 7.

---

### Tarea 1: Integraciones → Mapas en el núcleo

**Archivos:**
- Modificar: `backend/wp-content/mu-plugins/hwe-control-center/SchemaGroups/Integrations.php`
- Modificar: `docs/CONFIGURATION.md` (sección de Integraciones)
- Test: el test existente del esquema en `backend/tests/Unit/ControlCenter/` que fija los campos de
  `integrations` (o uno nuevo colocado allí)

**Interfaces:**
- Produce: `integrations.maps_provider` (`select`: `none` | `google_maps` | `openrouteservice`,
  por defecto `none`, `public: false`) y `integrations.maps_api_key` (`secret`, `public: false`),
  legibles con `Storage::get(['integrations','maps_provider'])` y
  `SecretsStorage::get(['integrations','maps_api_key'])`.

- [ ] Test: los dos campos existen con esos tipos, opciones y `public: false`, y ninguno aparece en
      la salida pública de `/hwe/v1/config`. Ejecutar y ver fallar.
- [ ] Añadir los campos (bloque «Mapas», descripción que diga que la clave se guarda cifrada, que
      solo la usa el servidor y que para Google necesita Routes API + Geocoding API habilitadas
      con restricción por IP del servidor, no por referer).
- [ ] Documentar en `CONFIGURATION.md`. `composer test` en verde.
- [ ] Commit `feat(control-center): integración genérica de mapas (proveedor y clave)`.

### Tarea 2: Modelo de ciudad y opciones de entrega

**Archivos:**
- Crear: `includes/Rates/City.php`, `includes/Rates/DeliveryOption.php`, `includes/Rates/GeoPoint.php`
  (valores inmutables), `includes/Rates/Cities.php` (repositorio con caché)
- Modificar: `includes/Save.php` (guardado de la ciudad), `includes/MetaBoxes.php` (editor),
  `includes/AdminColumns.php` (tipo y nº de opciones), `includes/ShippingCost.php` (quitar el mapa
  `ratesByCity`/`freeShippingCities` basado en la meta vieja; conservar `normalizeCity`,
  `baseCountry`, `applyCouponFreeShipping`, `hasFreeShippingCoupon`, `clearCache`)
- Crear: aviso de admin para ciudades del modelo anterior (en `includes/Admin/LegacyNotice.php` o
  donde encaje con el estilo del plugin)
- Assets de admin del repetidor: en `assets/` del plugin, encolados solo en la pantalla del CPT
- Test: `backend/tests/Unit/Shipping/CitiesTest.php`, `backend/tests/Unit/Shipping/SaveCityTest.php`
  (separa del `ShippingTest.php` existente lo que dependa del modelo viejo y adapta ese archivo)

**Interfaces:**
- Produce:
  - `GeoPoint(float $lat, float $lng)`; `GeoPoint::tryFrom(mixed $lat, mixed $lng): ?GeoPoint`
    (null si no son numéricos finitos o están fuera de [-90,90]/[-180,180]).
  - `DeliveryOption`: `id: string` (estable, generado al crear, p. ej. aleatorio corto en
    minúsculas), `name: string`, `freeEligible: bool`, `amount: ?float` (fija),
    `pricePerKm: ?float`, `minCost: float`, `maxCost: float` (0 = sin límite).
  - `City`: `postId: int`, `name: string`, `type: 'fixed'|'distance'`, `options: DeliveryOption[]`
    (≥ 1), `origin: ?GeoPoint`, `originLabel: string`, `coverageKm: float`.
  - `Cities::forCity(string $city): ?City` (por `ShippingCost::normalizeCity`), `Cities::all(): City[]`,
    `Cities::hasDistanceCities(): bool`, `Cities::freeEligibleCityNames(): string[]`,
    `Cities::legacyPostIds(): int[]`, `Cities::clearCache(): void` (también sube la versión del
    transient de envíos de Woo, como hace hoy `ShippingCost::clearCache`).
  - Metas: `_hwe_shipping_city_type`, `_hwe_shipping_city_options` (JSON),
    `_hwe_shipping_city_origin_address`, `_hwe_shipping_city_origin_lat`,
    `_hwe_shipping_city_origin_lng`, `_hwe_shipping_city_origin_label`,
    `_hwe_shipping_city_coverage_km`.

- [ ] Tests de `Cities`: lee una ciudad fija y una por distancia; coincide con mayúsculas/tildes/
      espacios distintos; una ciudad sin `_hwe_shipping_city_type` no se devuelve y sí aparece en
      `legacyPostIds()`; caché invalidada por `clearCache`.
- [ ] Tests de guardado: tipo inválido → no se publica (queda en borrador con aviso); ciudad sin
      opciones → no publica; ciudad por distancia sin coordenadas de referencia o con radio ≤ 0 →
      no publica; opción con `minCost > maxCost` (ambos > 0) → se rechaza con aviso; precios
      negativos → se rechazan; un id de opción existente se conserva al renombrar; ciudad
      normalizada duplicada → no publica; nonce/capacidad ausentes → no guarda.
- [ ] Ejecutar y ver fallar.
- [ ] Implementar valores, repositorio y guardado. Saneado en el servidor; el editor ve por qué no
      se publicó (aviso de admin con el motivo).
- [ ] Editor de la ciudad: selector de tipo; repetidor de opciones (añadir, quitar, reordenar; los
      campos de precio cambian según el tipo); dirección de referencia con botón «Validar» (lo
      conecta la Tarea 5; aquí deja el campo, lat/lng editables y la etiqueta resuelta) y radio.
      Sin JS inline: asset encolado, accesible con teclado.
- [ ] Aviso de admin global si `legacyPostIds()` no está vacío, con enlace a cada ciudad.
- [ ] `composer test` en verde. Commit `feat(shipping): modelo de ciudad con tipo y opciones de entrega`.

### Tarea 3: Precio, ajustes de distancia y proveedor de línea recta

**Archivos:**
- Crear: `includes/Rates/Pricing.php`, `includes/Distance/Settings.php`,
  `includes/Distance/Provider.php` (interfaz), `includes/Distance/DistanceResult.php`,
  `includes/Distance/GeocodeResult.php`, `includes/Distance/Haversine.php`
- Modificar: `includes/ReserveZone.php` (`schema()`: añade los campos de §6.2 al grupo `shipping`;
  mueve el registro del esquema a una clase propia si `ReserveZone` queda con dos
  responsabilidades)
- Test: `backend/tests/Unit/Shipping/PricingTest.php`, `backend/tests/Unit/Shipping/DistanceSettingsTest.php`

**Interfaces:**
- Produce:
  - `Pricing::cost(DeliveryOption $option, ?float $km): float` — fija: `amount`; distancia:
    `round(km × pricePerKm)` y después `minCost`/`maxCost` (0 = sin límite), redondeado a la
    precisión de la moneda (`wc_get_price_decimals()`). `km` null con opción por distancia →
    excepción de programación (no debe ocurrir).
  - `Settings::method(): 'maps'|'straight_line'`, `::mapsProvider(): 'none'|'google_maps'|'openrouteservice'`,
    `::mapsKey(): string`, `::mapsConfigured(): bool` (proveedor ≠ none y clave no vacía),
    `::travelMode(): 'driving'|'walking'`, `::incrementPct(): int` (0–200, saneado),
    `::cacheTtlHours(): int` (≥ 1, por defecto 24). Campos del esquema: `distance_method`
    (por defecto `straight_line`), `distance_travel_mode` (`driving`),
    `distance_straight_line_increment` (`30`), `distance_cache_ttl_hours` (`24`).
  - `DistanceResult`: `km: float`, `durationMin: int`, `provider: string`, `fallback: bool`,
    `fallbackReason: string`.
  - `GeocodeResult`: `point: GeoPoint`, `label: string`, `precision: string`.
  - `Provider` (interfaz): `id(): string`, `distance(GeoPoint $from, GeoPoint $to, string $mode): DistanceResult|\WP_Error`,
    `geocode(string $query, string $country, string $language): array|\WP_Error` (lista de
    `GeocodeResult`, mejor primero), `reverse(GeoPoint $point, string $language): GeocodeResult|\WP_Error`.
  - `Haversine::km(GeoPoint $a, GeoPoint $b): float`; `Haversine::estimate(GeoPoint $a, GeoPoint $b, int $incrementPct, string $mode): DistanceResult`
    (km × (1 + incremento/100), duración a 30 km/h conduciendo o 5 km/h caminando,
    `provider = 'straight_line'`).

- [ ] Tests de `Pricing`: fija devuelve `amount`; por km con `km=4.35`, `pricePerKm=1000` → 4350;
      mínimo eleva, máximo recorta, 0 = sin límite; redondeo a decimales de la moneda.
- [ ] Tests de `Settings`: valores fuera de rango o basura se sanean a los por defecto/limites;
      sin Control Center devuelve los por defecto; `mapsConfigured()` falso sin clave.
- [ ] Tests de `Haversine`: distancia conocida entre dos puntos (tolerancia razonable), incremento
      aplicado, duraciones por modo.
- [ ] Ejecutar, ver fallar, implementar, ver pasar.
- [ ] Commit `feat(shipping): precio por opción, ajustes de distancia y línea recta`.

### Tarea 4: Proveedores HTTP, normalizador y servicio de distancias

**Archivos:**
- Crear: `includes/Distance/GoogleProvider.php` (Routes `computeRouteMatrix` + Geocoding v4),
  `includes/Distance/OpenRouteServiceProvider.php` (directions + geocode/search + geocode/reverse),
  `includes/Distance/StraightLineProvider.php` (Haversine + Nominatim con backoff),
  `includes/Distance/AddressNormalizer.php`, `includes/Distance/DistanceService.php`
- Modificar: `backend/wp-content/mu-plugins/hwe-geo/packs/co.php` (clave nueva de abreviaturas),
  el contrato del paquete (`generic.php` con la clave vacía) y su documentación en
  `docs/FRONTEND_CONNECT.md` («Paquetes por país»)
- Test: `backend/tests/Unit/Shipping/ProvidersTest.php`, `backend/tests/Unit/Shipping/DistanceServiceTest.php`,
  `backend/tests/Unit/Shipping/AddressNormalizerTest.php`, y el test existente de `hwe-geo` si fija
  la forma del paquete

**Interfaces:**
- Consume: `Provider`, `DistanceResult`, `GeocodeResult`, `GeoPoint`, `Settings`, `Haversine` (Tarea 3).
- Produce:
  - Clave opcional del paquete de país `geocoding: { abbreviations: {abreviatura: forma completa},
    drop_tokens: string[] }` (el paquete `CO` lleva las del origen: vías abreviadas, «#» y guiones
    entre números, «BIS», cardinales, letras sueltas tras número; `generic` vacía). Expuesta en
    `GET hwe/v1/geo` como el resto del paquete.
  - `AddressNormalizer::forGeocoding(string $raw, array $pack): string`.
  - `DistanceService::between(GeoPoint $origin, GeoPoint $dest): DistanceResult` — **nunca falla**:
    caché → proveedor configurado (si `Settings::method() === 'maps'` y `mapsConfigured()`) →
    respaldo `Haversine::estimate` con `fallback = true` y motivo = código del error; registra el
    respaldo con `error_log('[hwe] shipping: …')`.
  - `DistanceService::geocode(string $query): array|\WP_Error` y
    `DistanceService::reverse(GeoPoint $point): GeocodeResult|\WP_Error` — caché → proveedor de
    mapas (si configurado) → Nominatim; normaliza la consulta con el paquete del país base.
  - Códigos de error (`WP_Error`): `provider_unauthorized`, `provider_rate_limited`,
    `provider_unavailable`, `route_unavailable`, `geocoding_no_results`, `geocoding_backoff`.
  - Filtro `hwe_shipping_distance_providers` (array id → `Provider`) aplicado al construir el mapa
    de proveedores.

- [ ] Tests de proveedores con `wp_remote_*` simulados, uno por caso: éxito; 401/403/PERMISSION_DENIED
      → `provider_unauthorized`; 429/RESOURCE_EXHAUSTED → `provider_rate_limited`; 5xx/timeout →
      `provider_unavailable`; Routes sin `ROUTE_EXISTS` → `route_unavailable`; 200 con cuerpo no
      JSON, distancia ≤ 0 o sin elementos → error (no un resultado con 0); Google elige el resultado
      de mejor granularidad; la clave va en cabecera y **no** aparece en la URL ni en ningún
      `error_log`.
- [ ] Tests de Nominatim: `User-Agent` con la URL del sitio (sin contacto escrito a mano); backoff
      de 30 s (error de red), 2 min (5xx) y 15 min (429) que corta las siguientes llamadas;
      `countrycodes` = país base en minúsculas.
- [ ] Tests de `DistanceService`: segunda llamada idéntica sale de caché (proveedor llamado una vez);
      clave de caché = origen y destino a 4 decimales + proveedor + modo + incremento; TTL normal =
      `cacheTtlHours` y TTL de respaldo = 15 min; proveedor que falla → respaldo marcado;
      `method = straight_line` no llama a HTTP para distancias; geocodificación cae a Nominatim si
      el proveedor de mapas falla; geocodificación (directa por consulta normalizada + proveedor,
      inversa por coordenada) cacheada 7 días y los errores no se cachean.
- [ ] Tests del normalizador con el paquete `CO` (abreviaturas expandidas, complementos tras coma
      y tokens descartados) y con `generic` (solo limpia espacios).
- [ ] Ejecutar, ver fallar, implementar, ver pasar.
- [ ] Commit `feat(shipping): proveedores de mapas, normalizador por país y servicio de distancias`.

### Tarea 5: Herramientas de wp-admin para mapas

**Archivos:**
- Crear: `includes/Admin/MapsAdminApi.php` (rutas REST de wp-admin), `includes/Admin/MapsNotices.php`
- Modificar: asset del editor de la ciudad (botón «Validar»), el bloque «Distancia» del Control
  Center (botón «Probar conexión»: si el Control Center no tiene un tipo de campo adecuado,
  registra uno propio con `hwe_control_center_field_types`)
- Test: `backend/tests/Unit/Shipping/MapsAdminApiTest.php`

**Interfaces:**
- Consume: `DistanceService::geocode`, `Settings`, proveedores (Tarea 4).
- Produce: `POST hwe-shipping/v1/admin/geocode` (`{address}` → `{lat,lng,label,precision,provider}`)
  y `POST hwe-shipping/v1/admin/maps-test` (`{address}` → `{ok, provider, code, message, hint}`,
  con `code` ∈ `ok` | `missing_key` | `unauthorized` | `api_not_enabled` | `rate_limited` |
  `network` | `no_results`), ambas con `manage_woocommerce` + nonce `wp_rest` y `Cache-Control: no-store`.

- [ ] Tests: sin capacidad → 403; «Probar conexión» llama al proveedor **sin** respaldo y clasifica
      cada error con su `hint` accionable en español; sin clave → `missing_key` sin llamada HTTP;
      nunca devuelve la clave ni partes de ella; dirección de prueba obligatoria (sin valor por
      defecto de ningún país).
- [ ] Ejecutar, ver fallar, implementar, ver pasar.
- [ ] «Validar» en el editor rellena lat/lng y la etiqueta resuelta; el editor puede corregirlas.
- [ ] Avisos de admin: `distance_method = maps` sin proveedor/clave válidos (se calculará en línea
      recta); clave guardada que no se puede descifrar. Solo en pantallas de WooCommerce/Control
      Center y para `manage_woocommerce`.
- [ ] Commit `feat(shipping): validar dirección de referencia y probar la conexión de mapas`.

### Tarea 6: Punto de entrega en la sesión (extensión de Store API)

**Archivos:**
- Crear: `includes/DeliveryPoint.php`
- Test: `backend/tests/Unit/Shipping/DeliveryPointTest.php` (amplía `backend/tests/Stubs/wc-store-api.php`
  si hace falta, como hizo `hwe-loyalty`)

**Interfaces:**
- Consume: `GeoPoint`, `ShippingCost::normalizeCity`.
- Produce:
  - Update callback de la Store API, namespace `hwe_shipping`, datos `{ action: 'set_point', lat, lng,
    address_1, city, country }` y `{ action: 'clear_point' }` (vía `cart/extensions`). Guarda en la
    sesión de WooCommerce el punto junto a su huella.
  - `DeliveryPoint::fingerprint(array $destination): string` — de `address_1`, ciudad (normalizada)
    y país; minúsculas, sin tildes, espacios colapsados.
  - `DeliveryPoint::forDestination(array $destination): ?GeoPoint` — el punto de la sesión solo si
    su huella coincide con la del destino.
  - Filtro `woocommerce_cart_shipping_packages`: añade el punto vigente al destino del paquete
    (`destination['hwe_point']`) para que el hash de paquete cambie con el pin.

- [ ] Tests: `set_point` con coordenadas válidas guarda; con NaN, cadenas, fuera de rango o sin
      `address_1` → error de la Store API y no guarda; `forDestination` devuelve null si cambió la
      calle (Foco 1) o la ciudad, y el punto si solo cambian mayúsculas/tildes; `clear_point` borra;
      el paquete lleva `hwe_point` solo si hay punto vigente; sin sesión de Woo no rompe.
- [ ] Ejecutar, ver fallar, implementar, ver pasar.
- [ ] Commit `feat(shipping): punto de entrega del cliente por la Store API`.

### Tarea 7: Método de envío por ciudad y zona del módulo

**Archivos:**
- Crear: `includes/CityRateMethod.php` (extiende `WC_Shipping_Method`, id `hwe_city_rate`),
  `includes/ShippingStatus.php`
- Modificar: `includes/Plugin.php` (registrar el método con `woocommerce_shipping_methods`, quitar
  `applyCityRate`), `includes/ShippingCost.php` (eliminar `applyCityRate`), `includes/ReserveZone.php`
  (`sync`: la zona existe siempre con el módulo activo y lleva `hwe_city_rate` en lugar de
  `flat_rate`; quita el `flat_rate` que creó ella; `reserve_enabled` cambia de descripción y
  significado, §5.3), `backend/tests/Stubs/wc-shipping.php` (lo mínimo de `WC_Shipping_Method`)
- Test: `backend/tests/Unit/Shipping/CityRateMethodTest.php`, `backend/tests/Unit/Shipping/ReserveZoneTest.php`
  (o adapta lo existente en `ShippingTest.php`)

**Interfaces:**
- Consume: `Cities`, `Pricing`, `DistanceService::between`, `DeliveryPoint::forDestination`,
  `Settings`.
- Produce:
  - Tarifas con id `hwe_city_rate:<optionId>` (y `hwe_city_rate:reserve`), etiqueta = nombre de la
    opción (o `reserve_name`), coste de `Pricing` pasado por el filtro
    `hwe_shipping_rate_cost( float $cost, array $context )` (`city`, `option_id`, `type`, `km`).
  - Meta de tarifa (se copia a la línea de envío del pedido). Leídas por el frontend:
    `hwe_option_id`, `hwe_city_type` (`fixed`|`distance`|`reserve`), `hwe_distance_km`,
    `hwe_fallback`. Solo para wp-admin/pedido: proveedor, motivo de respaldo, duración, punto
    (lat/lng) y un enlace a mapa del punto **visible** («Ubicación de entrega»). Comprueba si la
    Store API expone las metas con `_`; si no, las que lee el frontend van sin `_` y las demás se
    ocultan del cliente como convenga (decide y anótalo).
  - `ShippingStatus::forPackage(array $package): array` →
    `{ mode: 'fixed'|'distance'|'reserve'|'none', status: 'ok'|'needs_address'|'needs_location'|'out_of_coverage', distance_km?, duration_min?, fallback? }`
    — misma decisión que el método (compártela, no la dupliques), memoizada por petición.

- [ ] Tests del método: destino de otro país → sin tarifas; ciudad sin registro con
      `reserve_enabled` → una tarifa de reserva, sin él → ninguna (`mode: none`); ciudad fija con
      dos opciones → dos tarifas con sus importes; ciudad por distancia con punto dentro del radio
      → una tarifa por opción con coste de `Pricing` y metas; sin punto → ninguna,
      `needs_location`; fuera del radio → ninguna, `out_of_coverage`; sin calle/ciudad →
      `needs_address`; respaldo marcado en la meta; ciudad del modelo anterior → como sin registro;
      ids de tarifa estables al renombrar una opción (Foco 5); el filtro de coste se aplica.
- [ ] Tests de `ReserveZone::sync`: crea la zona con `hwe_city_rate` aunque `reserve_enabled` esté
      apagado; sustituye el `flat_rate` que había creado; idempotente; no toca otras zonas.
- [ ] Ejecutar, ver fallar, implementar, ver pasar.
- [ ] Commit `feat(shipping): método de envío por ciudad con tarifas fijas y por distancia`.

### Tarea 8: Envío gratis por opción y estado del envío en el carrito

**Archivos:**
- Modificar: `includes/FreeShipping.php` (evalúa por opción; `cityStatus` = la ciudad tiene alguna
  opción con `freeEligible`; aplica 0 solo a las tarifas de opciones marcadas; la extensión publica
  `rate_ids`), `includes/RestApi.php` (`/rates`: cada ciudad con `name` y `type`; `/free-shipping/scope`:
  ciudades desde `Cities::freeEligibleCityNames()`)
- Crear: registro de la extensión `cart.extensions.hwe_shipping` (en `ShippingStatus` o en una
  clase `StoreApiExtension`, como prefieras), con su esquema
- Test: adapta los casos de envío gratis de `ShippingTest.php`; añade `backend/tests/Unit/Shipping/ShippingStatusExtensionTest.php`

**Interfaces:**
- Consume: `Cities`, ids de tarifa de la Tarea 7, `ShippingStatus::forPackage`.
- Produce: `extensions.hwe_free_shipping.rate_ids: string[]` (tarifas que la regla deja a 0) además
  de los campos actuales; `extensions.hwe_shipping` con la forma de `ShippingStatus` para el primer
  paquete; `GET hwe-shipping/v1/rates` → `[{ name, type }]`; la config pública del módulo (por el
  mecanismo que ya use el plugin para `modules`/`hwe_control_center_*`, o un campo calculado del
  grupo `shipping`) expone `shipping.distance_enabled: boolean` = `Cities::hasDistanceCities()`.

- [ ] Tests: regla cumplida en ciudad con «Estándar» marcada y «Express» no → solo Estándar a 0 con
      la meta «Envío gratis: …»; ciudad sin opciones marcadas → `not_eligible`; reserva nunca
      gratis; cupón con envío gratuito sigue dejando todo a 0; `rate_ids` correctos; `/rates` no
      expone importes ni direcciones de referencia; `distance_enabled` refleja las ciudades.
- [ ] Ejecutar, ver fallar, implementar, ver pasar.
- [ ] Commit `feat(shipping): envío gratis por opción y estado del envío en el carrito`.

### Tarea 9: Geocodificación para el BFF (WordPress)

**Archivos:**
- Crear: `includes/Rest/GeocodeApi.php`
- Test: `backend/tests/Unit/Shipping/GeocodeApiTest.php`

**Interfaces:**
- Consume: `DistanceService::geocode` / `::reverse`.
- Produce: `POST hwe-shipping/v1/geocode` (`{ query }` → `{ results: [{ lat, lng, label }] }`, máx. 5)
  y `POST hwe-shipping/v1/reverse-geocode` (`{ lat, lng }` → `{ label }`), solo con
  `X-HWE-Internal-Secret`; errores → `{ code }` con 422 (`no_results`, entrada inválida) o 503
  (`provider_unavailable`, `geocoding_backoff`), sin filtrar mensajes del proveedor.

- [ ] Tests: sin secreto → 401; consulta vacía o > 200 caracteres → 422; coordenadas inválidas →
      422; resultados de caché sin segunda llamada; backoff de Nominatim → 503 `geocoding_backoff`;
      la respuesta no incluye proveedor de pago ni clave.
- [ ] Ejecutar, ver fallar, implementar, ver pasar.
- [ ] Commit `feat(shipping): geocodificación interna para el mapa del BFF`.

### Tarea 10: `lib/` del frontend (tipos, lectores y validación)

**Archivos:**
- Modificar: `frontend/src/types/address.ts` (`SavedAddress.location?: { lat: number; lng: number }`),
  `frontend/src/lib/validation/address.ts` y `frontend/src/lib/validation/store.ts` (esquemas),
  `frontend/src/lib/customer/addresses.ts` (al editar una dirección, la ubicación se conserva solo
  si no cambian `address_1` ni `city`), `frontend/src/lib/shipping/destination.ts`
  (`CartDestination` gana `address_1` y `location?`), `frontend/src/lib/shipping/free-shipping.ts`
  (`rate_ids`), `frontend/src/lib/shipping/types.ts`, `frontend/src/lib/config/types.ts` y
  `defaults.ts` (`shipping.distance_enabled`, por defecto `false`)
- Crear: `frontend/src/lib/shipping/status.ts` (estado y detalle de tarifa)
- Test: colocados junto a cada archivo (`*.test.ts`)

**Interfaces:**
- Produce:
  - `locationSchema` (lat ∈ [-90,90], lng ∈ [-180,180], números finitos; rechaza `0,0`).
  - `ShippingStatus` (TS) con la forma de §8.3 y `readShippingStatus(cart): ShippingStatus | null`
    (tolerante: forma inesperada → null).
  - `readRateDetails(rate): { optionId: string; cityType: 'fixed'|'distance'|'reserve'; distanceKm: number | null; fallback: boolean } | null`.
  - `FreeShippingState.rateIds: string[]`.
  - `mergeAddressLocation(previous: SavedAddress | undefined, next: SavedAddress): SavedAddress`
    (o el nombre que encaje en `addresses.ts`) que aplica la regla de conservar/borrar ubicación.

- [ ] Tests: esquemas aceptan/rechazan los casos del Foco 3; lectores con extensiones ausentes,
      malformadas y completas; regla de la ubicación (cambia la calle → se borra; cambia el
      teléfono → se conserva); `destinationFromAddress` incluye `address_1` y `location`.
- [ ] Ejecutar, ver fallar, implementar, ver pasar. Verificación de frontend completa + `npx next build`.
- [ ] Commit `feat(shipping): tipos y lectores del envío por distancia en lib`.

### Tarea 11: Rutas del BFF

**Archivos:**
- Modificar: `frontend/src/app/api/store/shipping/destination/route.ts` (acepta `address_1` y
  `location`; tras `/cart/update-customer`, fija o borra el punto con `cart/extensions` namespace
  `hwe_shipping`; devuelve el carrito final), `frontend/src/lib/woocommerce/cart-destination.ts`
  (`pushCartDestination`/`syncCartDestination` envían calle y ubicación de la dirección guardada),
  `frontend/src/app/api/store/addresses/route.ts` (acepta y conserva `location` según la regla de la
  Tarea 10), `frontend/src/app/api/store/checkout/route.ts` (rechazo sin tarifa, §9)
- Crear: `frontend/src/app/api/shipping/geocode/route.ts`,
  `frontend/src/app/api/shipping/reverse-geocode/route.ts`, `frontend/src/lib/shipping/geocode.ts`
  (`server-only`, llamadas a las rutas de la Tarea 9 por `wpFetch`)
- Modificar: `frontend/src/i18n/messages/es.json` y `en.json` (errores nuevos)
- Test: `route.test.ts` colocados en cada ruta; `frontend/src/lib/shipping/geocode.test.ts`

**Interfaces:**
- Consume: Tareas 9 y 10.
- Produce:
  - `POST /api/shipping/geocode` (`{ query }` → `{ results }`) y `POST /api/shipping/reverse-geocode`
    (`{ lat, lng }` → `{ label }`): `requireModule` → `guardMutation` con rate-limit
    `shipping_geocode` (10/60 s; por usuario con sesión, por IP sin ella) → sin sesión y con
    `ecommerce.checkout_login_required` encendido → 401.
  - Checkout: si el carrito `needs_shipping` y no tiene tarifa seleccionable, 409
    `{ error, status }` con el `status` de `extensions.hwe_shipping`, **antes** de crear el pedido
    (comprueba primero si la Store API ya lo rechaza; el test cubre el resultado, venga de donde
    venga).

- [ ] Tests: módulo apagado → 404 en las rutas nuevas y en `destination` (ya existe); sin CSRF → 403;
      `location` inválida → 422 sin llamar a WordPress; destino con ubicación llama a
      `update-customer` y a `cart/extensions` con `set_point`, sin ubicación con `clear_point`;
      geocode sin sesión con compra de invitado apagada → 401; rate-limit → 429; checkout sin tarifa
      → 409 y no se crea pedido; la dirección guardada conserva/borra `location` según la regla.
- [ ] Ejecutar, ver fallar, implementar, ver pasar. Verificación de frontend completa + `npx next build`.
- [ ] Commit `feat(shipping): BFF de destino con pin, geocodificación y checkout sin tarifa`.

### Tarea 12: CI, documentación y verificación final

**Archivos:**
- Modificar: `.github/scripts/stub-graphql.mjs` (`/wp-json/hwe-shipping/v1/rates` con una ciudad
  `distance` y las rutas `geocode`/`reverse-geocode` con respuestas fijas), `frontend/tests/e2e/shipping.spec.ts`
  (rutas de geocodificación y 422 de ubicación inválida), `frontend/tests/e2e/modules-off.spec.ts`
  (404 de las rutas nuevas)
- Modificar: `backend/wp-content/plugins/hwe-shipping/README.md` y `MANUAL.md` (modelo por ciudad,
  opciones, distancia, proveedores, pin, avisos), `docs/FRONTEND_CONNECT.md` («Envío»: estado,
  metas de tarifa, pin obligatorio, rutas, guía de mapa: búsqueda al enviar, teselas a elección de
  la instancia), `docs/PLUGIN_DEVELOPMENT.md` (hooks `hwe_shipping_distance_providers` y
  `hwe_shipping_rate_cost`), `docs/CONFIGURATION.md` (Envío → Distancia), `CHANGELOG.md`
  (`Unreleased`: cambio de modelo del módulo `shipping`, sin migración), `AGENTS.md` §6.9 si
  describe el módulo
- Test: e2e del stub; `bash .github/scripts/check-anti-instance.sh`

- [ ] Stub y e2e actualizados; `npm run e2e` contra el build con el stub (como en CI) en verde, y la
      pasada sin módulos también.
- [ ] Documentación completa y en español; ningún resto de la instancia de origen.
- [ ] Verificación completa: `composer test`, la batería de frontend, `npx next build`,
      `check-anti-instance.sh`, `docker compose config` si tocaste compose (no debería).
- [ ] Verificación en vivo (pila híbrida o Docker, `docs/INSTALL.md`): ciudad por distancia con
      cada `distance_method`; «Validar» y «Probar conexión» con una clave real si la hay; carrito
      sin pin (`needs_location`, checkout 409), con pin dentro y fuera de radio, respaldo forzado
      (clave inválida); pedido creado con la línea de envío y sus metas en wp-admin. Lo que no se
      pruebe en vivo se dice en el resumen.
- [ ] Commit `docs(shipping): envío por distancia, contrato y manual`.
