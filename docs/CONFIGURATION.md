# Configuración — Variables de entorno y panel de control

Hay **dos niveles** de configuración, y conviene no mezclarlos:

| Nivel | Dónde | Qué contiene | Quién la cambia |
|-------|-------|--------------|-----------------|
| **Entorno** | `.env` (raíz, la consume Docker Compose) y `frontend/.env.local` (fuera de Docker) | Secretos, URLs de infraestructura, ajustes de despliegue | Quien despliega |
| **Negocio** | HWE Control Center (`wp-admin → HWE Config`), sembrable con `wp hwe setup` | Marca, diseño, idiomas, país, pasarela, integraciones, SEO, backups, módulos | El negocio |

Este documento cubre ambos. Plantillas: `.env.example` (desarrollo), `.env.prod.example`
(producción) y `frontend/.env.example` (frontend nativo). **El repositorio solo contiene las
plantillas; nunca subas claves reales.**

> **Regla de oro:** todo lo secreto va **sin** el prefijo `NEXT_PUBLIC_`. Solo las variables
> `NEXT_PUBLIC_*` se incluyen en el bundle del navegador.

> **En producción el arranque aborta** (`lib/security/secret-guard.ts`) si
> `GRAPHQL_JWT_AUTH_SECRET_KEY`, `CSRF_SECRET`, `WC_WEBHOOK_SECRET` o `HWE_REVALIDATION_SECRET`
> siguen con un valor por defecto o miden menos de 24 caracteres, o si `ALLOWED_ORIGIN` no es
> exactamente el `Origin` del navegador. Genera los secretos con `sh backend/scripts/generate-secrets.sh`.

> **Modo híbrido (WordPress nativo):** WordPress toma sus variables de base de datos de
> `WORDPRESS_DB_*` del entorno. MariaDB se expone en `localhost:${DB_PORT:-3307}` (no 3306) para no
> chocar con un MySQL local, y Redis en `localhost:${REDIS_PORT:-6379}`; con varias pilas en la
> misma máquina cada una publica los suyos (`docs/CREATE_INSTANCE.md` §2). Con WordPress nativo hay
> que definir `HWE_REVALIDATION_SECRET` en su `wp-config.php` (ver `README.md`); en Docker lo hace
> `docker-compose*.yml`.

---

## Entorno

### Compose y volúmenes (producción)

| Variable | Descripción |
|----------|-------------|
| `COMPOSE_FILE` | `docker-compose.prod.yml` en `.env.prod`: sin `-f`, un `docker compose …` a secas usaría el de **desarrollo** y recrearía db y wordpress con otra configuración. |
| `HWE_DB_VOLUME` | Nombre del volumen **externo** de la base de datos (se crea una vez con `docker volume create`). Si ya tenías una instalación, el nombre de tu volumen actual (`docker volume ls`). |
| `FRONTEND_IMAGE` | Imagen del frontend construida para producción (`hwe-frontend:prod`); de aquí toma el build los *build-args*. |
| `CACHE_IMAGES_MAX_AGE_DAYS` | (opc.) Días sin uso tras los que el servicio `cache-prune` borra una variante de imagen optimizada. El ISR no se purga. |

### Dominio y TLS (producción)

| Variable | Descripción |
|----------|-------------|
| `PUBLIC_DOMAIN` | Dominio público. Caddy emite el certificado; `ALLOWED_ORIGIN` se deriva como `https://<dominio>`. |
| `ACME_EMAIL` | Correo de la cuenta de Let's Encrypt. |
| `ADMIN_DOMAIN` | (opc.) Servir `/wp-admin` y el login en su propio host. Con valor, el dominio público redirige allí. Ver `caddy/README.md` (DNS, `caddy/conf.d/admin.caddy`, `siteurl`). |

### Base de datos

| Variable | Descripción | Ejemplo |
|----------|-------------|---------|
| `MYSQL_DATABASE` | Nombre de la BD | `wordpress` |
| `MYSQL_USER` / `MYSQL_PASSWORD` | Credenciales de la BD | *(larga y aleatoria en prod)* |
| `MYSQL_ROOT_PASSWORD` | Clave root de MariaDB | *(aleatoria en prod)* |
| `MYSQL_INNODB_BUFFER_POOL_SIZE` | (opc.) ≈ 50 % del límite de memoria del servicio `db` | `256M` |
| `MYSQL_MAX_CONNECTIONS` | (opc.) Conexiones simultáneas. Todo el tráfico pasa por el BFF, así que 50 sobran; cada conexión reserva buffers dentro de los 512 MB del servicio. | `50` |

### WordPress

| Variable | Descripción |
|----------|-------------|
| `WORDPRESS_PORT` / `FRONTEND_PORT` | Puertos publicados en el host (solo desarrollo; en prod no se publican). |
| `WORDPRESS_DEBUG` | `1` en desarrollo, `0` en producción. |
| `WP_ENVIRONMENT_TYPE` | `local` / `development` / `staging` / `production`. **La introspección de GraphQL se apaga si vale `production`** (o si no está definida: falla cerrado). |
| `WORDPRESS_PUBLIC_URL` | URL pública de WordPress. En el frontend, solo para autorizar sus imágenes (CSP y `next/image`); **va en el build** de la imagen. Opcional en desarrollo. |
| `HEADLESS_FRONTEND_URL` | URL del frontend (redirección headless y respaldo de CORS). |
| `HEADLESS_ALLOWED_ORIGINS` | Allowlist CORS de WPGraphQL (coma-separada). También acota los `frontend_url` que aceptan `forgot-password` y `send-verification`. |
| `WP_TITLE`, `WP_ADMIN_USER`, `WP_ADMIN_PASSWORD`, `WP_ADMIN_EMAIL` | Datos del administrador que crea `setup.sh`. **`WP_ADMIN_PASSWORD` es obligatoria** (el instalador aborta sin ella; el compose de producción se niega a arrancar). |
| `HWE_LOGIN_SLUG` | Mueve el login a `/<slug>`; `/wp-login.php` pasa a responder 404. 6–63 caracteres `[a-z0-9-]`. Vacío = sin mover. No lo publiques. |
| `HWE_TRUSTED_PROXY_COUNT` | (constante PHP; el compose la deriva de `TRUSTED_PROXY_COUNT`) Proxies de confianza delante de WordPress, para la IP real en `rate-limit.php`. |
| `HWE_BFF_TRUST_PRIVATE_NETWORK` | (constante PHP) **Desactivada por defecto.** Con `define('HWE_BFF_TRUST_PRIVATE_NETWORK', true)` `hwe-bff-only` vuelve a aceptar como BFF una petición sin `X-Forwarded-For` desde una IP privada. Es una suposición sobre la red, no una credencial: solo en redes cerradas. |
| `HWE_INTEGRATION_ALLOWED_IPS` | IPs exactas (sin CIDR; espacios o comas) que pueden llegar a GraphQL como **integración externa** (módulo `external-integration`: ERP, logística). Vacío = nadie. Solo GraphQL y solo con la contraseña de aplicación del usuario de servicio: la Store API y `hwe/v1/auth\|consent` siguen cerradas. Ver el `MANUAL.md` del plugin. |

### Seguridad y sesión

| Variable | Descripción | Cómo generarla |
|----------|-------------|----------------|
| `GRAPHQL_JWT_AUTH_SECRET_KEY` | Secreto JWT compartido WP↔frontend | `openssl rand -base64 64` |
| `CSRF_SECRET` | Firma de tokens CSRF. **Obligatorio y sin valor de reserva**: sin él toda escritura falla | `openssl rand -base64 32` |
| `ALLOWED_ORIGIN` | Origen autorizado para escrituras `/api/*`. **Obligatorio.** Tiene que ser *exactamente* el `Origin` del navegador: `https://host[:puerto]`, sin barra final, ruta ni `:443` | URL del frontend |
| `HWE_REVALIDATION_SECRET` | Secreto compartido WP↔BFF. Firma la revalidación y es el **secreto interno** (`X-HWE-Internal-Secret`) que el BFF presenta en cada llamada a WordPress: sin él, `hwe-bff-only` responde 404. Debe coincidir en ambos lados (`define('HWE_REVALIDATION_SECRET', …)` en `wp-config.php`) | `openssl rand -hex 32` |
| `HWE_SECRETS_KEY` | Clave AES-256-GCM de los secretos del Control Center. **Obligatoria en producción** y estable: si cambia, lo cifrado queda indescifrable | `openssl rand -base64 32` |
| `HWE_2FA_KEY` | Clave AES-256-GCM del secreto TOTP. Mismas reglas | `openssl rand -base64 32` |
| `AUTH_COOKIE_NAME`, `JWT_REFRESH_COOKIE_NAME`, `CSRF_COOKIE_NAME`, `CART_COOKIE_NAME` | (opc.) Nombres de cookie (`hwe_at`, `hwe_rt`, `hwe_csrf`, `hwe_cart`) | — |
| `REFRESH_TOKEN_MAX_AGE_DAYS`, `CART_COOKIE_MAX_AGE_DAYS` | (opc.) Vida del refresh token (30) y de la cookie del carrito (7) | — |
| `COOKIE_DOMAIN` | (opc.) Dominio de cookie para subdominios | `.tu-dominio.com` |
| `TRUSTED_PROXY_COUNT` | Proxies de confianza delante del BFF (Caddy = 1). Decide cómo se calcula la IP real desde `X-Forwarded-For` | `1` |
| `WP_ADMIN_PASSWORD` y demás | Ver «WordPress» arriba | — |

### WordPress / GraphQL (lado servidor)

| Variable | Descripción |
|----------|-------------|
| `WORDPRESS_INTERNAL_API_URL` | Endpoint GraphQL por red interna Docker (`http://wordpress:80/graphql`). El navegador no habla con WordPress: no existe `NEXT_PUBLIC_WORDPRESS_API_URL`. |
| `NEXT_PUBLIC_SITE_URL` | URL pública (enlaces de auth, respaldo mientras WordPress no responde y **predeterminado de `brand.url`** si el Control Center lo deja vacío; se registra `site_config.brand_url_default`). El nombre y la marca *reales* salen de `getSiteConfig()` (Control Center → Marca): no hay variable de entorno para el nombre. |

### WooCommerce (BFF, solo servidor)

| Variable | Descripción |
|----------|-------------|
| `WC_API_URL` | REST `wc/v3` (pedidos/clientes). |
| `WC_STORE_API_URL` | Store API `wc/store/v1` (carrito/checkout). |
| `WC_CONSUMER_KEY` / `WC_CONSUMER_SECRET` | Claves `ck/cs` (`backend/scripts/generate-woo-keys.sh`). |
| `WC_WEBHOOK_SECRET` | Firma HMAC de los webhooks de WooCommerce (revalidación y pedidos). |

### Redis

| Variable | Descripción |
|----------|-------------|
| `REDIS_URL` | `redis://redis:6379` (Docker) o tu Redis gestionado. El frontend y WordPress comparten Redis: WordPress usa el prefijo `hwe-wp:` y su «flush» es selectivo (no borra revocación de sesión, rate-limit ni idempotencia del BFF). |

### Pagos (capa agnóstica)

| Variable | Descripción |
|----------|-------------|
| `PAYMENT_PROVIDER` | Anulación de la pasarela activa: `noop` (sandbox) \| `wompi` \| `avalpay` \| `payu` \| `bold`. **Manda el panel**; esta variable solo la anula. |
| `PAYMENT_CURRENCY` | Moneda ISO-4217 de respaldo. La moneda de la tienda es `ecommerce.currency` del panel (defecto `EUR`; fíjala siempre). |
| `NEXT_PUBLIC_PAYMENT_RETURN_URL` | URL de retorno tras el checkout alojado. `POST /api/payments/create` solo acepta un `returnUrl` del propio sitio. |
| `NOOP_INTEGRITY_SECRET` | Secreto de los webhooks del proveedor `noop`. |
| `WOMPI_*`, `PAYU_*`, `BOLD_*` | Credenciales server-only por pasarela. Wompi y Aval Pay se configuran de punta a punta **desde el panel**; PayU y Bold son stubs (501). |

Para añadir una pasarela: `lib/payments/providers/<nombre>.ts` (ver `docs/FRONTEND_CONNECT.md`, «Pagos»).

### Analítica

Los servicios (GA4, GTM, Plausible, Meta Pixel + Conversions API, TikTok, HubSpot) y sus ids se
configuran en el panel (*Integraciones*); aquí solo quedan dos variables de **despliegue**:

| Variable | Descripción |
|----------|-------------|
| `PLAUSIBLE_HOST` | Host de Plausible si es autoalojado (por defecto, el servicio alojado). |
| `CSP_ANALYTICS_PROVIDERS` | La CSP autoriza por defecto los hosts de todos los proveedores. Si solo usas algunos, estréchala (`ga4,gtm,plausible,meta,tiktok,hubspot`; `none` = ninguno): un script de un proveedor no listado se bloquea. |

### Rendimiento y resistencia del frontend (todas opcionales)

| Variable | Defecto | Descripción |
|----------|---------|-------------|
| `GRAPHQL_TIMEOUT_MS` / `CONFIG_TIMEOUT_MS` | 10000 / 5000 | Plazo por intento de las lecturas a WordPress. Una petición abortada por plazo **no** entra en el Data Cache de Next. |
| `BUILD_GRAPHQL_TIMEOUT_MS` / `BUILD_CONFIG_TIMEOUT_MS` | — | Paciencia **solo del build**, con WordPress cargado por el propio build. |
| `RUNTIME_GRAPHQL_TIMEOUT_MS` / `RUNTIME_CONFIG_TIMEOUT_MS` | — | Paciencia en runtime: sin ella, la lentitud de WordPress se convierte en cascada de 500. |
| `PRERENDER_LONG_TAIL` | `all` | Cola larga del prerender (fichas y entradas): `all` las hornea en el build; `none` las deja para ISR bajo demanda (recomendado con un catálogo grande o una máquina justa). |
| `NEXT_STATIC_GENERATION_MAX_CONCURRENCY` / `…_MIN_PAGES_PER_WORKER` / `NEXT_STATIC_PAGE_GENERATION_TIMEOUT` | 2 / 400 / 180 | Ritmo del build en un servidor que comparte CPU con WordPress. Sus equivalentes `BUILD_STATIC_*` aplican solo al build de la imagen. |
| `NEXT_IMAGE_MIN_CACHE_TTL` | 2592000 | Segundos que vive una imagen ya optimizada (los uploads de WordPress son inmutables). |

### Operación y observabilidad

| Variable | Descripción |
|----------|-------------|
| `BACKUP_RETAIN_DAYS` | Días de retención local de las copias (el panel puede sobrescribirlo). |
| `ORDER_NOTIFICATION_WEBHOOK_URL` | (opc.) Webhook interno (Slack/Teams/ERP/cola) al que el BFF notifica eventos de pedido. Los correos **al cliente** los envía WooCommerce. |
| `LOG_LEVEL` | Nivel de pino (`debug`/`info`/`warn`/`error`). |
| `SENTRY_DSN` | DSN de Sentry (si se omite, no se envía nada). |

---

## Negocio: HWE Control Center

La fuente única de los campos es `backend/wp-content/mu-plugins/hwe-control-center/Schema.php`
(los grupos del núcleo, por tema, en `hwe-control-center/SchemaGroups/`). Los campos `public` salen
por `GET /wp-json/hwe/v1/config`; los `secret` se cifran en reposo y no salen nunca.

Los valores de una instancia se siembran con `wp hwe setup instance.config.json`
(`docs/CREATE_INSTANCE.md`; plantilla: `backend/scripts/instance.config.example.json`).

| Grupo | Campos | Notas |
|-------|--------|-------|
| `modules` | lista de ids en `instance.config.json` | Qué módulos quedan **activos**. La fuente de verdad en tiempo de ejecución es si el plugin está activo (`config.modules`). Ver «Módulos». |
| `i18n` | `locales` | Idiomas secundarios (p. ej. `en, fr`), sin el de `brand.locale`. Deben coincidir con `frontend/src/i18n/routing.ts` (el arranque avisa si divergen). |
| Marca / diseño | `brand.*`, `design.colors.*`, `design.typography.*` | Los colores solo admiten `#rrggbb` y las fuentes un nombre simple; lo que no cumple cae al valor por defecto. |
| Tienda (`ecommerce`) | `currency` (defecto `EUR`; fíjala siempre), `country`, `price_decimals` (calculado), `products_per_page`, flags `*_enabled`, `checkout_login_required`, `free_shipping.*` | `country` elige el paquete por país. El login se sincroniza con el ajuste nativo de Woo; el envío gratis lo aplica el módulo `shipping`. |
| Pasarelas de pago | `provider`, `gateway_enabled`, `whatsapp_enabled`, `cash_enabled` (+ credenciales por pasarela) | El panel solo enseña las credenciales de la pasarela activa, y ninguna si el pago en línea está apagado. Lo público lo lee el checkout; los secretos solo salen por `hwe/v1/payments/credentials` (canal interno). El efectivo usa «Contra reembolso», que se sincroniza al guardar. |
| Integraciones | ids de analítica, Mapas (`maps_provider`, `maps_api_key`), `consent_retention_years`, SMTP (+ `meta_capi_*` por canal interno) | Cada id activa su proveedor; vacío = desactivado. La clave de mapas se guarda cifrada y solo la usa el servidor. |
| Legal → Sedes | opcionales, hasta 5 (`location_1…location_5`) | Alimentan el JSON-LD `LocalBusiness` y la página de contacto. El panel solo enseña las que tienen datos y un botón «Añadir sede»; «Quitar sede» vacía el hueco al guardar. |
| Acceso (`auth`) | `phone_login_enabled`, `phone_default_dial_code` | Login y registro con el teléfono (E.164, único). Apagado por defecto. |
| SEO / GEO | `seo.*`, `geo.*` | Dos pestañas: «SEO» y «GEO (IA)». Las señales de entidad (`seo.organization_logo`, `founding_date`, `knows_about`, `alternate_names`, `founder_*`) se editan en GEO aunque su ruta siga en `seo.*`. `faq` y `faq_payments` son traducibles (formato «pregunta \| respuesta»). |
| Backups | frecuencia, retención, `keep_only_latest`, `exclude_thumbnails`, `include_config_export`, `notify_email` | Ver `RUNBOOK.md`: el panel escribe `hwe-backup-config.json` y el contenedor `backup` lo lee. |
| `instance` | lo que declare la instancia | Pestaña «Instancia»; los campos `public` salen en `instance.<grupo>.<campo>`. Ver `docs/PLUGIN_DEVELOPMENT.md`. |
| `shipping`, `landings`, `loyalty` | los puebla el módulo | Grupos raíz reservados: solo los rellena el plugin del módulo. |

**Campos traducibles.** Un campo marcado `'translatable' => true` en el schema (hoy
`brand.tagline`, `brand.description`, `geo.faq` y `geo.faq_payments`; una instancia puede marcar
los suyos) tiene una variante `<campo>_<idioma>` por cada idioma de `i18n.locales`: `tagline_en`,
`tagline_pt_br`… Vacía, se usa el texto del idioma por defecto. En el frontend se leen con
`pickLocalized(entity, "tagline", locale)` (`lib/i18n/pick-localized.ts`).

**Un campo nuevo** se declara en `Schema.php` (o en su grupo de `SchemaGroups/`), con su valor por
defecto en `frontend/src/lib/config/defaults.ts` (`CONFIG_DEFAULTS`, respaldo si WordPress no
responde), y se consume con `getSiteConfig()`.

### Mapas

*Integraciones → Mapas* es la integración genérica de mapas del núcleo. La usa el módulo
`shipping` para calcular distancias y geocodificar direcciones; cualquier otro módulo puede leer
la misma configuración con `\HWE\ControlCenter\Storage::get(['integrations', 'maps_provider'])` y
`\HWE\ControlCenter\SecretsStorage::get(['integrations', 'maps_api_key'])`. Ninguno de los dos
campos sale por la API pública: el proveedor y la clave se resuelven en el servidor.

| Campo | Valores | Notas |
|-------|---------|-------|
| Proveedor | `none`, `google_maps`, `openrouteservice` | Con `none` se calcula en línea recta y la geocodificación cae al servicio público de respaldo. |
| Clave de API | — | Cifrada en reposo (AES-256-GCM). Solo la usa el servidor: no sale por `/wp-json/hwe/v1/config`, ni por el canal interno del BFF, ni en una URL o un log. Déjala en blanco al guardar para conservar la actual. |

Para Google hay que habilitar las APIs **Routes** y **Geocoding** en el proyecto y restringir la
clave por la **IP del servidor**, no por referer HTTP: las peticiones las hace WordPress, nunca el
navegador.

### Envío → Distancia

*Control Center → Envío* (grupo `shipping`, del módulo `shipping`). Estos campos solo afectan a las ciudades
con envío **por distancia**; las ciudades fijas no los usan. El proveedor y su clave están en *Integraciones →
Mapas* (arriba). Ninguno de estos campos sale por `/wp-json/hwe/v1/config`, salvo `shipping.distance_enabled`
(calculado: hay al menos una ciudad por distancia).

| Campo | Valores | Por defecto | Notas |
|-------|---------|-------------|-------|
| Método de cálculo (`distance_method`) | `maps`, `straight_line` | `straight_line` | Con `maps` usa el proveedor de Integraciones → Mapas. Sin proveedor o sin clave válidos se calcula en línea recta y wp-admin avisa. |
| Modo de viaje (`distance_travel_mode`) | `driving`, `walking` | `driving` | Conducción con tráfico en Google; velocidad media en la línea recta (30 km/h y 5 km/h). |
| Incremento sobre la línea recta (`distance_straight_line_increment`) | 0–200 (%) | 30 | Se aplica también cuando un proveedor falla y el cálculo cae a la línea recta. |
| Caché de distancias (`distance_cache_ttl_hours`) | ≥ 1 (horas) | 24 | Las distancias de respaldo se cachean 15 minutos, aparte de este valor. |

**«Probar conexión»** (en esta misma sección, solo administradores) hace una llamada real al proveedor con
una dirección de prueba que escribes en el momento, sin valor por defecto de ningún país. Responde con
`ok`, `missing_key`, `unauthorized`, `api_not_enabled`, `rate_limited`, `network` o `no_results`, y una
pista. Nunca muestra la clave.

Las zonas de reserva (`shipping.reserve_enabled`, `reserve_name`, `reserve_cost`) siguen en esta sección. `reserve_enabled`
significa ahora «enviar también a las ciudades sin configurar» (ver `backend/wp-content/plugins/hwe-shipping/README.md`).

### Módulos

Un módulo es un plugin regular `hwe-<id>` (con `README.md` y `MANUAL.md`) que aporta una
funcionalidad que no toda instancia necesita. Se enciende con `modules` en `instance.config.json`
(`wp hwe setup` activa los listados y **desactiva los demás** del catálogo; sin la clave no toca
ningún plugin) o desde `wp-admin → Plugins`. Con el módulo apagado, su lector devuelve vacío o
nulo, sus rutas del BFF responden 404 y su UI no se pinta.

| Id | Qué aporta | Configuración propia |
|----|------------|----------------------|
| `banners` | Banners por tipo × locación, N idiomas | Plugin (CPT `hwe_banner`); `banners.enabled` |
| `shipping` | Tarifa fija o por distancia por ciudad (con opciones de entrega y pin del cliente), métodos extra, envío gratis por opción, zona de reserva | Menú «Envío» de wp-admin (tarifas y métodos) y Control Center → Envío (incluida «Distancia») |
| `product-landing` | Una landing por producto, a N idiomas | Caja «Landing del producto» en la ficha |
| `landings` | Landings editoriales (artículo / persona) | Control Center → Landings |
| `consent-log` | Registro del consentimiento como prueba (RGPD) | `integrations.consent_retention_years` |
| `site-reviews` | Reseñas del **sitio**: la calificación que un cliente da a un pedido completado (una por pedido) | WooCommerce → «Reseñas del sitio» (solo lectura) |
| `coupon-rules` | Reglas de cupón (ventana de validez, primera compra, requiere sesión, alcance, visibilidad) y anuncio de los públicos | Marketing → Cupones |
| `customer-identity` | Documento de identidad y aceptación de términos | Tipos y patrones del paquete de país |
| `loyalty` | Monedero de puntos (ganar, caducar, canjear y transferir) y referidos de dos niveles | Control Center → Fidelización (`loyalty.*`); menú «Fidelización» de wp-admin |
| `memberships` | Niveles y beneficios editables, compra y renovación de membresías, catálogo exclusivo con acceso anticipado, descuento de socio, envíos gratis, carné con QR y regalos por referido | Control Center → Membresías (`memberships.*`); menú «Membresías» de wp-admin; pestaña «Membresía» de cada producto y rango mínimo en productos y categorías |
| `external-integration` | Acceso de un integrador externo a pedidos | `HWE_INTEGRATION_ALLOWED_IPS` |

Comprobar que cada módulo está completo y que encender/apagar hace lo prometido:
`sh backend/scripts/verify-modules.sh` (ver `RUNBOOK.md`).

### Fidelización (`loyalty`)

La pestaña **HWE Config → Fidelización** la puebla el plugin `hwe-loyalty` en el grupo reservado
`loyalty`; el detalle de cada campo está en su `README.md`. Los ajustes que más cambian el
comportamiento:

| Campo | Default | Qué hace |
|---|---|---|
| `points_enabled` | `false` | Interruptor general del monedero. Sin él, referidos y transferencias se comportan como apagados. |
| `referrals_enabled` | `false` | Comisiones y puntos por alta de referidos (exige puntos). |
| `currency_name` / `currency_name_plural` / `currency_icon` | `punto` / `puntos` / vacío | Nombre e icono de la unidad en todos los textos del módulo. |
| `point_value` | `0.01` | Valor de una unidad en la moneda de la tienda. Con `0` o menos, el canje queda apagado. |
| `min_redeem`, `max_redeem_per_order` | `100`, `0` | Mínimo para canjear y tope por pedido (`0` = sin tope). |
| `max_redeem_percent` | `0` | Tope del descuento como porcentaje (0–100) de la base sin envío (productos y cargos ajenos), en unidades redondeadas a la baja. Se combina con los demás tomando el menor; `0` = sin tope. El filtro `hwe_loyalty_max_redeemable` puede endurecerlo. |
| `full_payment_enabled`, `min_cash_due` | `false`, `0.01` | Si el canje puede cubrir el pedido completo; con el pago completo apagado, importe que siempre queda por pagar en dinero. |
| `expiry_days` | `0` | Caducidad de los abonos (`0` = no caducan). |
| `cashback_enabled`, `cashback_percent` | `false`, `0` | Devolución al comprador sobre la base de la compra. |
| `events.*` | `0` | Unidades por registrarse, reseñar y cumplir años (`0` = evento apagado). |
| `referral_signup_points_l1` / `_l2`, `commission_*`, `level2_enabled`, `commission_duration_days` | `0` / `false` | Primas de alta, comisiones por compra y ventana de comisión. |
| `transfers_enabled`, `transfer_min`, `transfer_fee_percent`, `transfer_daily_cap` | `false`, `100`, `0`, `0` | Transferencias entre clientes por código, con mínimo, comisión y tope diario. |
| `allowed_roles` | `customer, subscriber` | Roles que participan. **No público**; se amplía con el filtro `hwe_loyalty_participant_roles`. |

### Membresías (`memberships`)

La pestaña **HWE Config → Membresías** la puebla el plugin `hwe-memberships` en el grupo reservado
`memberships`; el detalle del modelo (niveles, concesiones, catálogo exclusivo y carné) está en su
`README.md`, y el día a día del editor en su `MANUAL.md`.

| Campo | Default | Público | Qué hace |
|---|---|---|---|
| `card_enabled` | `false` | sí | Enciende el carné digital y sus rutas del BFF. |
| `card_verify_path` | `/verify` | sí | Ruta del frontend a la que apunta el QR. Una ruta vacía cae a `/verify`; una sin `/` inicial se normaliza. |
| `member_number_prefix` | vacío | sí | Prefijo del número de socio. Vacío = sin prefijo. |
| `card_token_ttl_hours` | `24` | no | Horas de vigencia del token del carné, recortadas a 1–720. |
| `expiring_notice_days` | `7` | no | Días de antelación del aviso de caducidad; un valor negativo se recorta a 0. |
| `referral_gift_requires_email_verification` | `true` | no | El regalo al referido solo se concede cuando confirma su correo. |

Los tres campos públicos son los únicos que salen por `/wp-json/hwe/v1/config` y los únicos que
necesita `SiteConfig.memberships` en el frontend. Los niveles, las concesiones y los requisitos de
catálogo **no** viven en la config: son contenido y se leen por su propia ruta. Con el módulo
apagado, la API sirve los defaults y el BFF responde 404.

---

## Email transaccional (SMTP)

El envío de correo (reset de contraseña, verificación de email y emails de pedido de
WooCommerce) se configura **desde el panel**, no por variables de entorno:
*wp-admin → HWE Config → Integraciones*.

| Campo | Notas |
|-------|-------|
| Activar envío por SMTP | Interruptor maestro. Si está off, WordPress usa el envío PHP por defecto (poco fiable). |
| SMTP Host / Puerto | P. ej. `email-smtp.eu-west-1.amazonaws.com` / `587`. |
| Cifrado | `STARTTLS` (587), `SSL/TLS` (465) o sin cifrado (solo redes internas). |
| Requiere autenticación | Si el relay exige usuario/contraseña (lo normal). |
| Usuario / Contraseña | La **contraseña se guarda cifrada** (AES-256-GCM); déjala en blanco al guardar para conservarla. |
| Email remitente (From) / Nombre | Usa un dominio con **SPF/DKIM** válidos. |

Tras guardar, usa **«Enviar email de prueba»** del panel. El transporte lo implementa el mu-plugin
`hwe-smtp.php` (hook `phpmailer_init`).

Para gestionar el secreto fuera de la BD (p. ej. en `wp-config.php`), define cualquiera de estas
constantes, que **tienen prioridad** sobre el panel: `HWE_SMTP_ENABLED`, `HWE_SMTP_HOST`,
`HWE_SMTP_PORT`, `HWE_SMTP_ENCRYPTION`, `HWE_SMTP_AUTH`, `HWE_SMTP_USER`, `HWE_SMTP_PASSWORD`,
`HWE_SMTP_FROM`, `HWE_SMTP_FROM_NAME`.

> Entregabilidad: configura **SPF, DKIM y DMARC** en el DNS del dominio remitente. Sin ellos, los
> correos legítimos acaban en spam aunque el SMTP funcione.
