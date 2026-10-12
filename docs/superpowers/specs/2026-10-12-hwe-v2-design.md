# Headless Web Ecosystem v2.0.0: diseño

> **Estado:** aprobado en brainstorming (2026-10-12).
> **Contexto:** `docs/superpowers/specs/2026-10-12-cruce-siu-framework.md` (inventario,
> hallazgos y clasificación por niveles).
> **Fuente de portación:** repo Siu Tutuava, HEAD `d008bc15`
> (`/home/sergi/Documentos/Proyectos/Siu Tutuava`).

## 1. Objetivo

Llevar el framework de v1.0 (más Banners) a **v2.0.0** incorporando lo que Siu Tutuava validó
en producción:

1. Las correcciones de seguridad y funcionamiento del núcleo.
2. Un **contrato de funcionalidades de instancia** que permita actualizar el núcleo sin
   conflictos.
3. Las funcionalidades genéricas de Siu (Nivel 1).
4. Las útiles con supuestos de Siu, generalizadas (Nivel 2).

Todo como plantilla: nada del núcleo conoce a una instancia concreta.

### Decisiones tomadas

| Tema | Decisión |
|---|---|
| Organización | Un único spec con fases F0–F6; cada fase deja el repo en verde |
| Versión | v2.0.0, un único salto con *breaking changes* documentados; no hay v1.0.1 |
| Arquitectura de instancia | Carpetas de instancia reservadas + hooks del núcleo (§4) |
| Portación | Archivo a archivo desde el HEAD de Siu, quitando lo específico de Siu y trayendo sus tests; cada commit cita el commit de origen en Siu |
| UI de lo portado | Starter mínimo y neutro en las vistas y componentes heredados |
| Traducción de contenido | N idiomas, tomados de la configuración de rutas |
| Migración de Siu | Solo la guía (`UPGRADE.md`); migrar o desplegar Siu queda fuera |
| Tests PHP | PHPUnit + Brain Monkey en CI, más scripts `verify-*.sh` contra la pila |
| Estilo del plan | Sin código literal, dirigido a agentes, con criterio técnico abierto dentro del spec, `AGENTS.md` y `CLAUDE.md` |

### Fuera de alcance

- Migrar o desplegar Siu.
- El visor 3D (`hwe-glb-uploads`, `media/model3d-*`).
- Todo el Nivel 3 del cruce: `Instance/Siu*`, dominio Siu en `lib/`, `components/siu/`,
  seeds `*-siu.sh` y migración v3.
- Implementar PayU y Bold, que siguen como stubs que responden 501.

---

## 2. Principios transversales

- **Frontera núcleo/instancia.**
  - Núcleo: `backend/` salvo las rutas de instancia, `frontend/src/lib/**`,
    `frontend/src/app/api/**`, `frontend/src/proxy.ts` y `frontend/src/components/ui/**`.
  - Instancia: `mu-plugins/instance/`, plugins `hwe-instance-*`, `frontend/src/instance/**`,
    `frontend/src/components/**` (salvo `ui/`) y `frontend/src/app/[locale]/**`.
- **El navegador nunca habla con WordPress.** Todo pasa por el BFF.
- **La seguridad falla cerrada**: secretos, 2FA, CSRF, idempotencia y `bff-only`.
- **El contenido falla de forma suave**, cayendo a valores por defecto: config, paquete de
  país, banners y traducciones (que caen al idioma por defecto).
- **Ningún fallo silencioso.** Todo error tragado se registra con un `event` estructurado
  (pino en el frontend, `error_log` con prefijo en WordPress).
- **Flags y módulos se respetan en el BFF**, no solo en la UI: con el módulo apagado, sus
  endpoints responden 404.
- **Grep anti-Siu.** En las rutas de núcleo, `siu|sheilak|mascota|essence|esencia|dormilab|atlas`
  (sin distinguir mayúsculas) debe devolver 0 resultados. Se comprueba en CI desde F2.

---

## 3. F0: base de tooling

**Objetivo:** dejar listas las herramientas que usan las demás fases.

- **Dependencias.** Se suben las de la tabla del cruce (§2 de ese documento).
  - Next se fija en la versión estable más reciente que sea ≥ 16.3.6 en el momento de
    ejecutar el plan.
  - Se retiran `@emnapi/*` si dejan de hacer falta.
  - Se añaden `server-only`, `eslint-plugin-jsx-a11y`, `@testing-library/react`, `jsdom` y
    `qrcode` (este último lo usa F1).
- **PHP.** `composer` en `backend/` con PHPUnit y Brain Monkey.
  - `phpunit.xml` limitado al código propio (mu-plugins, plugins `hwe-*` y el tema base).
  - Un bootstrap que cargue los archivos bajo prueba sin WordPress.
  - Job `phpunit` en CI.
- **Vitest.** Incluye también `src/**/*.test.{ts,tsx}`, además de `tests/unit`.
  - Entorno `node` por defecto y `jsdom` para los tests de componentes.
  - Se mantienen los umbrales de cobertura.
- **i18n.** `npm run i18n:check`, portado de `scripts/check-i18n-parity.mjs` de Siu, como paso
  de CI.
- **Stub de CI.** Se porta `.github/scripts/stub-graphql.mjs` y los fixtures que use, para que
  `build` y el e2e de CI corran sin WordPress. Se adapta el job e2e de la misma forma que en Siu
  (`05426f2e`: el job no podía arrancar el servidor).

**Aceptación:**
- CI en verde, con los jobs nuevos (`phpunit`, `i18n:check`) y un test PHP trivial que
  demuestra que la tubería funciona;
- `npm audit --audit-level=high` sin hallazgos.

---

## 4. F2: contrato de instancia

(Se describe antes que F1 porque las demás fases lo referencian. **F1 se ejecuta primero.**)

### 4.1 Backend

- **Cargador.** El mu-plugin de núcleo `hwe-instance-loader.php` carga los `.php` de primer
  nivel de `mu-plugins/instance/` en orden alfabético.
  - Con la carpeta vacía o ausente, no hace nada.
  - Define `HWE_CORE_CONTRACT_VERSION` (inicial: `2.0.0`).
  - El framework solo versiona el `README.md` de la carpeta (en `.gitignore`: se versiona la
    carpeta y se ignora su contenido salvo el README; la instancia ajusta esto al clonar).
- **Plugins grandes de instancia.** Se permiten como plugins regulares `hwe-instance-<slug>`
  (patrón de `.gitignore` ya preparado).
- **Grupo `instance` del schema.** El núcleo declara un grupo raíz `instance`, vacío, que se
  pinta como pestaña «Instancia» del Control Center.
  - La instancia añade hijos con el filtro `hwe_control_center_schema`.
  - Una clave raíz añadida fuera de `core ∪ {instance}` se descarta y se registra en el log.
  - `PublicConfigWalker` expone `instance` como cualquier otro grupo.
- **Hooks del contrato.** Nombres estables, documentados en `docs/PLUGIN_DEVELOPMENT.md`:
  - `hwe_control_center_schema` (filtro).
  - `hwe_control_center_field_types` (filtro, ver §6.1).
  - `hwe_control_center_saved` (acción): recibe los datos públicos saneados y el POST crudo.
    **Sustituye** a las seis acciones con nombre de Siu (`*_essence_copy_save`,
    `*_mascota_fields_save`…). `AdminPage` deja de leer claves POST concretas.
  - `hwe_revalidation_tags` (filtro de los tags ISR aceptados).
  - Los de módulos y de F5 se documentan en el mismo archivo según se creen.
- **Módulos opcionales.** Son plugins regulares del framework.
  - `instance.config.json` → `modules: [<id>…]`; `wp hwe setup` activa los listados y
    desactiva los demás módulos del framework.
  - La config pública expone `modules: { <id>: boolean }`, calculado según si el plugin está
    activo. Esa es la fuente de verdad.
  - Catálogo v2: `banners`, `shipping`, `product-landing`, `landings`, `consent-log`,
    `site-reviews`, `coupon-rules`, `customer-identity`, `external-integration`.

### 4.2 Frontend

- **`src/instance/`** es propiedad de la instancia. El framework publica:
  - `registry.ts`: un registro vacío que cumple la interfaz `InstanceRegistry`, definida en el
    núcleo (`lib/instance/types.ts` o equivalente). Es el **único** módulo de instancia que el
    núcleo importa. Contenido inicial: tags ISR extra. F5 añade entradas de landing estáticas,
    secciones únicas y tipos de campos de producto.
  - `config.d.ts`: un ejemplo de *module augmentation* de la interfaz vacía `InstanceConfig`.
    `SiteConfig.instance` queda tipado como `InstanceConfig`.
  - `lib/`: el dominio de la instancia (vacío en el framework).
- **Regla ESLint.** `src/lib/**`, `src/app/api/**`, `src/proxy.ts` y `src/components/ui/**` no
  pueden importar `@/components/**` (salvo `ui/`) ni `@/instance/**` (salvo
  `@/instance/registry`). `import type` también cuenta.
- **Config.** `lib/config/types.ts` y `CONFIG_DEFAULTS` no contienen campos de ninguna
  instancia. Se añaden `instance` y `modules`.

### 4.3 Ejemplo vivo

`examples/instance-minimal/` replica las rutas de instancia de backend y frontend. Contiene:
- un campo `instance.example.*`;
- un tipo de campo propio;
- un tag ISR extra;
- una vista que lee `config.instance.example`.

Más adelante (F5) se amplía con un campo de producto, un detalle de línea de pedido y una
landing estática con una sección única.

Un **job de CI «contrato»** superpone el ejemplo al repo y ejecuta `phpunit`, `test`,
`type-check` y `build`.

**Aceptación de F2:**
- el job «contrato» en verde;
- la regla ESLint rechaza un import prohibido (comprobado con un fixture de lint);
- grep anti-Siu = 0;
- `PLUGIN_DEVELOPMENT.md` documenta el contrato.

---

## 5. F1: seguridad

Se portan todos los hallazgos de la §2 del cruce. **Cada uno lleva un test de regresión** que
falla con el código anterior y pasa con el nuevo. Siu ya trae la mayoría (`csrf.test.ts`,
`idempotency.test.ts`, `proxy-origin.test.ts`, `remote.test.ts`, `2fa/qr/route.test.ts`,
`change-password/route.test.ts`, `secret-guard-origin.test.ts`); los de PHP se escriben con
Brain Monkey.

| Área | Qué | Origen en Siu |
|---|---|---|
| Auth | Login con 2FA mediante un id opaco de un solo uso en Redis; si Redis no responde, falla cerrado con 503 | `655e30dc` |
| Auth | Secreto TOTP pendiente en servidor; QR generado por el BFF (`/api/auth/2fa/qr`); `api.qrserver.com` sale de la CSP | `cce52c06`, `7981816d`, `006929b5` |
| Auth | El endpoint de 2FA en WordPress exige prueba TOTP o código de recuperación para activar y desactivar | `572a9796`, `f697fbbf` |
| Auth | Cambiar la contraseña revoca las demás sesiones | `c8c22abc` |
| Auth | Timeout en el refresh del JWT dentro del proxy | `e11bcc8d` |
| Auth | `frontend_url`/`verification_url` validados contra una allowlist | `b2d7bb2e` |
| BFF | Corrección de `deepMerge` (el override escalar o array reemplaza al valor por defecto) | `c3c253d1` |
| BFF | Excepción de la barrera de Origin para una **lista cerrada** de rutas que se autentican por firma (webhooks de Woo, `/api/revalidate`, webhooks de pago); cada una sigue verificando su firma | `39344b99` |
| BFF | `CSRF_SECRET` falla cerrado | `a4e51ae4` |
| BFF | La idempotencia no falla abierta | `9f01a94f` |
| BFF | Allowlist para `returnUrl` | `cab6a23d` |
| BFF | La identidad de las reseñas sale de la cuenta | `afab4868` |
| BFF | Validación del tipo de `payload.id` | `ad34ce7d` |
| BFF | Rate-limit en la búsqueda, `/api/revalidate` y la lectura del carrito; `verifyHmacSignature` compartido | `e9d5d642`, `30d022b1`, `be101b58` |
| BFF | `rel="noopener noreferrer"` en `sanitizeHtml`, con el hook registrado una sola vez | `a58de100`, `ffc65842` |
| BFF | Validación de los tokens de diseño antes de inyectarlos en `<style>` | `44bb5f5f` |
| BFF | `secret-guard` exige `ALLOWED_ORIGIN` https en producción; el proxy cierra `wc/store` y `wc/v3` | `1c7080fb`, `6ea9f0ff` |
| BFF | Zod en lugar de casts manuales en wishlist, 2fa-disable y verify-email | `e7572d60`, `bf6f0e90` |
| WP | Sin clave de cifrado de reserva: si faltan las salts, falla cerrado y lo registra en el log; activar 2FA con el cifrado fallido responde 500 | `b4eb6ed7` |
| WP | `hwe-bff-only` con **secreto compartido** (ver abajo) | `dab25cac`, `93730545` (adaptado) |
| WP | Endurecimiento de `rate-limit.php`, protección de `wp-login`, `WP_ADMIN_PASSWORD` obligatorio en producción | `efc4a58b` |
| Ops | Backups sin `-p<password>`, sin fallos silenciosos y con cron activo en producción; techo de memoria de Apache; cabeceras de seguridad en Caddy para las rutas de WP; checksum en `setup.sh`; `.gitignore` de `.env.prod`, `*.tar.gz` y `/backups/` | `7682b341`, `fbd0ca4f`, `e2761df8`, `88a851a4`, `ec4d8142`, `d4b13199`, `29e3d701`, `fe5ce0a9` |
| Ops | Eliminar `BackupConfig::triggerNow()` (código muerto con `exec()`) | `1aa0dcfe` |
| Deps | `npm audit --audit-level=high` como paso de CI que falla si encuentra algo | — |

**Adaptación de `hwe-bff-only`.**
- El BFF añade `X-HWE-Internal-Secret` a **todas** sus llamadas a WordPress (GraphQL, Store
  API, `wc/v3`, `hwe/v1/*`).
- `hwe-bff-only` sirve GraphQL, la Store API, `hwe/v1/auth/*` y `hwe/v1/consent/*` solo con
  ese secreto válido (comparación en tiempo constante) o desde WP-CLI. Cubre la ruta y el
  parámetro (`?graphql`, `?rest_route=`), y una petición rechazada recibe un 404.
- La heurística de red de Siu (sin `X-Forwarded-For` y desde IP privada) queda detrás de
  `HWE_BFF_TRUST_PRIVATE_NETWORK`, **desactivada por defecto**.
- La puerta por IP de integraciones externas se define en F5.
- Se elimina la rama de navegador de `lib/graphql-client.ts` y la variable
  `NEXT_PUBLIC_WORDPRESS_API_URL` (no tiene uso, y se verifica antes de quitarla).

**Aceptación de F1:**
- tests de regresión en verde;
- `npm audit` limpio;
- `backend/scripts/verify-security.sh` contra la pila Docker comprueba:
  - `/?graphql` y `?rest_route=/wc/store/v1/cart` responden 404 sin el secreto;
  - un webhook firmado atraviesa el proxy y uno sin firma se rechaza;
  - el login con 2FA no devuelve tokens antes del segundo factor.

---

## 6. F3: infraestructura del Control Center

### 6.1 Registro de tipos de campo

- Se sustituye el `switch` de render del Walker por un registro. Cada tipo declara su render,
  su saneado y su persistencia: `config` (en `hwe_config`), `secret` o `external` (lo
  gestiona su módulo al recibir `hwe_control_center_saved`). `external` reemplaza a
  `skip_persist`.
- Los tipos del núcleo se registran por el mismo filtro que los de una instancia.
- **Tipos del núcleo:**
  - `text`, `textarea`, `email`, `url`, `color`, `boolean`, `select`, `secret`, `group`;
  - `image`, `file`, `number`, `richtext`, `link`, `slug`, `lines`, `pairs`,
    `product_multiselect`, `product_fields_list`.
- `product_fields_list` se generaliza: edita meta de producto por cada ítem del nodo, declara
  las claves meta que escribe y las persiste él mismo (`external`). Así desaparecen las claves
  POST con nombre de instancia.
- `landing_registry` y `registry_type` se mueven al módulo `landings` (F5).

### 6.2 Piezas que se portan

- `PaymentsInternalApi` e `IntegrationsInternalApi` (credenciales descifradas, solo con el
  secreto interno).
- `WooCurrencySync`, `WooGuestCheckoutSync`, `WooCashGatewaySync`.
- `FreeShippingRule`, que entrega la regla por `hwe_shipping_free_rule`.
- `BackupActions` (backup manual, exportar config, `status.json`), `BackupNotify` y el
  `BackupConfig` endurecido.
- Correcciones de `Storage`, `SecretsStorage` y `RestApi`: `merge` en lugar de `setAll`,
  guardado *sparse* en `wp hwe setup` y secretos que se muestran como guardados (`3f42d4fe`).
- El schema ampliado de Siu en `brand`, `social`, `legal` (incluidas las sedes `locations`),
  `ecommerce` (`checkout_login_required`, `cash_enabled`, `free_shipping`…), `integrations`
  (GTM, Meta + CAPI, TikTok, HubSpot), `backups`, `seo` y `geo` (incluidas las FAQ).
  - **Se excluye** lo de Siu: `whatsapp_mascotas` y `model3d_*`.
  - Las credenciales de AvalPay y Wompi se reorganizan en la sección de pagos, por pasarela.
- Las partes genéricas de `assets/admin.css` y `admin.js`.

### 6.3 Partición

- `AdminPage` se divide por responsabilidad: render de página, guardado y avisos.
- El Walker queda como recorrido del árbol y delega en el registro.

**Aceptación de F3:**
- tests PHPUnit del registro, del saneado de cada tipo, de `sanitizeAndSplit` (modo normal y
  *sparse*), de la persistencia `external` y del descarte de claves raíz ajenas;
- grep anti-Siu = 0 en el Control Center;
- el ejemplo vivo registra un tipo propio.

---

## 7. F4: port del Nivel 1 y starter neutro

### 7.1 Clasificación de los mu-plugins

- **Núcleo**, siempre activo:
  - `hwe-login-slug` (slug por `HWE_LOGIN_SLUG`), `hwe-svg-uploads`;
  - `hwe-post-revalidate`, `hwe-menu-revalidate`;
  - `hwe-{post,page,product,category}-seo`;
  - `hwe-featured-post`, `hwe-contact`;
  - `hwe-email-chrome` + plantillas de correo de Woo en el tema base, y `hwe-email-test`. Estas
    piezas **sustituyen** a `woocommerce-email-branding.php`;
  - `hwe-space-hygiene`, `hwe-link-hygiene`, `hwe-media-reference`, `hwe-admin-product-image`;
  - `hwe-guest-order-badge`, `hwe-best-sellers`, `hwe-order-tracking`.
- **Módulos**, como plugins regulares: `consent-log` (con `ConsentStats`), `site-reviews` y
  `coupon-rules`.
- Del resto de mu-plugins de núcleo, se integran las mejoras de Siu en `headless-config`,
  `graphql-protection`, `security`, `hwe-auth`, `hwe-smtp`, `hwe-log-manager` y
  `woocommerce-headless`.
  - `woocommerce-shipping-config.php` se retira porque lo sustituye el módulo `shipping`
    (F5).

### 7.2 Dominios, en orden

Cada dominio porta backend, `app/api`, `lib` y su parte del starter.

1. **Auth**:
   - verificación de email (`email-links`, `email-verified`, tarjeta de estado);
   - correos de auth en el idioma del visitante;
   - teléfono como login y registro detrás de `auth.phone_login_enabled` (apagado por defecto);
   - fixes de sesión tras el login;
   - campo de contraseña unificado con confirmación.
2. **Pagos**:
   - pasarela activa y credenciales desde el Control Center (`payments/credentials`, caché en
     memoria del proceso, nunca en la Data Cache), con `PAYMENT_PROVIDER` del entorno solo
     como anulación;
   - sandbox deducido de las credenciales;
   - `checkout-options` (pasarela, efectivo y link de pago por WhatsApp como medios
     independientes);
   - Wompi y AvalPay/PlacetoPay de punta a punta, con firma, importe y moneda verificados en
     el webhook y confirmación servidor a servidor donde la pasarela la ofrezca.
3. **Checkout y carrito**:
   - compra como invitado configurable, reclamo del pedido (`guest-order-claim`, cookie) e
     invitación a iniciar sesión en «gracias»;
   - `order-access`, `return-url`, `customer-seed`, `cart-session`, `cart-stock`,
     `cart-destination`;
   - descarte de respuestas obsoletas;
   - lock contra doble envío con idempotency-key estable;
   - bloqueo del pedido si no hay tarifa;
   - «gracias» espera a la pasarela;
   - el pedido guarda el contexto de medición de la compra;
   - errores de Woo como texto plano.
4. **Cuenta**: volver a pedir, reseña del pedido (módulo `site-reviews`), `order-status`,
   `order-payable`, `order-support` y elegibilidad de reseñas.
5. **Analítica y consentimiento**:
   - `config.integrations` (GA4/GTM, Meta Pixel + Conversions API con `event_id` compartido,
     TikTok, HubSpot) y **Plausible** como opción del framework;
   - eventos con la forma de GA4 (`lib/analytics/ecommerce.ts`), retenidos hasta el
     consentimiento;
   - `consent-ledger` y `/api/consent` (módulo `consent-log`), separados de `consent-stats`;
   - el consentimiento se pide solo por lo configurado; se puede retirar; generación del
     consentimiento por versión;
   - hosts en la CSP por proveedor.
6. **SEO/GEO**:
   - `lib/seo/` completo: `jsonld`, `breadcrumbs`, `safe-json-ld`, `opengraph`,
     `meta-description`, `title`, `sitemap-segments`, `urls`, `og-static` (con su
     generador);
   - `robots.txt` con `Content-Signal` y separación de crawlers de IA (`geo.ai_crawlers`);
   - `/llms.txt` por idioma;
   - `noindex` en vistas privadas y filtradas, sin `alternates`.
   - `seo/legacy-*` (redirecciones heredadas) **no** se porta: es de instancia.
7. **Blog**: páginas de categoría (criterio único de disponibilidad), archivo completo, scroll
   infinito con cursor (`/api/blog/posts`, cacheable), tags y autor clicables cuando hay más de
   una entrada, y fecha de actualización.
8. **Precio y moneda**: `formatPrice` y `formatStoreAmount` exigen `currencyCode`
   (*breaking*); `useCurrency` en cliente y `getSiteConfig().ecommerce.currency` en servidor.
9. **Utilidades**: `http/fetch-retry`, `api/wc-message`, `navigation/safe-return`,
   `security/internal-secret`, `wordpress/media` + `CmsImage`, `youtube`,
   `validation/{email,form,checkout-guard}`, `prerender-budget` y filtrado de mensajes i18n
   por namespace.
10. **Infra y CD**:
    - servicio `wp-cron`;
    - Caddy: panel opcional en `admin.<dominio>`, estáticos servidos por Caddy y compresión;
    - tuning de MariaDB;
    - el build del frontend alcanza WordPress;
    - CD: caché de capas, sin construir el stage dev, sin 502 durante el deploy;
    - `warm-cache.sh`, `purge-old-build-pages.sh` y `static-generation-options`;
    - los parches de `node_modules` de Next (`patch-next-isr-headers`,
      `patch-next-image-lru`) **solo si siguen siendo necesarios** en la versión fijada en F0.
      En ese caso comprueban la versión y fallan ruidosamente si no coincide.

### 7.3 Starter neutro

Cada funcionalidad con interfaz recibe una UI mínima en las vistas y componentes heredados:
- clases de marca, sin estilo de Siu;
- textos en `es.json` y `en.json`;
- gateada por su flag o módulo;
- accesible (axe sin violaciones serias).

Las primitivas genéricas de `components/ui/` de Siu se portan si el starter las usa.

**Aceptación de F4, por dominio:**
- tests unitarios portados o escritos;
- e2e de Playwright con axe por cada vista nueva, contra el stub;
- `FRONTEND_CONNECT.md` actualizado con los endpoints y campos del dominio;
- grep anti-Siu = 0.

---

## 8. F5: adaptaciones del Nivel 2

### 8.1 Traducción de contenido a N idiomas (mu-plugin de núcleo `hwe-content-i18n`)

- Sustituye a `hwe-content-translation`, `hwe-slug-translation`,
  `hwe-category-translation`, `hwe-tag-translation` y `hwe-blog-category-translation` de Siu.
- **Idiomas.**
  - El idioma por defecto es el contenido nativo de WordPress.
  - Los secundarios se declaran en `frontend/src/i18n/routing.ts` y en el Control Center
    (`i18n.locales`, sembrado por `wp hwe setup` desde `instance.config.json`).
  - Si no coinciden, `instrumentation` lo registra como error al arrancar.
- **Almacenamiento.** Meta de post y de término `_hwe_<campo>_<locale>`, incluido
  `_hwe_slug_<locale>`. Las claves `_en` de Siu son compatibles sin migrar datos.
- **Registro** de campos traducibles (filtro `hwe_translatable_fields`) por tipo de contenido.
  El núcleo declara:
  - post: título, resumen, contenido, SEO y slug;
  - página: título, contenido y SEO;
  - producto: nombre, descripciones y slug;
  - término: nombre, descripción, SEO y slug.
- **GraphQL.**
  - `translation(locale: String!)` en cada tipo afectado, con los campos traducidos (pueden
    ser nulos).
  - `hweSlugFrom(postType, locale, slug)` para la búsqueda inversa.
- **Admin.** Caja «Traducciones» con una pestaña por idioma secundario, y campos por idioma en
  el editor de términos. Los plugins de higiene (§7.1) también actúan sobre estas metas.
- **Frontend.**
  - `pickLocalized` y la resolución de slugs se generalizan a N idiomas.
  - Las fichas aceptan el slug de cualquier idioma y redirigen con 308 al del idioma pedido.
  - **Sin traducción no se anuncia ese idioma**: no hay hreflang ni entrada de sitemap.
  - Un sitio monolingüe no paga ningún coste.

### 8.2 Paquetes por país (mu-plugin de núcleo `hwe-geo`)

- Sirve el paquete de `config.ecommerce.country`: divisiones administrativas, ciudades, tipos
  de documento, prefijo telefónico y formato de dirección.
- Lo expone en `/wp-json/hwe/v1/geo`. Lector server-only `getGeoPack()` con tag ISR `geo`; los
  formularios reciben el paquete por props.
- Incluye **CO** (desde `geo/colombia-*` y los datos de Siu) y **genérico** (texto libre, sin
  tipos de documento). Paquetes nuevos por filtro.
- Los `lib/geo/colombia-*` de Siu **no** se portan al frontend: sus datos pasan al paquete CO.

### 8.3 Módulos adaptados

- **`customer-identity`**:
  - tipos de documento del paquete de país;
  - documento único entre cuentas y de escritura única;
  - viaja en `extensions` de la Store API;
  - aparece enmascarado en los correos al cliente.
- **`shipping`**:
  - país base desde `config.ecommerce.country`;
  - zona de reserva configurable;
  - textos y comentarios neutros.
- **`product-landing`**: iconos por el filtro `hwe_product_landing_icons`, con un set neutro
  por defecto; secciones activables.
- **`landings`** (nuevo), con el motor extraído de `SiuEducationalLandings`:
  - **Backend:**
    - registro desde el panel (tipos `landing_registry` y `registry_type`);
    - slots fijos: hero desde el banner `landing-<slug>`, lead, hasta 5 secciones con layouts,
      cita, FAQ, productos y cierre;
    - URL con `base` (área o raíz) y `path`; publicación; SEO; traducciones a N idiomas (§8.1).
  - **Núcleo frontend** (`lib/landings/`):
    - registro combinado (entradas estáticas del `InstanceRegistry` más las del CMS);
    - `resolveSections` con `consumesBase` y el contador `placed`;
    - tonos de banda, `prose-blocks`;
    - `getEditorialHref`, segmentos de raíz reservados calculados desde `routing.pathnames`,
      `findEditorialByPath`.
  - Las secciones únicas y las entradas estáticas las aporta la instancia.
- **`external-integration`**, que generaliza Atlas:
  - rol `hwe_integration`;
  - Application Password válida solo desde `HWE_INTEGRATION_ALLOWED_IPS` y solo en GraphQL;
  - puerta por IP en `hwe-bff-only`;
  - política en el filtro `hwe_integration_policy`: operaciones raíz permitidas,
    restricciones de mutaciones y claves `metaData` visibles;
  - la política por defecto permite leer pedidos y cambiar su estado, y solo ve las claves de
    seguimiento de `hwe-order-tracking`;
  - `backend/scripts/verify-integration.sh` genérico.
- **`banners`**: se adopta la versión de Siu (filtros de tipos, ubicaciones, slides, ayudas de
  campo y guía de tamaños; `MANUAL.md`; página de ayuda).
  - `mandala_presets` pasa a `decor_presets` (*breaking*).
  - `Placements.php` del framework se retira en favor de `Locations`.

### 8.4 Anatomía de un módulo y su mitad de frontend

**Regla común.** Todo módulo del catálogo (§4.1), los de F4 incluidos, se entrega completo:
- **Plugin.** Plugin regular con `README.md` técnico y `MANUAL.md` para editores.
- **Revalidación.** Al cambiar sus datos dispara la revalidación ISR de su propio tag, que se
  registra en `hwe_revalidation_tags`.
- **Lector y rutas.** Lector server-only en el núcleo (`lib/<módulo>/`) y rutas BFF propias.
  Ambos comprueban `modules.<id>`: si el módulo está apagado, el lector devuelve vacío o nulo
  y las rutas responden 404.
- **UI.** Parte del starter neutro (§7.3).
- **Activación.** Por `modules` en `wp hwe setup`.

**Por módulo:**
- **`shipping`**:
  - `lib/shipping/` (destino, envío gratis, alcance del envío gratis, normalización) con sus
    tests;
  - rutas `/api/store/shipping`, `/api/store/shipping/destination` y
    `/api/store/free-shipping/scope`;
  - el destino usa el paquete de país (§8.2) para las ciudades;
  - el destino del cliente con sesión se fija fuera del checkout (`3fb1f330`);
  - el aviso de envío gratis en el carrito, el cajón y el checkout (`f41fec46`, `3bd1d7e6`,
    `24f09ca8`) va en el starter;
  - tag `shipping`.
- **`banners`**:
  - se reconcilia el `lib/banners/` actual del framework con el de Siu;
  - `localize` se generaliza a N idiomas (§8.1) y reemplaza el filtro de idioma secundario
    único;
  - `mandala-presets` pasa a `decor-presets`: un resolvedor en el núcleo cuyos presets aporta
    `InstanceRegistry`, con paridad comprobada por test frente a `decor_presets` del plugin;
  - tag `banners`.
- **`product-landing`**:
  - `getProductLanding` (nulo salvo publicada), `product-bound-landings` y
    `related-product-landings` (anillo round-robin con semilla fija) en `lib/landings/`;
  - `generateStaticParams` solo con las landings publicadas;
  - entrada en el sitemap;
  - el JSON-LD (`FAQPage` solo si hay consejos con encabezado) va en el núcleo SEO;
  - el starter trae una vista genérica de landing de producto;
  - tag `products`.
- **`landings`**: ver §8.3. El starter trae las dos rutas, la del área y la de raíz, delegando
  en una vista compartida.
- **`consent-log`, `site-reviews`, `coupon-rules`, `customer-identity`,
  `external-integration`**: cumplen la regla común con las piezas de frontend listadas en §7.2
  y §8.3.

### 8.5 Mecanismos declarativos del núcleo

- **`hwe-product-fields`**: registro (`hwe_product_fields`) con clave, etiqueta, tipo (los del
  §6.1 aplicables), si es traducible y en qué caja va.
  - Pinta las cajas, sanea al guardar y expone **`Product.hweFields(locale)`** como lista de
    pares clave–valor.
  - El frontend la tipa por *augmentation* desde `InstanceRegistry`.
  - El núcleo no declara campos propios.
- **`hwe-order-item-details`**: registro (`hwe_order_item_details`) de datos de extensión de
  `cart/add-item`, validados por un callback y guardados como meta visible de la línea (llega a
  correos, wp-admin y la API).

**Aceptación de F5:**
- tests PHPUnit de cada registro y filtro;
- tests Vitest de la resolución de idioma y slug con un fixture de **3 idiomas**, de
  `resolveSections` (varias secciones únicas con el mismo `after`) y de `findEditorialByPath`;
- el ejemplo vivo ampliado (campo de producto, detalle de línea, landing estática con una
  sección única);
- cada módulo, activado y desactivado: con `modules.<id> = false` sus rutas responden 404 y su
  UI no se pinta (test del BFF y e2e contra el stub);
- `README.md` y `MANUAL.md` presentes en cada plugin de módulo;
- `verify-*.sh` para las traducciones, el paquete de país y la política de integración.

---

## 9. F6: documentación y release

- **`docs/UPGRADE.md` v1→v2**, organizada por cada cambio *breaking*:
  - `instance.*` y el traslado de `Instance/` a `mu-plugins/instance/`;
  - `src/instance/` y `InstanceRegistry`;
  - `hwe_control_center_saved`;
  - los nombres GraphQL de las traducciones;
  - moneda obligatoria;
  - `bff-only` con secreto y la eliminación de `NEXT_PUBLIC_WORDPRESS_API_URL`;
  - los mu-plugins que pasan a módulo;
  - `decor_presets`;
  - campos de producto declarativos.
  - Incluye la sección **«Migrar Siu Tutuava»**, una tabla que asigna a cada pieza de Siu
    (Niveles 2 y 3) su destino en v2.
- **Actualizar** `FRONTEND_CONNECT.md`, `PLUGIN_DEVELOPMENT.md`, `CREATE_INSTANCE.md`
  (`modules`, `i18n.locales`, país), `CONFIGURATION.md`, `SECURITY.md`, `RUNBOOK.md`,
  `COMPATIBILITY.md`, `AGENTS.md`, `CLAUDE.md`, `README.md` y `CHANGELOG.md` (2.0.0).
- **Verificación de punta a punta** con la pila Docker sembrada:
  - compra completa con `E2E_FULL=1`;
  - todos los `verify-*.sh`;
  - `examples/minimal-consumer` contra la pila.
  - Lo que no se pueda verificar en vivo se declara en el CHANGELOG.

---

## 10. CI final

`lint` → `type-check` → `format:check` → `i18n:check` → `test` → `test:coverage` → `phpunit`
→ `build` → `npm audit`.

A eso se suman gitleaks, e2e con el stub, `docker compose config` (dev y prod), el job
«contrato» de instancia y el grep anti-Siu.

## 11. Riesgos

| Riesgo | Mitigación |
|---|---|
| Tamaño del trabajo | Fases que dejan el repo en verde y se revisan una a una |
| Supuestos de Siu ocultos en lo portado | Grep anti-Siu en CI, el ejemplo vivo y revisión por dominio |
| Parches de `node_modules` de Next obsoletos o frágiles | Solo si siguen siendo necesarios; comprueban la versión y fallan ruidosamente |
| `hwe-bff-only` con secreto rompe una llamada del BFF olvidada | Inventario de clientes HTTP del BFF a WordPress en F1 y `verify-security.sh` |
| Seguridad y pagos solo verificables con la pila | `verify-*.sh` y e2e `E2E_FULL`; lo no verificado se declara |
| Divergencia de idiomas entre `routing.ts` y el Control Center | Comprobación en el arranque con error en el log |

## 12. Pautas para el plan de implementación

- Sin código literal. Cada tarea indica: objetivo, archivos de Siu de origen (ruta y commit),
  destino en el framework, restricciones aplicables (§2 y `AGENTS.md`/`CLAUDE.md`) y
  verificación (tests que deben existir y comandos que deben pasar).
- El agente ejecutor tiene libertad técnica dentro de este spec.
- Orden de ejecución: F0 → F1 → F2 → F3 → F4 → F5 → F6.
- Cada fase termina con la batería completa de CI en verde y un commit (o varios) que citan el
  origen en Siu.
- `next build` es obligatorio antes de cerrar cualquier tarea de frontend.
