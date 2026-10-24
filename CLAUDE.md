# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Empieza por `AGENTS.md`.** Es el mapa mental completo del proyecto (arquitectura, dónde
> vive cada flujo, convenciones, gotchas). Este CLAUDE.md solo resume lo imprescindible y remite a
> las secciones (`§`) de `AGENTS.md` para el detalle. El idioma del proyecto (docs, comentarios,
> UI) es **español**; código y nombres técnicos en **inglés** — mantén esa convención.

## Qué es

E-commerce **headless** contenedorizado y comercializable como plantilla:

- **Backend** = WordPress + WooCommerce en modo headless (sin frontend nativo). Solo expone
  datos: **WPGraphQL/WooGraphQL para leer**, **WC REST para escribir**. `/wp-admin` sigue
  siendo el panel de gestión.
- **Frontend** = Next.js 16 (App Router, Turbopack) + React 19 + TS + Tailwind v4. Es la web
  pública **y** el **BFF**: toda operación sensible pasa por sus Route Handlers (`/api/*`),
  nunca del navegador directo a WordPress.
- **Infra** = Docker Compose (prod) o modo híbrido (dev: WP y frontend nativos, DB/Redis en Docker).

## Framework vs. instancia (léelo antes de tocar `frontend/`)

El framework es **backend + arquitectura**, no un generador de UI. En el mismo repo conviven
dos capas que **no debes confundir** (ver `AGENTS.md §1.1`):

- **Núcleo del framework** (se versiona/actualiza como unidad reutilizable): `backend/` (salvo las
  rutas de instancia), `frontend/src/app/api/*` (BFF), `frontend/src/lib/*` (toda la lógica),
  `frontend/src/proxy.ts`, `frontend/src/components/ui/*` (primitivas sin negocio) y los 11 módulos
  (`backend/wp-content/plugins/hwe-*`).
- **Responsabilidad de la instancia** — rutas **reservadas**, el framework nunca las toca:
  `backend/wp-content/mu-plugins/instance/`, plugins `hwe-instance-*` y `frontend/src/instance/*`.
  Además, `frontend/src/components/**` (salvo `ui/`) y **todas las vistas** de
  `frontend/src/app/[locale]/*`, que se heredan una vez al clonar como starter neutro.
- **El contrato de instancia:** el backend se engancha con **hooks** de nombre estable
  (`hwe_control_center_schema`, `hwe_control_center_saved`, `hwe_revalidation_tags`,
  `hwe_geo_packs`, `hwe_product_fields`…; `docs/PLUGIN_DEVELOPMENT.md §0`) y el frontend con
  `src/instance/registry.ts` (**solo datos**, único módulo de instancia que el núcleo importa).
  Una regla de ESLint impide que el núcleo importe `@/components/**` (salvo `ui/`) o
  `@/instance/**` (salvo `registry`). **Nada de una instancia concreta entra en el núcleo**: la
  puerta anti-instancia de CI (`.github/scripts/check-anti-instance.sh`) falla con cualquier
  resto (nombres, slugs, dominios, y también palabras comunes que contengan un token prohibido).
  El ejemplo vivo es `examples/instance-minimal/` (job «contrato» de CI).

El contrato estable entre backend/BFF y cualquier frontend está en `docs/FRONTEND_CONNECT.md`.

## Comandos (desde `frontend/`)

```bash
npm run dev            # desarrollo
npm run type-check     # tsc --noEmit
npm run lint           # ESLint flat config (next lint se eliminó en Next 16)
npm run i18n:check     # paridad de mensajes entre idiomas (es/en y los de la instancia)
npm run test           # Vitest: tests colocados junto al código + tests/unit
npm run audit:gate     # npm audit con allowlist (el gate de CI)
npm run test:watch     # Vitest en watch
npx vitest run tests/unit/csrf.test.ts   # un solo archivo de test
npx vitest run -t "<nombre>"             # un solo test por nombre
npm run test:coverage  # cobertura (umbrales en vitest.config.ts)
npm run format         # Prettier (src/ y tests/)
npm run format:check   # lo que corre CI — falla si hay archivos sin formatear
npm run e2e            # Playwright (smoke + axe) contra una app YA levantada (E2E_BASE_URL, def. :3000)
npx next build         # ⭐ la verificación definitiva antes de dar por terminado un cambio
```

Desde `backend/`: `composer test` (PHPUnit 12 + Brain Monkey). Contra la pila levantada:
`sh backend/scripts/verify-security.sh`, `verify-modules.sh` y `verify-integration.sh`.

- **Los tests unitarios viven colocados** junto al código (`src/**/*.test.ts(x)`; los de
  componentes en `jsdom`) y en `frontend/tests/unit/` (infraestructura y despliegue). `server-only`
  se neutraliza con un alias a `tests/stubs/server-only.ts`, así que los módulos de servidor se
  pueden testear directo. Cada fix de seguridad se prueba en rojo contra el código anterior.
- **CI** (`.github/workflows/ci.yml`) corre en este orden: `lint` → `type-check` →
  `format:check` → `i18n:check` → `test` → `test:coverage` → `build` → `npm audit`, más `phpunit`,
  la puerta anti-instancia, el job «contrato» (el ejemplo vivo superpuesto al repo), gitleaks,
  e2e contra un **stub** de WordPress (build + `next start`, con una segunda pasada sin módulos)
  y validación de `docker compose config` (dev y prod). Reproduce eso localmente antes de pushear.
- **`next build` es obligatorio antes de declarar terminado un cambio de frontend**: atrapa
  límites server/client, RSC, edge runtime y prerender por locale que `tsc`/`lint` no ven.
- **E2E de compra completa** (`tests/e2e/purchase.spec.ts`) es opt-in: requiere la pila Docker
  sembrada y `E2E_FULL=1`.
- Sé honesto sobre lo no verificado en vivo: login/carrito/checkout/webhooks necesitan la pila
  WP real levantada; si no la levantaste, dilo.

**Pila completa:** Docker puro (`docker compose up -d` + `docker compose run --rm wpcli` en el
primer arranque) o híbrido recomendado (`docker compose up -d db redis` + WP nativo con
`php -S 0.0.0.0:8080 -t <ruta-wp>` contra la DB en `127.0.0.1:3307` + `npm run dev`).
Pasos exactos en `README.md` y `docs/INSTALL.md`.

## Backend: qué es código propio

En `backend/wp-content/` solo se versiona lo nuestro (ver `.gitignore`): `mu-plugins/*`, el tema
vacío `themes/hwe-headless-base/` y los **11 módulos** `plugins/hwe-*` (`banners`, `shipping`,
`product-landing`, `landings`, `consent-log`, `site-reviews`, `coupon-rules`, `customer-identity`,
`external-integration`, `loyalty`, `memberships`). WooCommerce, WPGraphQL, JWT, etc. los instala `backend/scripts/setup.sh`
— **no edites plugins de terceros** en `plugins/`. Un módulo se enciende con la clave `modules` de
`instance.config.json` (`wp hwe setup` activa los listados y **desactiva los demás**); con él
apagado, su lector devuelve vacío, sus rutas BFF responden 404 (`requireModule`) y su UI no se
pinta. Tu código de instancia va en `mu-plugins/instance/` o en un plugin `hwe-instance-*`.

La config de negocio la gestiona el mu-plugin **HWE Control Center**: `Schema.php`
es la fuente única de todos los campos (los `public` salen por `/wp-json/hwe/v1/config`) y
`Cli.php` implementa `wp hwe setup <instance.config.json>` (ver `docs/CREATE_INSTANCE.md`).
Un campo de config nuevo se declara en `Schema.php` (los grupos del núcleo, por tema, en
`hwe-control-center/SchemaGroups/`) y se consume en el frontend vía `getSiteConfig()`,
con su valor por defecto en `lib/config/defaults.ts` (`CONFIG_DEFAULTS`, fallback si WP no responde).
Un campo **de una instancia** se declara bajo el grupo `instance` con `hwe_control_center_schema`.

## El principio que lo explica todo: el BFF

```
Navegador ──HTTPS──► Next.js (BFF, Route Handlers) ──red interna──► WordPress/WooCommerce
  cookies httpOnly      guarda secretos, valida,                      WPGraphQL (lectura)
  token CSRF            limita, autoriza                              WC REST + Store API (escritura)
  SIN secretos
```

Reglas de oro (no las rompas — `AGENTS.md §2, §7`):

1. **El navegador nunca ve secretos.** Credenciales WooCommerce (`ck/cs`), secreto JWT, secretos
   de webhook viven solo en el servidor. Si es secreto, **no** lleva prefijo `NEXT_PUBLIC_`.
2. **Todo lo que escribe o lee datos privados pasa por el BFF** (`app/api/*`), no por llamadas
   directas del cliente a WordPress.
3. Módulos de servidor llevan `import "server-only";` para que el bundler falle si se importan
   desde el cliente.
4. **La prueba de un pago es el webhook verificado**, nunca el redirect del cliente.

## Convenciones que rompen cosas si las ignoras

- **Escrituras** (`POST/PUT/PATCH/DELETE`) en `/api/*` pasan por `guardMutation` (Origin → CSRF
  → rate-limit, en `lib/api/guard.ts`). El cliente pide token a `/api/csrf` y lo reenvía en
  `X-CSRF-Token`. Si añades un endpoint de escritura, **úsalo**.
- **Navegación interna** desde `@/i18n/navigation` (Link/redirect/useRouter), **no** desde
  `next/*` (excepto `notFound`), o se pierde el idioma. Locales: `es` sin prefijo (canónico),
  `en` en `/en`.
- **Mensajes i18n paralelos**: una clave nueva en `es.json` va también en `en.json`.
- **Páginas estáticas bajo `[locale]`** llaman `setRequestLocale(locale)`; las dependientes del
  usuario (carrito/checkout/cuenta/auth) van `force-dynamic`.
- **La marca/URL del sitio SIEMPRE sale de `getSiteConfig()`** (`lib/config/index.ts`, fetch
  server-only a `/wp-json/hwe/v1/config`), nunca de una constante o env var leída en un componente.
- **Flags `config.ecommerce.*_enabled`** (reviews/wishlist/coupons/search) deben gatear la UI
  correspondiente, no solo el JSON-LD, **y las rutas del BFF** (`requireFlag`, 404). Igual con los
  módulos (`requireModule`).
- **Ningún fallo se traga en silencio**: lo que degrada se registra con un `event` estructurado
  (`logReadFailure`, `logRedisFailure`; `error_log('[hwe] …')` en WP). La seguridad falla
  cerrada; el contenido, de forma suave.
- **Idiomas**: `i18n.locales` del Control Center (secundarios) debe coincidir con
  `i18n/routing.ts`; el contenido del CMS se lee con `pickLocalized` / `translation(locale)` y
  cada idioma tiene su slug (308 al canónico). Cada idioma necesita su `messages/<idioma>.json`.
- **Tailwind v4 es CSS-first**: el tema vive en `globals.css` (`@theme`), no hay `tailwind.config`.
  Usa clases de marca (`brand`, `secondary`, `accent`, `surface`…), nunca hex hardcodeado.
- **Importes de la Store API vienen en unidades menores** (céntimos): formatea con
  `formatStoreAmount`. Los de WooGraphQL ya vienen formateados: usa `formatPrice`.
- **HTML del CMS** con `dangerouslySetInnerHTML` pasa por `sanitizeHtml` (`lib/security/sanitize.ts`).

## Gotchas de alto valor (más en `AGENTS.md §9`)

- **`export default nextConfig` es obligatorio** en `next.config.mjs`, o el plugin de next-intl
  no se aplica y todo render estático falla.
- **El middleware/`proxy.ts` corre en edge**: no puede abrir TCP a Redis. Por eso rate-limit e
  idempotencia viven en los Route Handlers (Node), no en el middleware. En Next 16 `middleware`
  se renombró a `proxy`.
- **WordPress solo atiende al BFF** (`hwe-bff-only`): toda llamada del BFF a WordPress lleva
  `X-HWE-Internal-Secret` (`HWE_REVALIDATION_SECRET`) vía `wpFetch` o los clientes de GraphQL/wc/Store
  API; un test rompe si una ruta usa `fetch` a WordPress por su cuenta.
- **Dos clientes de WooCommerce distintos**: `lib/woocommerce/client.ts` (WC REST `wc/v3` con
  `ck/cs`, para pedidos/cliente y autorización anti-IDOR) vs `store-client.ts` (Store API con
  `Cart-Token`, para carrito/checkout). No los confundas.
- **No dupliques emails transaccionales**: los envía WooCommerce nativamente al cambiar el estado
  del pedido. El dispatcher de `lib/woocommerce/order-events.ts` es para integraciones (ERP/Slack).
- **Pagos**: capa agnóstica en `lib/payments/`. Wompi y Aval Pay están de punta a punta (pasarela y
  credenciales desde el Control Center); `noop` es solo de pruebas y PayU/Bold son stubs (501). Añadir pasarela = implementar `PaymentProvider` sin tocar el checkout.
- **Secreto interno del BFF** para llamadas server-to-server (p. ej. `2fa-status`) es
  `HWE_REVALIDATION_SECRET`, en la cabecera `X-HWE-Internal-Secret`.
- **Banners** (módulo `banners`): los administra el plugin **HWE Banners**, no el
  Control Center. CPT `hwe_banner` → REST `hwe-banners/v1/banners` → lector core
  `lib/banners/getBanner(type, location, locale)` (ISR tag `banners`), gateado por el módulo
  `banners` y por `config.banners.enabled`.
  Celda = tipo + locación. Extensible por hooks (`hwe_banners_banner_types`, `hwe_banners_locations`,
  `hwe_banners_slide_types`, `hwe_banners_decor_presets`, `hwe_banners_slide_visible`, `hwe_banners_slide`).

## Documentación

`docs/` cubre instalación (`INSTALL.md`), crear instancia (`CREATE_INSTANCE.md`), configuración y
módulos (`CONFIGURATION.md`), el contrato frontend (`FRONTEND_CONNECT.md`), el contrato núcleo ↔
instancia y desarrollo de plugins (`PLUGIN_DEVELOPMENT.md`), construir el frontend de una instancia
(`FRONTEND_BUILD.md`), actualizar una instancia y migrar de v1 (`UPGRADE.md`), seguridad, operación
(`RUNBOOK.md`), go-live, observabilidad y matriz de compatibilidad. Cambios en `CHANGELOG.md`
(Keep a Changelog + SemVer).
