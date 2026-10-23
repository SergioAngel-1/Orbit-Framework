# Changelog

Todas las novedades relevantes de esta plantilla se documentan aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el
versionado sigue [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

### Fixed
- **Presupuesto de memoria de WordPress (OOM bajo carga).** `memory_limit` valía 512M —el
  límite entero del contenedor— y OPcache reservaba 336 MB (256 de caché + 16 de interned + 64
  de JIT), mientras el dimensionado de Apache (`apache-mpm.conf`) daba por sentados ~90 MB de
  OPcache: un pico de WPGraphQL o el prerender podían consumir el cgroup y matar el contenedor
  (`OOMKilled`), dejando el sitio en 500 hasta que Docker lo reiniciaba. Ahora `memory_limit` es
  256M (techo por petición, lo que recomienda WooCommerce), Apache pasa a 5 workers, OPcache
  baja a 96 + 8 MB con el JIT apagado (WordPress es I/O-bound) y el contenedor `wordpress` de
  producción pasa de 512 a 768 MB. El presupuesto (típico 414 MB; peor caso, una petición al
  tope y 4 workers normales, 610 MB) está documentado en `docs/RUNBOOK.md` §5.
  `tests/unit/apache-mpm.test.ts` lee también `opcache.ini`, `uploads.ini` y el límite del
  compose, y comprueba los dos casos: subir uno sin recalcular los demás ahora falla.
- **Sondas de salud que miden lo que dicen medir.** El healthcheck del frontend (dev y prod)
  llamaba a `/api/health`, que devuelve 200 aunque Redis y WordPress estén caídos y que hace dos
  pings de red cada 30 s: ahora usa `/api/health/live` (liveness puro) y la readiness estricta
  (`?ready=1`) sigue en el gate del despliegue. El de WordPress en producción pedía `/wp-json/`
  (>1 MB con WooCommerce y arranque PHP completo cada 30 s) y pasa al endpoint ligero que ya
  usaba desarrollo (`/wp-json/wp/v2/categories?per_page=1`). Caddy estrena sonda propia contra
  su API de administración (loopback).
- **Segmentación de red y ciclo de vida en producción.** La pila compartía una única red plana:
  Caddy podía resolver y alcanzar `db:3306` y `redis:6379`, y cualquier contenedor comprometido
  llegaba a la base de datos y al Redis de revocaciones, rate-limit e idempotencia. Ahora hay tres
  capas (`edge`, `app`, `data`), `data` es **interna** y Caddy no está en ella; WordPress, el
  frontend y wp-cli/wp-cron conservan salida a Internet por `app` (pasarelas, webhooks, SMTP,
  descargas de `setup.sh`). Además: `init: true` y `stop_grace_period` (adiós al SIGKILL con
  peticiones en vuelo), `cpus`/`pids` acotados, Caddy con 256 MB y `GOMEMLIMIT`, `db` con
  `start_period` (el primer `up` ya no falla), y arranque por `service_started` en vez de
  `service_healthy` para que un WordPress lento no impida servir el sitio cacheado.
- **MariaDB, Redis y el cron, con parámetros coherentes.** MariaDB pasa a 50 conexiones
  (`max-connections`) con `max-allowed-packet=64M` (el default de 16 MB rompía un restore con
  filas grandes), `slow-query-log` con `long-query-time=1` (sin él no se ve la consulta que tumba
  el sitio) y `skip-name-resolve`. Redis deja de bloquear escrituras si falla el `bgsave`
  (`stop-writes-on-bgsave-error no`: aquí los datos son caché y estado reconstruible). El bucle de
  `wp-cron` toma un `flock` (una corrida larga ya no se solapa con la siguiente y duplica acciones),
  se acota con `timeout 300` y atiende SIGTERM. Desarrollo estrena la misma rotación de logs que
  producción.
- **La caché de Next sobrevive al despliegue y las imágenes se purgan.** Solo se persistía
  `/app/.next/cache/images`, así que cada `up -d` dejaba el ISR en frío y todo el sitio se
  regeneraba contra WordPress a la vez. Ahora el volumen `next_cache` cubre todo
  `/app/.next/cache` (ISR, fetch-cache e imágenes) y un servicio `cache-prune` borra a diario las
  variantes de imagen sin uso en `CACHE_IMAGES_MAX_AGE_DAYS` (30) días, que `next/image` no
  recolecta nunca. `warm-cache.sh` calienta con 3 peticiones en paralelo (contra los 5 workers de
  WordPress) en vez de una cada segundo, y sigue admitiendo `WARM_CONCURRENCY=1`.

### Security
- **Desarrollo deja de exponer la pila a la red local.** MariaDB (`3307`), Redis (`6379`),
  WordPress (`8080`) y el frontend (`3000`) se publicaban en `0.0.0.0` con las credenciales por
  defecto y Redis sin auth: en una red compartida cualquiera podía entrar a la base de datos o
  hacer `FLUSHALL` del Redis que guarda la blocklist de revocación, el rate-limit y la
  idempotencia del checkout. Ahora los cuatro se publican solo en `127.0.0.1`; el modo híbrido
  (WordPress nativo contra `127.0.0.1:3307` y `npm run dev`) sigue igual. Para probar desde el
  móvil hay que publicar a propósito con un override.
- **`frontend/.dockerignore` excluye todos los `.env*`.** Antes solo excluía `.env` y `.env.local`
  exactos: un `.env.production` —lo natural al preparar un despliegue— entraba en el contexto y se
  horneaba en la imagen, con Next inlinando en `next build` cualquier `NEXT_PUBLIC_*` y dejando el
  resto en las capas del builder. Ahora el patrón es `.env*` con `!.env.example`.
- **Caddy deja de anunciar el stack y acota la superficie pública.** Se borran `Server` y
  `X-Powered-By` del upstream (Apache ya no emite la versión de PHP: `expose_php = Off`,
  `ServerTokens Prod`, `ServerSignature Off`), se limita el cuerpo de la petición a 130 MB (los
  medios necesitan 128), se añaden timeouts de servidor contra conexiones a goteo (slowloris) y un
  `handle_errors` propio sin caché en vez de la página de error de Caddy. Un `.php` dentro de
  `wp-content/uploads` responde **404** en vez de ejecutarse en Apache. Y el año de `Cache-Control`
  queda solo para `uploads/` (inmutable): el resto de `/wp-content` (CSS/JS de temas y plugins)
  pasa a una hora con revalidación, para que un asset actualizado no quede obsoleto un año en los
  navegadores.
- **El core de WordPress ya no queda congelado en el volumen.** El core vive en
  `wordpress_core:/var/www/html` y el entrypoint de la imagen oficial solo lo copia si falta
  `/var/www/html/index.php`: subir la etiqueta (`wordpress:7.1` → `7.2`) **no actualizaba nada**,
  así que los parches de seguridad del core no llegaban por la vía que se creía. Se añade
  `backend/scripts/update-core.sh` (estado + `verify-checksums`, y `--apply <versión>` para
  actualizar), documentado en `docs/RUNBOOK.md` §10 y `docs/UPGRADE.md`, con el reinicio que exige
  `opcache.validate_timestamps=0`. La actualización sigue siendo un paso explícito de operación
  (no automática) para no cambiar de versión de core en un `up -d`.
- **Los límites de GraphQL no se aplicaban.** `graphql-protection.php` enganchaba
  `graphql_query_max_depth` y `graphql_query_complexity`, filtros que WPGraphQL no tiene: `/graphql`
  aceptaba consultas de cualquier profundidad y complejidad (DoS). Ahora el límite de profundidad va
  por `graphql_query_depth_max` (WPGraphQL ≥ 2.24) y el de complejidad sustituye la regla
  `QueryComplexity` de webonyx, que viene desactivada, por `graphql_validation_rules`. Los valores
  siguen siendo 15 y 1000 (`GRAPHQL_MAX_DEPTH` y `GRAPHQL_QUERY_COMPLEXITY_LIMIT` en `wp-config.php`);
  las consultas del frontend del framework caben con margen, pero una instancia con consultas
  propias más profundas debe comprobarlas o subir el límite. El log de consultas rechazadas
  (`[hwe] graphql: …`) tampoco escribía nada por HTTP, porque WPGraphQL le pasa un `ExecutionResult`
  y no un array: ahora registra también los rechazos de la guardia de `external-integration`.
- **Parches de seguridad del 21 de octubre de 2026 (PHP y Node.js).** La imagen de WordPress pasa
  de `wordpress:7.0-php8.4-apache` a `wordpress:7.1-php8.4-apache` (dev y prod): la etiqueta 7.0 ya
  no se reconstruye y se había quedado en PHP 8.4.23, vulnerable a CVE-2026-109618 y CVE-2026-109619
  («PHP2Shell», RCE sin autenticar, explotada activamente); la 7.1 trae PHP 8.4.27. Las imágenes
  `node:24-alpine` ya traen Node 24.22.1 (CVE-2026-111497, RCE en el descompresor Brotli, y nueve
  más); el build del CD pasa a `pull: true` para que la caché de GHA no cuele una base vieja. Tras
  actualizar, `docker compose pull` y `docker compose build --pull frontend` (ver `docs/UPGRADE.md`).
- **WooCommerce 11.2.1 y WPGraphQL 2.25.0.** `setup.sh` fijaba WooCommerce 10.9.4 (SQL injection,
  corregida en 11.0, y CVE-2026-106608, escalada de privilegios de *shop manager* a administrador,
  corregida en 11.2.0) y WPGraphQL 2.23.1 (CVE-2026-109703, lectura sin autenticar de datos
  embebidos, y los GHSA de 2.24.1: SSRF por IPv6, lectura de contenido en la papelera y
  comprobaciones de capacidad en mutaciones). WooGraphQL 1.0.3 se validó contra WooCommerce 11.2.1.
- **Vitest 4.1.12.** `vitest` y `@vitest/coverage-v8` (desarrollo) corrigen GHSA-qxg6-89mg-rqv8 y
  GHSA-xp26-3454-mjgp (`@vitest/mocker`).
- **Vite 8.3.5.** `vite` (dependencia de desarrollo, vía `vitest` y `@vitejs/plugin-react`) pasa de
  8.0.16 a 8.3.5, que corrige GHSA-587h-7wmj-h5pm, GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw,
  GHSA-9jrq-w75r-8gcw y GHSA-rq7h-c2jc-7f22 (`launch-editor` y `server.fs.allow`). No llega a
  producción (`npm audit --omit=dev` ya estaba limpio), pero `npm run audit:gate` vuelve a pasar.
- **Next.js 16.4.1.** Se sube `next` y `eslint-config-next` de 16.4.0 a 16.4.1, que corrige dos RCE
  críticas (ejecución de código en `use cache` con *typed arrays* y en el optimizador de imágenes
  con SVG) y un DoS en Server Actions. `sharp` queda en 0.35.5 en el lock. El parche de cabeceras
  ISR de `scripts/patch-next-isr-headers.mjs` se revalidó contra esta versión (el bucle de Next es
  idéntico): su `SUPPORTED_NEXT` pasa a `16.4.1`.
- **Pagos solo por WhatsApp sin webhook de pruebas abierto.** Con «Activar pago en línea» apagado,
  el panel oculta la pasarela pero el `noop` por defecto seguía guardado y contaba como activo: su
  webhook (firmado con un secreto público) se atendía y permitía marcar pagado —o cancelar— un
  pedido sin pagar. Ahora, sin pago en línea no hay pasarela activa (`lib/payments/registry.ts`):
  el webhook de `noop` responde 404 y el cobro, 503. Los webhooks de pasarelas reales se siguen
  atendiendo (cobros ya iniciados). El panel explica cómo dejar WhatsApp como único medio.

### Changed
- **(BREAKING) Módulo `shipping`: modelo de tarifas por ciudad rediseñado, sin migración.** Cada ciudad
  tiene un único tipo, *fija* o *por distancia*, y sus importes pasan a **opciones de entrega** repetibles
  (id estable, nombre y casilla de envío gratis por opción). La tarifa la produce un método de envío propio
  de WooCommerce (`hwe_city_rate`) en la zona de reserva, en lugar del filtro `applyCityRate` y del
  `flat_rate` que se sustituía. `shipping.reserve_enabled` pasa a significar «enviar también a las ciudades
  sin configurar». Las ciudades del modelo anterior no producen tarifa hasta reconfigurarlas: wp-admin lo
  avisa con la lista. El checkout sin tarifa responde **409** `{ error, status }` (antes **422**) antes de
  crear el pedido; el frontend debe leer el `status` de `extensions.hwe_shipping`. Versión del plugin 2.0.0.
- **Imagen de producción del frontend: trazabilidad y paridad de runtime.** El stage `prod` lleva
  etiquetas OCI (`source`/`revision` opcionales por build-arg), instala `libc6-compat` como el
  stage base (sharp es nativo) y aplica los parches de Alpine del momento del build con
  `apk upgrade` —el `pull` del tag de Node no los trae—. `public/` se copia con `--chown`, así que
  no queda de root. `docs/GO-LIVE.md` documenta además el endurecimiento que queda pendiente
  (capabilities de WordPress/MariaDB, `read_only` en frontend/Caddy, `wp-content` fuera del
  checkout), los permisos de `.env.prod`, la actualización del core y el cifrado/verificación de
  las copias.
- **Imágenes con el minor fijado.** `node:24-alpine` y `redis:8-alpine` eran *majors*
  flotantes: dos builds del mismo commit podían usar Node o Redis distintos y una regresión de
  runtime entraba en producción sin pasar por CI. Ahora son `node:24.22-alpine` y
  `redis:8.10-alpine` (MariaDB, Caddy y WordPress ya fijaban minor), y el compose de producción
  declara `pull_policy: if_not_present` para que un `up -d` no cambie la base a mitad de un
  despliegue. `docs/COMPATIBILITY.md` documenta la política y cómo fijar por digest si se quiere
  reproducibilidad total.
- **CI: menos minutos de runner y permisos mínimos.** El job `frontend` ejecutaba la suite dos
  veces (`npm run test` y `npm run test:coverage`, que ya corre los tests): ahora solo la segunda,
  con umbrales incluidos. Los navegadores de Playwright se cachean entre ejecuciones del job `e2e`
  (cientos de MB por PR) y el workflow declara `permissions: contents: read` con
  `timeout-minutes` por job, para que un cuelgue no consuma horas de runner y el `GITHUB_TOKEN`
  no nazca con escritura.
- **La suite PHPUnit es ~4× más rápida.** `composer test` cachea en disco lo que Patchwork reescribe
  y los opcodes de PHP (`opcache.file_cache`), en `backend/.phpunit.cache/` (ignorado por git): los
  809 tests que corren con `#[RunTestsInSeparateProcesses]` dejan de recompilar lo mismo en cada
  proceso. Medido en la misma máquina: de 309 s a 75 s en frío y 72 s en caliente (83/80 s con la
  máquina cargada), con los mismos 1.233 tests y 5.660 aserciones. El comando no cambia y las cachés
  se invalidan solas cuando cambia el fuente o `patchwork.json`; se pueden borrar en cualquier momento.
- **La cobertura incluye `hwe-loyalty`.** Faltaba en `<source>` de `phpunit.xml.dist` (solo estaban
  9 de los 10 módulos), así que cualquier informe con `--coverage-*` omitía en silencio el módulo
  con más código (265 tests en 16 clases).
- **`brand.url` tiene predeterminado.** Vacío en el Control Center, el frontend usa su URL pública
  (`NEXT_PUBLIC_SITE_URL`) y lo registra (`site_config.brand_url_default`): las canónicas, el
  JSON-LD, el sitemap y el enlace de referido ya no salen relativos en una instancia sin configurar.
- **Control Center: sedes, pagos y GEO más claros.** Las sedes de Legal son opcionales y pasan de
  4 a 5 (`legal.locations.location_5`, nuevo en `SiteConfig` y `CONFIG_DEFAULTS`): el panel solo
  enseña las que tienen datos, con «Añadir sede» y «Quitar sede». En Pasarelas de pago solo se ven
  las credenciales de la pasarela activa, y ni la pasarela ni sus credenciales si «Activar pago en
  línea» está apagado. La pestaña «SEO & GEO» pasa a «SEO» y las señales de entidad
  (`organization_logo`, `founding_date`, `knows_about`, `alternate_names`, `founder_*`) se editan
  en «GEO (IA)»; sus rutas siguen en `seo.*`, así que la API y el frontend no cambian. El esquema
  gana dos atributos solo de panel: `panel` (pintar un campo en otra pestaña) y `slots` (huecos
  opcionales).
- **Rebrand «HWE Orbit» y nueva paleta por defecto.** El framework pasa a presentarse como **HWE
  Orbit** (HWE = Headless Web Ecosystem) en el README, las cabeceras de plugins y las descripciones
  de los paquetes, y el tema mínimo describe la nueva identidad. La paleta por defecto del Control
  Center, los tokens de `globals.css`, los ejemplos de configuración y el panel pasan a tinta,
  cadmio y ultramar (`brand` `#111111`, `brand_dark` `#000000`, `brand_light` `#3a3a3a`,
  `secondary` `#2a3fd6`, `secondary_dark` `#1f2fa3`, `accent` `#f2c200`, `surface` `#e9e9e6`);
  los colores semánticos de estado no cambian. El nombre por defecto del sitio pasa a
  «HWE-Orbit» (`WP_TITLE`, `brand.name` y la razón social de ejemplo), en lugar de
  «Headless Web Ecosystem» y «HeadlessWP».
- **Lema y tema.** El lema por defecto pasa a «Framework de e-commerce headless» («Headless
  e-commerce framework» en inglés) en lugar de presentar el producto como plantilla, y el tema
  `hwe-headless-base` se muestra como «HWE Orbit» con una descripción nueva (la carpeta y el slug
  no cambian).
- **Autoría:** el autor de los plugins y mu-plugins pasa a «Sergio Jauregui» (cabecera `Author:`),
  el del tema `hwe-headless-base` a «sergioja.com», y el titular en `LICENSE`, `EULA.md`,
  `package.json` y `composer.json` a «Sergio Jauregui».

### Added
- **Envío por distancia en el módulo `shipping`.** Ciudades por distancia con dirección de referencia (con
  «Validar»), radio de cobertura y precio por km con mínimo y máximo, calculado en WooCommerce con
  `Pricing` (una sola implementación). Proveedores intercambiables: Google (Routes y Geocoding v4),
  OpenRouteService y línea recta con Nominatim, que es además el respaldo automático y se marca como tal.
  Caché de distancias y geocodificaciones en transients; backoff de Nominatim. El pin del cliente llega
  por la Store API (`hwe_shipping`), se guarda en la sesión ligado a la calle y WordPress lo valida; sin
  pin no hay tarifa (`needs_location`). Estado del envío en `cart.extensions.hwe_shipping`, `rate_ids` del
  envío gratis, metas de tarifa y de línea de pedido, y avisos de wp-admin.
- **Integración genérica de mapas en Control Center → Integraciones.** `maps_provider` (`none`,
  `google_maps`, `openrouteservice`) y `maps_api_key` (cifrada). Solo la usa WordPress; no sale por la
  configuración pública ni por el BFF.
- **BFF del mapa del checkout.** `PUT /api/store/shipping/destination` acepta `address_1` y `location`
  (con validación de coordenadas: 422 antes de llegar a WordPress). Nuevas rutas `POST
  /api/shipping/geocode` y `POST /api/shipping/reverse-geocode`, con `requireModule`, `guardMutation` y
  límite propio de 10 por minuto. La libreta de direcciones guarda la ubicación y la borra si cambia la
  calle o la ciudad.
- **Filtros estables del módulo:** `hwe_shipping_distance_providers` y `hwe_shipping_rate_cost`, además
  del filtro núcleo `hwe_control_center_public_config`, con el que el módulo publica `shipping.distance_enabled`.
- **Módulo `loyalty` (fidelización).** Monedero de puntos y referidos de dos niveles, sin UI: el
  plugin `hwe-loyalty` es la fuente de verdad (libro mayor transaccional con consumo FIFO y
  caducidad, ganancias por pedido y por eventos, canje en la Store API con fee negativo y
  confirmación contra el libro, transferencias atómicas, administración en wp-admin y
  privacidad). El BFF expone `/api/loyalty/{summary,transactions,referrals,referral-link,
  validate-code,transfer}` y `/api/store/cart/points`, con `paid_with_points` en el checkout y la
  cookie `hwe_ref` para el registro. Ajustes en el Control Center → Fidelización (grupo reservado
  `loyalty`), hooks estables `hwe_loyalty_*` y cobertura en CI: stub con el módulo encendido,
  pasada e2e sin módulos y `verify-modules.sh`.
- **Marca de HWE Orbit en el panel** (mu-plugin `hwe-admin-branding`). Esquema de color «HWE Orbit»
  (tinta, cadmio y ultramar), compilado con el `_admin.scss` del core y aplicado por defecto a quien
  tenga el esquema de WordPress (`fresh`), más un login con el símbolo de Orbit. El tema
  `hwe-headless-base` estrena su `screenshot.png`. Una instancia lo apaga con el filtro
  `hwe_admin_branding`.

## [2.0.0] — 2026-10-14

Salto mayor con cambios incompatibles. **Antes de actualizar una instancia de v1, sigue la
guía de [`docs/UPGRADE.md`](docs/UPGRADE.md) §5** (qué cambia, por qué y pasos, por cada cambio
incompatible); el registro completo está en §6.

### Added
- **Contrato de instancia.** El código de una instancia vive en rutas que el framework nunca toca
  (`backend/wp-content/mu-plugins/instance/`, plugins `hwe-instance-*` y `frontend/src/instance/`).
  Hooks estables (`hwe_control_center_schema`, `hwe_control_center_field_types`,
  `hwe_control_center_saved`, `hwe_revalidation_tags`, `hwe_geo_packs`…), `InstanceRegistry` solo
  de datos, grupo `instance` del Control Center, `HWE_CORE_CONTRACT_VERSION`, regla de ESLint de
  frontera y **puerta anti-instancia** en CI. Ejemplo vivo en `examples/instance-minimal/`, que el
  job «contrato» superpone al repo.
- **Nueve módulos opcionales** (`banners`, `shipping`, `product-landing`, `landings`,
  `consent-log`, `site-reviews`, `coupon-rules`, `customer-identity`, `external-integration`):
  plugins `hwe-<id>` con `README.md` y `MANUAL.md`, que se encienden con `modules` en
  `instance.config.json`. Apagados, su lector devuelve vacío, sus rutas del BFF responden 404
  (`requireModule`) y su UI no se pinta.
- **Contenido a N idiomas** (`hwe-content-i18n`): traducción de entradas, productos y términos,
  slug por idioma con 308 a la URL canónica, `translation(locale)` en GraphQL e `i18n.locales` en
  el Control Center.
- **Paquetes por país** (`hwe-geo`: `CO` y `generic`): división, ciudades, tipos de documento y
  prefijo telefónico; el checkout y la libreta de direcciones validan contra el paquete.
- **Mecanismos declarativos:** campos de producto (`hwe_product_fields`) y detalles de línea de
  pedido (`hwe_order_item_details`).
- **HWE Control Center:** tipos de campo extensibles, campos traducibles, pasarelas y credenciales
  por canal interno, backups programables desde el panel (con «Backup ahora» y descarga),
  exportación/importación de la configuración, SMTP y registro de errores.
- **Pagos:** Wompi y Aval Pay de punta a punta (firma, importe y moneda verificados en el webhook),
  medios de pago configurables (en línea, WhatsApp, efectivo) y compra de invitado con cookie de
  pedido (`hwe_guest_order`).
- **Cuenta y tienda:** reseñas de producto solo de compradores, reglas de cupón y «Cupones
  disponibles», identidad del cliente (documento y términos), envío por ciudad con extras y envío
  gratis por regla, landings editoriales y de producto, y blog con categorías, archivo y scroll
  infinito.
- **SEO y GEO completos:** metadatos y JSON-LD por vista, sitemap segmentado, `robots.txt` y
  `llms.txt` por idioma, y `noindex` en las vistas privadas.
- **Analítica y consentimiento:** GA4, GTM, Plausible, Meta (con Conversions API desde el servidor),
  TikTok y HubSpot desde `config.integrations`, con opt-in; registro del consentimiento como
  prueba (módulo `consent-log`).
- **Operación:** servicio `wp-cron`, Caddy sirve los medios, `warm-cache.sh`, CD con la CI en verde y
  copia previa de la base de datos, y **verificación en vivo** (`verify-security.sh`,
  `verify-modules.sh`, `verify-integration.sh`).
- **Tooling:** PHPUnit + Brain Monkey (`composer test`), tests de frontend colocados con `jsdom`,
  `npm run i18n:check`, `npm run audit:gate`, stub de WordPress para CI y e2e (smoke, axe, módulos y
  una segunda pasada sin módulos), umbrales de cobertura de trinquete y reglas de accesibilidad.
- **Documentación:** `docs/UPGRADE.md` (guía v1 → v2), y revisión de `CONFIGURATION`, `SECURITY`,
  `RUNBOOK`, `COMPATIBILITY`, `CREATE_INSTANCE`, `FRONTEND_CONNECT`, `FRONTEND_BUILD`,
  `PLUGIN_DEVELOPMENT`, `README.md`, `AGENTS.md` y `CLAUDE.md`.

### Changed
- **(BREAKING)** Código de instancia → `mu-plugins/instance/` y `src/instance/`; las acciones con
  nombre de instancia pasan a `hwe_control_center_saved`.
- **(BREAKING)** GraphQL: los campos `*En` desaparecen en favor de `translation(locale)`;
  `hweSlugFromEn` pasa a `hweSlugFrom(postType, locale, slug)`; `pickLocalized` recibe la
  entidad y el campo.
- **(BREAKING)** `formatPrice` y `formatStoreAmount` exigen moneda y locale; no hay moneda fijada en
  el código.
- **(BREAKING)** WordPress solo atiende al BFF: GraphQL, Store API y `hwe/v1/auth|consent` responden
  404 sin `X-HWE-Internal-Secret`. Se elimina `NEXT_PUBLIC_WORDPRESS_API_URL`.
- **(BREAKING)** Funcionalidad que pasa a módulo (identidad del cliente, envío, acceso de un
  integrador, landings…); `banners` adopta el modelo tipo × locación y `mandala_presets` pasa a
  `decor_presets`; los campos de producto pasan a `Product.hweFields(locale)`.
- **(BREAKING)** Despliegue: `CSRF_SECRET`, `HWE_SECRETS_KEY` y `HWE_2FA_KEY` obligatorias y sin
  clave de reserva; `ALLOWED_ORIGIN` exacta; el `Dockerfile` del frontend exige el build-arg
  `WORDPRESS_PUBLIC_URL`; la base de datos de producción es un volumen externo (`HWE_DB_VOLUME`).
- Dependencias: Next 16.4.0 (versión exacta, con parche de cabeceras ISR), React 19.3.0, WPGraphQL
  2.17.0 y el resto de la matriz de `docs/COMPATIBILITY.md`.
- Las vistas y componentes heredados pasan a ser un starter neutro (clases de marca, textos en
  `es.json` y `en.json`, cada funcionalidad gateada por su flag o módulo).
- `banners.enabled` es el único campo del grupo `banners` del Control Center; la autoría vive en el
  plugin.

### Removed
- `NEXT_PUBLIC_WORDPRESS_API_URL` y la rama de navegador del cliente GraphQL.
- Los mu-plugins de traducción de v1 (`hwe-content-translation`, `hwe-slug-translation`,
  `hwe-category-translation`, `hwe-tag-translation`, `hwe-blog-category-translation`; eran de
  instancias derivadas, el núcleo v1 no los incluía), sustituidos por `hwe-content-i18n`.
- `woocommerce-email-branding.php` (lo sustituye `hwe-email-chrome` con las plantillas del tema
  base), el parser de banners del núcleo (`lib/config/banners.ts`), `app/sitemap.ts`,
  `BackupConfig::triggerNow()` y la carga del QR de 2FA desde un tercero.

### Security
- Login con 2FA sin tokens antes del segundo factor (id opaco de un solo uso en Redis; sin Redis,
  falla cerrado), alta de 2FA con el secreto en el servidor y QR propio, y activar o desactivar
  exige prueba de posesión (TOTP o código de recuperación).
- `logout-all` y el cambio de contraseña revocan de verdad los refresh tokens; los clientes pueden
  cambiar su contraseña.
- Sin claves de cifrado de reserva (secretos del Control Center y TOTP); `CSRF_SECRET` sin reserva;
  guard de arranque ampliado.
- Origin/CSRF con lista cerrada de rutas firmadas; HMAC único con anti-replay; `returnUrl` y
  `?return=` solo del propio sitio; idempotencia sin fallo abierto; rate-limit en lecturas y
  `wp-login.php`.
- Reseñas con la identidad del perfil, validación con Zod, saneado de HTML con `rel` seguro y tokens
  de diseño validados por tipo; SVG subidos saneados.
- `GET /api/store/products?include=` ya no expone productos sin publicar; la introspección de
  GraphQL falla cerrada; `WP_ADMIN_PASSWORD` obligatoria al instalar.
- Backups reales (antes las copias automáticas de producción no funcionaban), contraseña de MySQL
  fuera de la línea de comandos, `setup.sh` verifica el SHA-256 de lo que descarga y
  `wp cache flush` ya no borra el Redis del frontend.

### Fixed
- **Pagos:** Wompi concilia la transacción con su API antes de aplicar un evento (la `reference`
  no va firmada por Wompi); el pago en línea nunca cae a efectivo ni a WhatsApp si Woo no ofrece
  un medio de cobro (422); el `metadata` del cobro ya no lleva el id bajo `order_key`.
- **Catálogo:** el listado de la tienda (búsqueda, filtros y orden) usa los tipos de WooGraphQL ≥ 1.0
  (`StockStatusEnum`, `ProductsOrderByEnum`, precios `Float`); con el WooGraphQL fijado respondía
  «Unknown type» y la tienda no cargaba. Ordenar por nombre usa `NAME` (el enum no tiene `TITLE`).
- **Catálogo/i18n:** un producto inexistente es `null` y no un error de GraphQL (WooGraphQL lanza
  un `UserError`), con lo que `/products/<slug-de-otro-idioma>` llega al 308 y un slug desconocido
  da 404; la ruta de categorías se hornea (ISR) y devuelve 404/308 reales; el idioma por defecto
  se compara normalizado en `translation.ts`.
- **Control Center:** una lista en un campo de texto (`i18n.locales: ["en", "fr"]`) se une con
  comas en vez de guardarse como «Array».
- **Seguridad:** todos los endpoints de escritura de `api/auth` limitan con `strict: true`
  (`change-password`, `verify-email`, `resend-verification`, `refresh`, `logout` no lo llevaban).
- **Infra:** la salida de las copias programadas va a `docker logs hwe_backup`;
  `BACKUP_HOUR_UTC` y `HWE_BACKUP_LOCK_MAX_AGE_MIN` llegan al contenedor; `backup.sh` toma los
  uploads del contenedor cuando no están en el host; CI valida el Caddyfile con Caddy 2.11 (la
  de producción); `wpcli` lleva `no-new-privileges`.
- **Frontend:** las preguntas de pagos (`geo.faq_payments`) se muestran en una segunda columna de
  la FAQ; las redes sociales del pie se resuelven a URL (antes un usuario enlazaba en relativo);
  el webhook de pago emite sus logs con el `requestId`. Se retiran exports sin uso
  (`fetchGraphQLAsViewer`, `validateForm`, `isOfferProduct`, `buildServiceJsonLd`…).
- **CI:** el job e2e define `WC_API_URL`/`WC_STORE_API_URL` contra el stub (la cuenta salía vacía y
  5 tests fallaban); Dependabot solo propone actualizaciones de seguridad.
- **Dependencias:** `postcss-selector-parser ^7.1.6` forzado en `@tailwindcss/typography`
  (GHSA-rj75-hqrm-r3gf).
- **Tests y docs:** `purchase.spec.ts` manda dirección de envío (la compra completa `E2E_FULL=1`
  pasa en vivo contra la pila de desarrollo); nuevo e2e de status HTTP de las fichas; guías
  corregidas (`--entrypoint wp wpcli`, `i18n.locales`, `COMPOSE_FILE`, mu-plugins de traducción,
  idempotencia, versión de cabecera por componente) y `NEXT_PUBLIC_SITE_NAME` retirada.

### Plugins de WordPress fijados
WPGraphQL **2.23.1** (incluye la corrección de 2.22.2), WooCommerce **10.9.4** y WooGraphQL
**1.0.3**, validados en un WordPress desechable (esquema, consultas del frontend y mensaje de
producto inexistente). Nuevo `backend/scripts/check-versions.sh` compara los plugins fijados con
la última release. WooCommerce 11 y Redis Object Cache 3 quedan fuera: ver
`docs/COMPATIBILITY.md`.

### Verificación y alcance
Verificado: lint, tipos, formato, paridad i18n, tests de frontend y de PHP, puerta anti-instancia y
`next build`; el e2e contra el stub (con módulos encendidos y la pasada sin módulos), también en el
CI del servidor; la compra completa (`E2E_FULL=1`) contra la pila de desarrollo;
`verify-modules.sh toggle` (9 módulos), `verify-security.sh` (también `--strict`) e integración
externa contra la pila en vivo; `examples/minimal-consumer`; el job «contrato»; y
`docker build --target prod`. Una auditoría independiente repasó el código y la pila; sus hallazgos
están corregidos arriba.

**No verificado en esta versión:**
- los webhooks de Wompi y Aval Pay contra el sandbox de cada pasarela (se prueban con firmas y una
  API simulada): se validan con las credenciales de cada cliente antes de cobrar;
- la actualización de una instancia real de v1 siguiendo `docs/UPGRADE.md` (se ensayó en una copia
  desechable, no en una instancia con datos);
- el arranque en producción tras Caddy con dominio real, el SMTP real y el CD con despliegue;
- los plugins actualizados con los mu-plugins propios en una pila real (validados sin ellos).

## [1.0.0] — 2026-07-14

### Añadido
- **Logo de marca** administrable desde HWE Control Center (`brand.logo`), consumido por el
  header heredado y expuesto en `/wp-json/hwe/v1/config`.
- **Menús gestionables desde WordPress**: locations `primary`/`footer` (+ variantes `_en`) en el
  tema headless, siembra de menú por defecto en `setup.sh`, helper de núcleo
  `lib/navigation/menu.ts` (`getMenu`) y consumo de referencia en header/footer heredados con
  fallback local. Contrato documentado en `docs/FRONTEND_CONNECT.md §A.6`.
- **Errores tipados** en el cliente GraphQL (`GraphQLClientError`: network/http/graphql/empty).
- **Guía de desarrollo de plugins** (`docs/PLUGIN_DEVELOPMENT.md`).
- **Ejemplo mínimo de integración** (`examples/minimal-consumer/`): consumidor Node del
  contrato (config pública + GraphQL + BFF) sin framework.
- **Banner Manager**: banners de portada administrables desde HWE Config (sección Banners),
  expuestos en la config pública, con parser en el núcleo (`parseBanners`) y el carrusel
  heredado (`HeroCarousel`) conectado como implementación de referencia.
- **Frontera núcleo/instancia ejecutable** (AGENTS.md §1.1): regla de lint que prohíbe que
  `lib/`, `app/api/` y `proxy.ts` importen de `components/**` (salvo `ui/`).
- **`docs/UPGRADE.md`**: procedimiento de actualización de instancias (remote `framework` +
  merge de tags de release), enlazado desde `README.md` y `docs/CREATE_INSTANCE.md`.

## [0.9.0] — 2026-07-14

### Corregido
- **ESLint funcional de nuevo**: `eslint.config.mjs` importa los flat configs nativos de
  `eslint-config-next` 16 (cargarlos vía FlatCompat rompía el validador eslintrc) y `eslint`
  baja a `^9` (rango soportado por los plugins que arrastra Next). Se retira `@eslint/eslintrc`.
- **Formato Prettier aplicado** a todo `src/` y `tests/` (122 archivos): `format:check` llevaba
  tiempo en rojo, invisible porque el lint estaba roto.

### Cambiado
- Se retiran `husky` y `lint-staged` (hook de pre-commit eliminado); la calidad la impone la CI.
- `CLAUDE.md` y el plan de implementación v1.0 (`docs/superpowers/plans/`) quedan versionados.

### Decisión de arquitectura: el framework es backend + BFF, no un generador de UI

#### Cambiado
- **Alcance del framework redefinido**: el núcleo reutilizable es WordPress headless
  (`backend/`) + el BFF de Next.js (`frontend/src/app/api/*`, `frontend/src/lib/*`) +
  `frontend/src/components/ui/*` (primitivas sin negocio). Todo lo demás del frontend
  (`components/**` salvo `ui/`, y las vistas de `app/[locale]/*`) pasa a considerarse código
  **heredado al clonar**, responsabilidad de cada instancia — el framework deja de prometer
  que se mantenga genérico o se actualice.
- **Documentación actualizada** para reflejar el límite: `AGENTS.md` (nueva §1.1), `README.md`,
  `docs/CREATE_INSTANCE.md` (§7 reescrita), `docs/FRONTEND_CONNECT.md` (reestructurado en
  Parte A — contrato backend/BFF estable — y Parte B — inventario de lo heredado, no
  mantenido), `docs/FRONTEND_BUILD.md`.

### Modo de desarrollo híbrido (WordPress + frontend nativos, DB/Redis en Docker)

#### Añadido
- **Documentación actualizada** para reflejar dos modos de desarrollo: Docker puro y
  híbrido (recomendado en Windows para evitar latencia de bind mounts).
- **Scripts de inicio local**: `start-local.bat` / `start-local.ps1` que establecen
  las variables de entorno necesarias para WordPress nativo y lo arrancan con
  `php -S`.
- **Puerto MariaDB 3307 expuesto** al host para que WordPress nativo pueda
  conectarse al contenedor Docker.

#### Cambiado
- `README.md`, `AGENTS.md`, `docs/INSTALL.md`, `docs/CONFIGURATION.md`:
  documentación actualizada con los dos modos de desarrollo.
- `docker-compose.yml`: puerto 3307:3306 para MariaDB, volumen wordpress_core
  compartido entre WordPress y wpcli, healthcheck optimizado.

### Auditoría y endurecimiento (remediación del plan `AUDITORIA-Y-PLAN-DE-IMPLEMENTACION.md`)

#### Corregido (flujos rotos — P0)
- **Checkout creaba pedidos de invitado** (`customer_id = 0`), lo que impedía al comprador
  pagar/ver su pedido y vaciaba el historial. Ahora el checkout reenvía el JWT a la Store API
  y, como salvaguarda idempotente, `ensureOrderOwner()` fija `customer_id` vía wc/v3.
- **CI E2E** arrancaba `next start` sin `next build` (falso verde): se añade el build previo.
- **Webhook `order.updated`** con lógica de "estado anterior" muerta: transición real vía
  Redis (`recordAndDiffStatus`) + dispatcher de efectos (`order-events.ts`).

#### Añadido (flujos incompletos — P1)
- **Verificación de email** (opcional): envío tras registro + expiración de token (24 h).
- **2FA endurecido**: secreto TOTP cifrado en reposo (AES-256-GCM), **códigos de recuperación**
  de un solo uso, `2fa-status` no público (secreto interno del BFF) y re-verificación al desactivar.
- **Analítica** real con consentimiento (Plausible/GA4) + eventos `add_to_cart` y `purchase`.
- **Meta de cliente atómica**: wishlist/direcciones serializadas con lock Redis (`lib/security/lock.ts`).

#### Seguridad (endurecimiento — P1/P2)
- **Guard de arranque** que aborta en producción con secretos por defecto/cortos (`secret-guard.ts`).
- **Revocación de sesión**: blocklist del access token en `logout`; `/api/auth/logout-all` rota
  el secreto JWT del usuario en WP.
- **SecretsStorage** del Control Center migrado a AES-256-GCM con clave dedicada `HWE_SECRETS_KEY`.
- **Rate-limit durable** en auth (fallback en memoria si Redis cae).
- **Anti-replay + rate-limit** en webhooks de pago y de WooCommerce (`lib/security/replay.ts`).
- **Anti-spoofing de `X-Forwarded-For`** (Next y WP) vía nº de proxies de confianza.

#### Infraestructura (P2)
- **Restauración probada**: `backend/scripts/restore.sh` + `docs/RUNBOOK.md`.
- **Caddy**: `/graphql` público restringido a POST/OPTIONS.
- **Hardening**: `security_opt: no-new-privileges` en todos los servicios de producción.
- **Sondas**: `/api/health/live` (liveness) y readiness estricta `GET /api/health?ready=1`.

#### Tests (P1)
- Unit (Vitest) de `origin`, `csrf`, `jwt`, `rate-limit`, `idempotency`, `lock`, `replay`,
  `secret-guard`, `request-ip`, `order-events`.
- E2E de **compra completa** (`tests/e2e/purchase.spec.ts`, opt-in `E2E_FULL=1`): regresión del fix de pedido-invitado.

#### Documentación (P1)
- `docs/RUNBOOK.md`, `docs/GO-LIVE.md`, `docs/COMPATIBILITY.md`; `AGENTS.md` actualizado al
  estado real (mapa del repo, flujos 6.4/6.5/6.6.1, gotchas, doc map §11).
- `.env.example` documenta las nuevas variables (analítica, 2FA, proxies, secretos, notificaciones).

#### Diferido a propósito
- **CSP con nonce** (rompería ISR/SSG) y **pasarela de pago real** (requiere credenciales y
  certificación). Ver el plan para el detalle.

### Añadido
- **Fase 8 — Calidad, CI/CD y observabilidad:**
  - Tests unitarios con **Vitest** (`tests/unit/`) para `lib/` (formato, firmas de
    pago, máquina de estados del pedido, validación, proveedor `noop`).
  - Scaffolding **Playwright** (`tests/e2e/`) con smoke tests (i18n, SEO, sonda de
    salud, barrera del BFF).
  - **CI** (`.github/workflows/ci.yml`): lint · type-check · formato · tests ·
    build + **secret scanning** (gitleaks).
  - **Prettier** + **lint-staged** + **husky** (hook pre-commit).
  - Sonda de salud `GET /api/health` (liveness/readiness con estado de Redis y WP)
    y `healthcheck` del servicio `frontend` en `docker-compose.yml`.
  - **Logging estructurado** con pino (`lib/observability/logger.ts`) e
    `instrumentation.ts` (captura de errores; punto de integración de Sentry).
  - **Dockerfile de producción** multi-stage (Next `output: "standalone"`, usuario
    no-root, healthcheck) y `target: dev` para el compose de desarrollo.
  - Script de **backup** de BD + uploads (`backend/scripts/backup.sh`).
- **Fase 9 — Empaquetado comercial:**
  - `LICENSE` (comercial) + `EULA.md`.
  - Documentación de cliente en `docs/`: `INSTALL`, `CONFIGURATION`,
    `CUSTOMIZATION`, `DEPLOYMENT`, `SECURITY`.
  - Páginas legales (privacidad, cookies, términos, devoluciones) localizadas
    bajo `/[locale]/legal/[slug]`, con enlaces en el pie.
  - White-label: marca del header desde `config/site.ts` / i18n; enlace "saltar al
    contenido" y mejoras de accesibilidad base.
  - Plantillas de issues y pull request (`.github/`).
  - Script de **datos demo** (`backend/scripts/seed-demo.sh`).

### Cambiado
- `next.config.mjs`: `output: "standalone"` para la imagen de producción.

## [1.0.0] — Base funcional (Fases 1–7)
- Fase 1 — Endurecimiento de seguridad base (cabeceras/CSP, CORS, hardening, Redis).
- Fase 2 — Autenticación JWT (cookies httpOnly + refresh transparente).
- Fase 3 — Proxy inverso a WooCommerce (BFF, `ck/cs` server-only, anti-IDOR).
- Fase 4 — CSRF + rate-limit + idempotencia.
- Fase 5 — E-commerce (catálogo ISR, carrito, checkout, cuenta, revalidación).
- Fase 6 — SEO + i18n (next-intl, sitemap, hreflang, JSON-LD).
- Fase 7 — Pagos: capa de pasarelas agnóstica (provider-agnostic) + proveedor `noop`.

[Sin publicar]: https://example.com/compare/v1.0.0...HEAD
[1.0.0]: https://example.com/releases/v1.0.0
