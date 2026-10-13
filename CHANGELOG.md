# Changelog

Todas las novedades relevantes de esta plantilla se documentan aquí.
El formato sigue [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el
versionado sigue [SemVer](https://semver.org/lang/es/).

## [Sin publicar]

## [2.0.0] — 2026-10-13

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
  `hwe-category-translation`, `hwe-tag-translation`, `hwe-blog-category-translation`), sustituidos
  por `hwe-content-i18n`.
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

### Verificación y alcance
Verificado: lint, tipos, formato, paridad i18n, tests de frontend y de PHP, puerta anti-instancia y
`next build`; el e2e local contra el stub (con módulos encendidos y la pasada sin módulos);
`verify-modules.sh toggle` (9 módulos), `verify-security.sh` e integración externa contra la pila de
desarrollo en vivo; el job «contrato» reproducido en local; y `docker build --target prod`.

**No verificado en vivo en esta versión:**
- la compra completa (`E2E_FULL=1`) contra la pila sembrada;
- `verify-security.sh --strict` y `examples/minimal-consumer` contra la pila;
- la segunda pasada e2e y la CI completa en el servidor de CI;
- los webhooks de Wompi y Aval Pay contra el sandbox de cada pasarela (se prueban con firmas
  simuladas);
- la actualización de una instancia real de v1 siguiendo `docs/UPGRADE.md`.

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
