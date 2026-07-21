# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Empieza por `AGENTS.md`.** Es el mapa mental completo del proyecto (arquitectura, dónde
> vive cada flujo, convenciones, gotchas). Este CLAUDE.md solo resume lo esencial y remite a
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

- **Núcleo del framework** (se versiona/actualiza como unidad reutilizable): `backend/`,
  `frontend/src/app/api/*` (BFF), `frontend/src/lib/*` (toda la lógica), `frontend/src/components/ui/*`
  (primitivas sin negocio).
- **Responsabilidad de la instancia** (se hereda una vez al clonar, no se re-sincroniza):
  `frontend/src/components/**` (salvo `ui/`) y **todas las vistas** de `frontend/src/app/[locale]/*`.

El contrato estable entre backend/BFF y cualquier frontend está en `docs/FRONTEND_CONNECT.md`.

## Comandos (desde `frontend/`)

```bash
npm run dev            # desarrollo
npm run type-check     # tsc --noEmit
npm run lint           # ESLint flat config (next lint se eliminó en Next 16)
npm run test           # unit (Vitest): seguridad, auth, pagos, validación
npm run test:watch     # Vitest en watch
npx vitest run <ruta>  # un solo archivo de test (p. ej. src/lib/security/csrf.test.ts)
npm run test:coverage  # cobertura (umbrales en vitest.config.ts)
npm run e2e            # Playwright (smoke + axe)
npx next build         # ⭐ la verificación definitiva antes de dar por terminado un cambio
```

- **`next build` es obligatorio antes de declarar terminado un cambio de frontend**: atrapa
  límites server/client, RSC, edge runtime y prerender por locale que `tsc`/`lint` no ven.
- **E2E de compra completa** (`tests/e2e/purchase.spec.ts`) es opt-in: requiere la pila Docker
  sembrada y `E2E_FULL=1`.
- Sé honesto sobre lo no verificado en vivo: login/carrito/checkout/webhooks necesitan la pila
  WP real levantada; si no la levantaste, dilo.

**Pila completa:** Docker puro (`docker compose up -d` + `docker compose run --rm wpcli` en el
primer arranque) o híbrido recomendado (`docker compose up -d db redis` + WP nativo + `npm run dev`).
Pasos exactos en `README.md` y `docs/INSTALL.md`.

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
  correspondiente, no solo el JSON-LD.
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
- **Dos clientes de WooCommerce distintos**: `lib/woocommerce/client.ts` (WC REST `wc/v3` con
  `ck/cs`, para pedidos/cliente y autorización anti-IDOR) vs `store-client.ts` (Store API con
  `Cart-Token`, para carrito/checkout). No los confundas.
- **No dupliques emails transaccionales**: los envía WooCommerce nativamente al cambiar el estado
  del pedido. El dispatcher de `lib/woocommerce/order-events.ts` es para integraciones (ERP/Slack).
- **Pagos**: capa agnóstica en `lib/payments/`. Solo `noop` está implementado de extremo a extremo;
  Wompi/PayU/Bold son stubs. Añadir pasarela = implementar `PaymentProvider` sin tocar el checkout.
- **Secreto interno del BFF** para llamadas server-to-server (p. ej. `2fa-status`) es
  `HWE_REVALIDATION_SECRET`, en la cabecera `X-HWE-Internal-Secret`.
- **Banners**: los administra el plugin **HWE Banners** (regular, activable), no el
  Control Center. CPT `hwe_banner` → REST `hwe-banners/v1/banners` → lector core
  `lib/banners/getBannerPlacement()` (ISR tag `banners`), gateado por `config.banners.enabled`.
  Extensible por hooks (`hwe_banners_placements`, `hwe_banners_slide_visible`, `hwe_banners_slide`).

## Documentación

`docs/` cubre instalación (`INSTALL.md`), crear instancia (`CREATE_INSTANCE.md`), el contrato
frontend (`FRONTEND_CONNECT.md`), construir el frontend de una instancia (`FRONTEND_BUILD.md`),
seguridad, operación (`RUNBOOK.md`), go-live, observabilidad y matriz de compatibilidad. Cambios
en `CHANGELOG.md` (Keep a Changelog + SemVer).
