# Plan de Producción — Plantilla Headless WooCommerce 100% operativa y comercializable

> Documento de planificación. Define **qué falta**, **en qué orden**, **dónde va cada
> pieza** (rutas reales del repo) y **cuándo se considera terminado** (criterios de
> aceptación). No es código de producción: es la hoja de ruta para llegar a él.

- **Stack base ya existente:** Docker Compose (MariaDB + WordPress + Next.js), WordPress
  como Headless CMS vía WPGraphQL, Next.js 15 (App Router) + React 19 + TS + Tailwind v4.
- **Objetivo:** convertir el esqueleto actual en una **plantilla e-commerce headless
  vendible**: segura, con WooCommerce vía proxy inverso, autenticación JWT robusta,
  pagos, documentación y empaquetado para distribución comercial.

---

## 0. Estado actual vs. objetivo

| Área | Hoy | Objetivo |
|------|-----|----------|
| Contenido | Posts vía WPGraphQL + ISR | Catálogo WooCommerce (productos, categorías, stock) |
| Auth | JWT plugin instalado, sin flujo | Login/registro con JWT en cookies httpOnly + refresh |
| Secretos | Solo claves de WP/DB | Credenciales WooCommerce **nunca** en el navegador |
| API e-commerce | — | BFF / proxy inverso en Next.js Route Handlers |
| Seguridad | CORS + bloqueo frontend nativo | CSRF, rate-limit, CSP, validación, webhooks firmados |
| Pagos | — | Capa de pasarelas enchufable (Wompi/PayU/Bold/…) confirmada por webhook |
| Calidad | — | Tests, CI/CD, linting, observabilidad |
| Comercial | README de arranque | Licencia, docs, white-label, demo seed, versionado |

---

## 1. Arquitectura objetivo

```
┌──────────────┐        ┌───────────────────────────┐        ┌──────────────────┐
│  Navegador   │        │      Next.js (BFF)         │        │   WordPress +    │
│  (cliente)   │        │  App Router + Route Handl. │        │   WooCommerce    │
│              │        │                            │        │                  │
│  - Cookies   │ HTTPS  │  /app/api/*  (server-only) │ HTTPS  │  - WPGraphQL     │
│    httpOnly  │──────► │  - WC creds (server env)   │──────► │  - WC REST API   │
│  - CSRF tok  │        │  - JWT verify/refresh      │        │  - JWT plugin    │
│  - sin claves│ ◄──────│  - rate-limit + CSRF       │ ◄──────│  - webhooks      │
└──────────────┘        └───────────────────────────┘        └──────────────────┘
        ▲                          │
        │                          ▼
        │                   ┌──────────────┐
        └───────────────────│ Redis/Upstash│  (rate-limit, sesiones, idempotencia)
                            └──────────────┘
```

**Principio rector:** el navegador **nunca** habla directamente con la WooCommerce REST
API ni ve el `consumer_key`/`consumer_secret`. Toda escritura (carrito, checkout, cuenta)
pasa por el **BFF** de Next.js, que valida, limita y reenvía con las credenciales que solo
viven en el servidor.

### Lectura vs. escritura
- **Lectura pública** (catálogo, fichas, categorías): WPGraphQL + WooGraphQL con **ISR**.
  Cacheable, sin secretos.
- **Escritura / datos sensibles** (carrito, pedidos, cuenta, pagos): **WC REST API vía
  proxy inverso** en Route Handlers, autenticado y protegido.

---

## 2. Variables de entorno nuevas

Añadir a `.env.example` (raíz) y `frontend/.env.example`. **Sin prefijo `NEXT_PUBLIC_`**
para todo lo secreto (eso lo dejaría expuesto en el bundle del cliente).

```bash
# ---- WooCommerce REST API (SOLO servidor / BFF) ----
WC_API_URL=http://wordpress:80/wp-json/wc/v3   # interno Docker
WC_CONSUMER_KEY=ck_xxxxxxxxxxxxxxxxxxxxxxxx
WC_CONSUMER_SECRET=cs_xxxxxxxxxxxxxxxxxxxxxxxx

# ---- WooGraphQL (lectura de catálogo) ----
NEXT_PUBLIC_WORDPRESS_API_URL=https://cms.tu-dominio.com/graphql

# ---- Seguridad / sesión ----
SESSION_COOKIE_SECRET=<openssl rand -base64 64>   # firma de cookies
CSRF_SECRET=<openssl rand -base64 32>
JWT_REFRESH_COOKIE_NAME=hwe_rt
ALLOWED_ORIGIN=https://tienda.tu-dominio.com

# ---- Rate limit / cache (Upstash Redis o Redis propio) ----
REDIS_URL=redis://redis:6379
RATELIMIT_WINDOW_SECONDS=60
RATELIMIT_MAX_REQUESTS=60

# ---- Webhooks WooCommerce ----
WC_WEBHOOK_SECRET=<openssl rand -hex 32>

# ---- Pagos: capa de pasarelas agnóstica (ver Fase 7) ----
PAYMENT_PROVIDER=noop            # noop | wompi | payu | bold | …
PAYMENT_CURRENCY=COP
NEXT_PUBLIC_PAYMENT_RETURN_URL=https://tienda.tu-dominio.com/checkout/return
# Credenciales server-only por proveedor (rellenar SOLO al integrar uno):
# WOMPI_PRIVATE_KEY=  WOMPI_INTEGRITY_SECRET=  WOMPI_EVENTS_SECRET=
# PAYU_MERCHANT_ID=  PAYU_API_KEY=  PAYU_ACCOUNT_ID=
# BOLD_IDENTITY_KEY=  BOLD_SECRET_KEY=
```

> **Regla de oro de comercialización:** el repo distribuible solo contiene `.env.example`.
> Nunca claves reales. Documentar la generación de cada secreto.

---

## 3. Fases del plan

Cada fase es entregable de forma independiente y deja la plantilla en estado estable.

### Fase 1 — Endurecimiento de seguridad base ✅ COMPLETADA
**Objetivo:** cerrar superficie de ataque antes de añadir lógica de negocio.

- [x] **Cabeceras de seguridad** en `frontend/next.config.mjs` (`headers()`): CSP,
      `Strict-Transport-Security` (solo prod), `X-Frame-Options: DENY`,
      `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP/CORP,
      `poweredByHeader: false`.
- [x] **CORS de WPGraphQL con allowlist estricta**: `headless-config.php` ahora valida
      el `Origin` contra `HEADLESS_ALLOWED_ORIGINS` (lista por comas) y **deniega** los no
      autorizados (sin fallback permisivo); preflight no permitido → `403`.
- [x] **Bloqueo de endpoints WP innecesarios**: nuevo `mu-plugins/security.php` desactiva
      la REST API pública de usuarios (`/wp-json/wp/v2/users`), la enumeración por
      `?author=N`, pingbacks/trackbacks y generaliza errores de login. XML-RPC ya estaba.
- [x] **Cookies `Secure` + `SameSite` en producción**: helper central
      `frontend/src/lib/security/cookies.ts` (`server-only`) — base para Fases 2 y 4.
      HTTPS se fuerza vía HSTS + `upgrade-insecure-requests` (la terminación TLS/redirect
      la hace el proxy inverso o la plataforma de despliegue).
- [x] **Service Redis** añadido a `docker-compose.yml` (`redis:7-alpine`, healthcheck,
      volumen `redis_data`); el frontend depende de él y recibe `REDIS_URL`.

**Criterio de aceptación:** `securityheaders.com` ≥ A; WPGraphQL solo responde al origen
permitido; `/wp-json/wp/v2/users` devuelve 401. **Estado: implementado** (verificación de
cabeceras en vivo pendiente del primer despliegue con HTTPS).

---

### Fase 2 — Autenticación JWT (flujo completo) ✅ COMPLETADA
**Objetivo:** login/registro de clientes con tokens **fuera del alcance de JS del cliente**.

- [x] **Route Handlers de auth** (BFF):
  - `api/auth/login` → mutación `login`; fija `authToken` y `refreshToken` en
    **cookies httpOnly + Secure(prod) + SameSite=Lax**. Los tokens **no** se devuelven
    en el cuerpo.
  - `api/auth/refresh` → intercambia el refresh token por un nuevo `authToken`.
  - `api/auth/logout` → borra las cookies de sesión.
  - `api/auth/register` → `registerUser` + validación + auto-login.
  - `api/auth/me` → devuelve el `viewer` autenticado (estado de sesión sin exponer token).
- [x] **Verificación local de firma** con `jose` (`lib/auth/jwt.ts`): el frontend valida
      HS256 con el secreto compartido, sin llamar a WordPress en cada request.
- [x] **Helper de sesión server-side** `lib/auth/session.ts`: `getSession`/`getAuthToken`
      (memoizados con `cache()`), `requireSession` y `fetchGraphQLAsViewer` (inyecta el
      JWT de la cookie en queries autenticadas).
- [x] **Refresh transparente**: `middleware.ts` renueva el `authToken` caducado usando el
      refresh token y lo propaga al navegador **y al request en curso** (reescribe la
      cabecera Cookie para que el SSR vea el token fresco).
- [x] **Validación de entrada** con Zod (`lib/validation/auth.ts`) y **verificación de
      `Origin`** (`lib/security/origin.ts`) en todos los endpoints de escritura.
- [x] **Registro habilitado** en `setup.sh` (`users_can_register=1`, rol `subscriber`).

> **Decisión de seguridad:** **NO** se usa `localStorage` para tokens (vulnerable a XSS).
> Cookies `httpOnly` + verificación de `Origin` (CSRF completo en Fase 4). El JWT nunca
> llega al JS del cliente.

> **Limitación conocida (honesta):** el plugin WPGraphQL JWT emite un refresh token de
> larga duración **sin rotación nativa**; `refreshJwtAuthToken` solo devuelve un nuevo
> `authToken`. La invalidación global se hace rotando el *user secret* en WordPress
> (`graphql_jwt_auth_revoke_user_secret`). Acotamos la cookie del refresh a 30 días.

**Criterio de aceptación:** login fija cookies httpOnly; el token no es accesible desde
`document.cookie`; el JWT expirado se refresca solo; logout borra la sesión.
**Verificado:** `tsc --noEmit`, `next lint` y `next build` en verde (middleware + 5
route handlers + page ISR).

---

### Fase 3 — Proxy inverso a WooCommerce (BFF) ✅ COMPLETADA
**Objetivo:** todas las operaciones de comercio pasan por el servidor con credenciales
server-only. **Este es el núcleo del requisito #2.**

> **Decisión de arquitectura (realidad de la API de WooCommerce):** WooCommerce expone
> **dos** APIs y cada una cubre cosas distintas:
> - **`wc/v3` (REST clásica, auth `ck`/`cs`)** → recursos "administrativos": pedidos,
>   clientes. **Aquí viven las credenciales en el backend** y aquí aplica la autorización
>   por propietario (anti-IDOR). Es el centro del requisito #2.
> - **Store API (`wc/store/v1`, sin `ck`/`cs`, basada en *Cart-Token*)** → carrito y
>   checkout de invitado/cliente. Se proxia persistiendo el `Cart-Token` en una cookie
>   httpOnly. El nonce de la Store API se desactiva en WP porque el BFF ya impone
>   verificación de `Origin` (+ CSRF en Fase 4).

- [x] **Cliente `wc/v3` server-only** `frontend/src/lib/woocommerce/client.ts`:
  auth **Basic** (`ck`:`cs`), timeout con `AbortController`, reintentos solo en GET/5xx,
  y `WooCommerceError` normalizado. `import "server-only"`.
- [x] **Cliente Store API server-only** `frontend/src/lib/woocommerce/store-client.ts`:
  gestiona el `Cart-Token` (lo lee de la respuesta y lo devuelve para persistir en cookie).
- [x] **Route Handlers proxy** bajo `frontend/src/app/api/store/`:
  - `cart/route.ts` (GET carrito, DELETE vaciar) — Store API.
  - `cart/items/route.ts` (POST añadir, PATCH actualizar, DELETE quitar) — Store API.
  - `checkout/route.ts` (POST crear pedido) — Store API.
  - `orders/[id]/route.ts` (GET) — `wc/v3` + **autorización por propietario**.
  - `customer/route.ts` (GET/PUT) — `wc/v3` + **autorización** (solo el propio cliente).
- [x] **Capa de autorización**: `orders` y `customer` exigen sesión (Fase 2) y comparan el
      `customer_id` del recurso con el `userId` del JWT (un usuario no ve datos de otro).
- [x] **Validación de entrada** con **Zod** (`frontend/src/lib/validation/store.ts`).
- [x] **Verificación de `Origin`** en todas las escrituras (carrito/checkout/customer).
- [x] **Mapa de tipos** `frontend/src/types/woocommerce.ts`.
- [x] **Backend**: `setup.sh` instala y activa **WooCommerce**; mu-plugin
      `woocommerce-headless.php` desactiva el nonce de la Store API; script
      `generate-woo-keys.sh` genera el par `ck`/`cs` vía WP-CLI.

**Criterio de aceptación:** desde el navegador no aparece nunca `ck_`/`cs_` (las cookies
y el bundle no los contienen); un usuario no puede leer pedidos de otro (→ 403/404);
payloads inválidos → 422. **Verificado:** `tsc`, `next lint` y `next build` en verde.

---

### Fase 4 — CSRF, rate-limiting y validación ✅ COMPLETADA
**Objetivo:** proteger los endpoints de escritura del BFF (requisito #1).

- [x] **CSRF — signed double-submit cookie** (`lib/security/csrf.ts`): token firmado con
      HMAC-SHA256 (`CSRF_SECRET`) en cookie legible + exigido en cabecera `X-CSRF-Token`;
      comparación en tiempo constante. Endpoint `GET /api/csrf` lo emite.
- [x] **Rate limiting con Redis** (`lib/security/rate-limit.ts`, ventana fija INCR+EXPIRE):
      por IP (y opcionalmente por usuario). Umbrales: `login`/`register` 5/min,
      `checkout` 10/min, `customer` 20/min, `cart` 60/min. Respuesta `429` + `Retry-After`.
      **Fail-open** si Redis cae (mitigación, no barrera de integridad).
- [x] **Guard unificado** `lib/api/guard.ts` (Origin → CSRF → rate-limit) aplicado en
      todos los handlers de escritura (auth + store).
- [x] **Verificación de Origin centralizada** en `middleware.ts` para escrituras `/api/*`
      (barrera temprana edge-compatible; los handlers repiten como defensa en profundidad).
- [x] **Idempotencia en checkout** (`lib/security/idempotency.ts`, Redis `SET NX`): la
      cabecera `Idempotency-Key` evita pedidos duplicados (replay de respuesta / `409`).
- [x] **Sanitización HTML** (`lib/security/sanitize.ts`, `isomorphic-dompurify`):
      `sanitizeHtml()` (allowlist de tags) y `htmlToText()`, listos para renderizar HTML
      del CMS de forma segura en la Fase 5.

> **Nota de arquitectura (honesta):** el rate-limit y la idempotencia viven en los Route
> Handlers (Node), **no** en el middleware, porque el edge runtime de Next no admite
> conexiones TCP a Redis (ioredis). El middleware solo hace la verificación de Origin
> (edge-compatible). Para mover el rate-limit al edge se usaría Upstash Redis (REST).

**Criterio de aceptación:** mutación sin token CSRF válido → 403; superar el umbral →
429 con `Retry-After`; doble checkout con misma `Idempotency-Key` → un único pedido.
**Verificado:** `tsc`, `next lint` y `next build` en verde; el middleware (edge) no
empaqueta dependencias Node.

---

### Fase 5 — Funcionalidad e-commerce (catálogo + carrito + cuenta) ✅ COMPLETADA
**Objetivo:** experiencia de tienda completa sobre la base segura.

- [x] **WooGraphQL** en backend: `setup.sh` instala/activa `wp-graphql-woocommerce`.
- [x] **Catálogo (lectura, ISR)** — `lib/catalog/products.ts` + `lib/woocommerce/queries.ts`:
  - `app/products/page.tsx` — listado con **búsqueda** y **paginación** por cursor.
  - `app/products/[slug]/page.tsx` — ficha con `generateStaticParams` + ISR +
    `generateMetadata` + **JSON-LD** + descripción **saneada** (`sanitizeHtml`).
  - `app/categories/[slug]/page.tsx`.
- [x] **Carrito**: estado en servidor (Store API vía BFF) + UI cliente con
      `CartProvider`/`useCart`. Componentes: `ProductCard`, `AddToCartButton`,
      `CartView`, `CartIndicator`. Escrituras con CSRF automático.
- [x] **Checkout**: `app/checkout/page.tsx` + formulario con `Idempotency-Key`.
- [x] **Cuenta** (protegida por sesión, redirige a `/login`): `app/account/` con perfil
      editable (`ProfileForm` → `PUT /api/store/customer`) y lista de pedidos.
- [x] **Auth UI**: `app/login`, `app/register` con formularios CSRF-aware.
- [x] **Revalidación on-demand**: `app/api/revalidate/route.ts` (webhook WooCommerce) →
      `revalidateTag('products')`; el catálogo se etiqueta con `products`.
- [x] **Verificación de firma de webhooks** con `WC_WEBHOOK_SECRET` (HMAC-SHA256,
      `lib/security/webhook.ts`).

> **Nota:** se optó por **estado de carrito en cliente con revalidación** (no
> `useOptimistic`) por simplicidad y robustez; el `CartProvider` refleja siempre la
> respuesta real del servidor. El checkout demo usa pago **contra reembolso (`cod`)**;
> la pasarela real (capa de pasarelas agnóstica + webhook) es la Fase 7.

**Criterio de aceptación:** navegación de catálogo estática+ISR; añadir/editar/quitar del
carrito persiste; cambio de producto en WP refleja tras webhook. **Verificado:** `tsc`,
`next lint` y `next build` en verde (24 rutas; `/products/[slug]` como SSG).

---

### Fase 6 — SEO e internacionalización (i18n) ✅ COMPLETADA
**Objetivo:** mercados multilingües y buscadores desde la arquitectura, con textos
traducibles, **URLs localizadas indexables** y metadatos ricos, **sin sacrificar SSG/ISR**.

- [x] **next-intl con enrutado por prefijo** (`app/[locale]/`, `localePrefix: "as-needed"`):
  `es` sin prefijo (canónico), `en` bajo `/en`. `i18n/{routing,request,navigation}.ts`,
  mensajes `es.json`/`en.json` completos y paralelos, `NextIntlClientProvider` en el
  layout de locale, `setRequestLocale` en páginas estáticas.
- [x] **Navegación locale-aware** (`i18n/navigation.ts`: `Link`/`redirect`/`useRouter`) +
  `LocaleSwitcher`. Todos los `next/link`/`next/navigation` migrados.
- [x] **Migración de textos** en todas las páginas y componentes (header/footer/nav,
  catálogo, carrito, checkout, cuenta, auth, banner de consentimiento).
- [x] **Formateo locale-aware**: `formatDate(iso, locale)` y precios.
- [x] **Sitemap** (`sitemap.ts`): páginas + productos con URLs `as-needed` correctas y
  **hreflang** (es / en / `x-default`).
- [x] **robots.ts**: bloquea todo en dev; en prod permite e **excluye rutas privadas**
  (`/account`, `/cart`, `/checkout`, `/login`, `/register`, `/api`) y sus variantes `/en`.
- [x] **Metadatos**: OG + Twitter + `metadataBase` + `alternates.canonical`/`languages`
  por idioma en el layout; **JSON-LD** `WebSite` (layout) + `Product` (ficha).
- [x] **Analytics provider** + banner de consentimiento (opt-in) traducido.
- [x] **Manifest PWA** (`manifest.ts`).
- [x] **Config central** `config/site.ts` (marca/URL/social) — base de white-label.

> **Corrección clave durante la revisión:** la implementación inicial mezclaba
> `createMiddleware` (modo *con* enrutado) con un árbol plano sin `[locale]`, lo que
> **rompía el build** (`/_not-found`), y faltaba `export default nextConfig` (el plugin de
> next-intl nunca se aplicaba). Se migró a `app/[locale]/` con root layout *passthrough* +
> `not-found` global/por-locale, y middleware compuesto (next-intl + barrera de Origin +
> refresh JWT) que **no toca `/api` ni archivos**.

**Criterio de aceptación: cumplido y verificado en runtime** — `/`→es, `/en`→en,
`/es`→307→`/`, `sitemap.xml` con hreflang, `robots.txt` correcto, 404 localizado, barrera
de Origin activa (POST sin Origin → 403). `tsc`, `next lint` y `next build` (22 páginas
SSG es+en) en verde.

**Pendiente menor (no bloqueante):** el `AnalyticsProvider` gestiona el consentimiento
pero aún **no inyecta** el script real de GA4/Plausible (es un stub listo para cablear con
`NEXT_PUBLIC_GA_ID`).

---

### Fase 7 — Pagos: capa de pasarelas enchufable (provider-agnostic) ✅ COMPLETADA
**Objetivo:** dejar el sistema **preparado** para integrar cualquier pasarela
(Wompi, PayU, Bold, ePayco, Mercado Pago…) **sin acoplar el código a ninguna**. No se
integra ninguna pasarela en esta fase: se construye la **abstracción** y un proveedor de
ejemplo "no-op" para validar el contrato. Añadir una pasarela real debe ser un paso
**repetible y autocontenido** (implementar una interfaz + registrarla), sin tocar el flujo
de checkout ni los Route Handlers.

> **Por qué redirect/confirmación y no SDK de tarjeta:** las pasarelas LATAM
> (Wompi, PayU, Bold, ePayco, Mercado Pago) operan con **checkout alojado / widget +
> confirmación server-to-server firmada**. El cliente nunca maneja datos de tarjeta ni el
> resultado es prueba de pago: la verdad la da el **webhook verificado**. La abstracción se
> diseña alrededor de ese patrón (no de PaymentIntents tipo Stripe).

#### 7.1 Contrato común — `frontend/src/lib/payments/types.ts`
Estado normalizado y única interfaz que todo proveedor implementa:

```ts
export type PaymentStatus = "pending" | "approved" | "declined" | "voided" | "error";

export interface CreateCheckoutInput {
  reference: string;          // id del pedido WooCommerce
  amountMinor: number;        // importe en unidades menores (centavos)
  currency: string;           // ISO-4217 (COP, USD…)
  customer: { email: string; fullName: string; phone?: string };
  returnUrl: string;          // URL de retorno (solo UX, NO prueba de pago)
  metadata?: Record<string, string>;
}

export interface CreateCheckoutResult {
  mode: "redirect" | "widget";
  redirectUrl?: string;                 // checkout alojado
  widget?: Record<string, unknown>;     // config para widget embebido
  providerReference?: string;
}

export interface WebhookVerification {
  valid: boolean;                       // firma/integridad verificada
  reference: string;                    // id del pedido
  status: PaymentStatus;
  providerTransactionId?: string;
  amountMinor?: number;                 // para validar contra el pedido
  currency?: string;
}

export interface PaymentProvider {
  readonly id: string;                  // "wompi" | "payu" | "bold" | …
  createCheckout(input: CreateCheckoutInput): Promise<CreateCheckoutResult>;
  verifyWebhook(rawBody: string, headers: Headers): Promise<WebhookVerification>;
  mapStatus(providerStatus: string): PaymentStatus;
}
```

#### 7.2 Registro extensible — `frontend/src/lib/payments/registry.ts`
Patrón **repetible**: añadir una pasarela = crear `providers/<nombre>.ts`, implementar
`PaymentProvider` y registrarlo. Cero cambios en el checkout.

```ts
const registry = new Map<string, PaymentProvider>();
export function registerProvider(p: PaymentProvider) { registry.set(p.id, p); }
export function getProvider(id = process.env.PAYMENT_PROVIDER): PaymentProvider { … }
export function listProviders(): string[] { return [...registry.keys()]; }
```

- [x] **Estructura de archivos** (scaffolding, sin integrar pasarela):
  - `lib/payments/types.ts` — contrato anterior (+ `PaymentError`).
  - `lib/payments/registry.ts` — registro + selección por `PAYMENT_PROVIDER`
    (`registerProvider`/`getProvider`/`listProviders`/`activeProviderId`).
  - `lib/payments/signature.ts` — helpers HMAC/SHA compartidos (`hmacSha256`, `sha256`,
    `safeEqual` en tiempo constante).
  - `lib/payments/orders.ts` — máquina de estados del pedido (wc/v3): `getOrder`,
    `isOrderPaid`, `paymentMatchesOrder`, `markOrderPaid`, `markOrderCancelled`, `toMinorUnits`.
  - `lib/payments/providers/index.ts` — registra los proveedores disponibles.
  - `lib/payments/providers/noop.ts` — proveedor de ejemplo (sandbox) que implementa el
    contrato y permite probar el flujo de punta a punta sin cobrar (webhook firmado HMAC).
  - `lib/payments/providers/{wompi,payu,bold}.ts` — **plantillas-stub** documentadas con
    el TODO de cada API (sin credenciales ni llamadas reales; lanzan `501`).
- [x] **Endpoints BFF agnósticos** bajo `frontend/src/app/api/payments/`:
  - `create/route.ts` (POST) — exige sesión + guard (CSRF/rate-limit) + propiedad del
    pedido (anti-IDOR); obtiene el pedido, llama `getProvider().createCheckout(...)` y
    devuelve `redirectUrl`/`widget`. Si el pedido ya está pagado → `mode: "none"`.
  - `webhook/[provider]/route.ts` — **ruta recursiva**: un único handler atiende a
    cualquier proveedor registrado; resuelve `getProvider(params.provider)`,
    `verifyWebhook(rawBody, headers)` y, si `approved` **y** el importe/moneda coinciden con
    el pedido, marca el pedido WC como pagado (wc/v3 ck/cs). Idempotente. Sin CSRF/Origin
    (server-to-server, autenticado por firma).
  - `return/route.ts` (GET) — URL de retorno del usuario; solo muestra estado (sesión +
    propiedad), **nunca** confirma el pago. Página UX en `app/[locale]/checkout/return`.
- [x] **Máquina de estados del pedido** (`lib/payments/orders.ts`): `pending` →
      (`approved` por webhook) `processing` (`set_paid`); `declined`/`voided` → `cancelled`.
      La transición a pagado **solo** ocurre desde el webhook verificado e idempotente.
- [x] **Conciliación y anti-fraude**: el pedido se crea en WooCommerce **antes** del pago;
      el webhook valida **firma + importe + moneda + referencia** contra el pedido; el
      redirect del cliente nunca es prueba de pago; el webhook no revierte un pedido ya pagado.
- [x] **Config de proveedor activo** (env): `PAYMENT_PROVIDER`, `PAYMENT_CURRENCY`,
      `NEXT_PUBLIC_PAYMENT_RETURN_URL` y credenciales **server-only** por proveedor (ver
      Fase 7.3). El `CheckoutForm` llama a `createCheckout` (vía `/api/payments/create`) y
      redirige sin saber qué pasarela hay detrás; si no hay pasarela online aplicable, cae al
      flujo de pedido creado (contra reembolso).

#### 7.3 Variables de entorno (placeholders, sin valores)
```bash
# Pasarela activa y moneda
PAYMENT_PROVIDER=noop            # noop | wompi | payu | bold | …
PAYMENT_CURRENCY=COP
NEXT_PUBLIC_PAYMENT_RETURN_URL=https://tienda.tu-dominio.com/checkout/return

# Credenciales por proveedor (SOLO servidor; rellenar al integrar)
# WOMPI_PRIVATE_KEY=  WOMPI_INTEGRITY_SECRET=  WOMPI_EVENTS_SECRET=
#   NEXT_PUBLIC_WOMPI_PUBLIC_KEY=   (solo si se usa widget)
# PAYU_MERCHANT_ID=  PAYU_API_KEY=  PAYU_ACCOUNT_ID=
# BOLD_IDENTITY_KEY=  BOLD_SECRET_KEY=
```

#### 7.4 Cómo añadir una pasarela nueva (procedimiento repetible)
1. Crear `lib/payments/providers/<nombre>.ts` que implemente `PaymentProvider`
   (`createCheckout`, `verifyWebhook` con su firma de integridad, `mapStatus`).
2. Registrarlo en `providers/index.ts` con `registerProvider(new XxxProvider())`.
3. Añadir sus credenciales al `.env` y poner `PAYMENT_PROVIDER=<nombre>`.
4. Configurar en el panel de la pasarela la URL de webhook
   `https://…/api/payments/webhook/<nombre>`.
   → **Sin tocar** el checkout ni los endpoints: el resto del sistema es agnóstico.

**Criterio de aceptación (de esta fase):** existe la abstracción con el proveedor `noop`
funcionando de punta a punta en sandbox (crear pedido `pending` → `createCheckout` →
webhook simulado verificado → pedido pagado, idempotente); cambiar `PAYMENT_PROVIDER` no
requiere cambios de código; ninguna credencial real ni SDK de pasarela en el repo; `tsc`,
`next lint` y `next build` en verde.

**Estado: implementado.** `tsc`, `next lint` y `next build` en verde (aparecen las rutas
`/api/payments/create`, `/api/payments/webhook/[provider]`, `/api/payments/return` y la
página `/[locale]/checkout/return`). La abstracción no acopla el checkout a ninguna pasarela
y los stubs Wompi/PayU/Bold devuelven `501` hasta implementarse.

> **Pendiente de verificación en vivo (honesto):** el ciclo completo (pedido real
> `pending` → `createCheckout` → POST firmado a `/api/payments/webhook/noop` → pedido
> `processing`) requiere la pila Docker con WooCommerce y claves `ck/cs`. Para probarlo:
> firmar el cuerpo del evento con `hmacSha256(rawBody, NOOP_INTEGRITY_SECRET)` y enviarlo en
> la cabecera `x-noop-signature`. Sin la pila, lo verificado es tipos + lint + build.
> Además, asociar el pedido de la Store API al `customer_id` del usuario autenticado (para
> que `create`/`return` autoricen por propietario) depende de la configuración de WP.

---

### Fase 8 — Calidad, CI/CD y observabilidad
**Objetivo:** confianza para vender y mantener.

- [ ] **Tests**: unit (Vitest) para `lib/`, integración para Route Handlers, E2E
      (Playwright) del flujo compra. Carpeta `frontend/tests/`.
- [ ] **CI** (`.github/workflows/ci.yml`): lint + type-check + tests + build en cada PR.
- [ ] **Linting/formato**: ESLint (ya), Prettier, `lint-staged` + Husky pre-commit.
- [ ] **Healthchecks**: `frontend/src/app/api/health/route.ts` y healthcheck del servicio
      `frontend` en `docker-compose.yml`.
- [ ] **Observabilidad**: logging estructurado (pino), Sentry (errores), métricas básicas.
- [ ] **Dockerfile de producción** (multi-stage `standalone`) — ya esbozado en el
      `frontend/Dockerfile`, activarlo + `output: "standalone"` en `next.config.mjs`.
- [ ] **Backups DB** y estrategia de migración de `wp-content`.

**Criterio de aceptación:** pipeline verde obligatorio para merge; build de producción
funciona; errores se reportan a Sentry.

---

### Fase 9 — Empaquetado comercial (requisito #4)
**Objetivo:** que un comprador pueda instalarlo y personalizarlo sin fricción.

- [ ] **Licencia y términos**: `LICENSE` (p. ej. licencia comercial propia o MIT según
      modelo de negocio), `EULA`, política de uso/reventa.
- [ ] **Documentación de cliente** en `docs/`:
  - `INSTALL.md` (paso a paso, requisitos, troubleshooting).
  - `CONFIGURATION.md` (todas las variables de entorno explicadas).
  - `CUSTOMIZATION.md` (white-label: logo, colores vía `@theme`, tipografías).
  - `DEPLOYMENT.md` (Vercel/VPS + WordPress gestionado, dominios, HTTPS).
  - `SECURITY.md` (modelo de amenazas, responsabilidades del cliente).
- [ ] **White-label / theming**: centralizar marca en `frontend/src/config/site.ts`
      (nombre, logo, redes, legal) + tokens de color en `globals.css @theme`. Cero textos
      hardcodeados de "Headless Web Ecosystem" en el código.
- [ ] **Onboarding / seed**: script que carga **datos demo** (productos, categorías,
      imágenes de muestra) y un asistente de primer arranque. Ampliar `backend/scripts/`.
- [ ] **Páginas legales**: privacidad, cookies, términos, devoluciones (plantillas).
- [ ] **Accesibilidad**: auditoría WCAG 2.2 AA (componentes accesibles).
- [ ] **Versionado y changelog**: SemVer + `CHANGELOG.md`. Releases etiquetadas.
- [ ] **Soporte**: plantillas de issues, canal de soporte, política de actualizaciones.

**Criterio de aceptación:** un tercero instala desde cero siguiendo `INSTALL.md` en
< 30 min; rebranding completo sin tocar lógica; demo cargable con un comando.

---

## 4. Matriz de amenazas → mitigaciones

| Amenaza | Mitigación | Fase |
|---------|-----------|------|
| Robo de credenciales WooCommerce | Proxy inverso BFF, `server-only`, env sin `NEXT_PUBLIC_` | 3 |
| XSS roba el token de sesión | JWT en cookie `httpOnly`, CSP estricta, sanitización | 1,2,4 |
| CSRF en checkout/cuenta | Double-submit token + verificación de `Origin` | 4 |
| Fuerza bruta en login | Rate-limit estricto + bloqueo progresivo | 4 |
| Pedidos duplicados | Clave de idempotencia (Redis) | 4,7 |
| Pago falsificado desde el cliente | Confirmación solo por webhook firmado | 7 |
| Webhook falso | Verificación HMAC con secreto | 5,7 |
| Acceso a datos de otro usuario (IDOR) | Autorización por propietario en cada handler | 3 |
| Enumeración de usuarios WP | Bloqueo de `/wp-json/wp/v2/users` y autores | 1 |
| Fuga de secretos en el repo | Solo `.env.example`, secret scanning en CI | 0,7 |

---

## 5. Dependencias a añadir (frontend)

```jsonc
// Producción
"zod"                         // validación de entrada
"jose"                        // verificación/manejo de JWT en Edge/Node
"@upstash/ratelimit"          // o implementación propia con Redis
"@upstash/redis" | "ioredis"  // backend de rate-limit / cache
"isomorphic-dompurify"        // sanitización de HTML del CMS
// Pagos: SIN SDK por defecto — la capa es agnóstica (fetch + firmas HMAC).
//   Al integrar una pasarela, su SDK (si lo tiene) se añade en ESE provider.
"next-intl"                   // i18n (Fase 6)
"pino"                        // logging estructurado
"@sentry/nextjs"              // observabilidad de errores

// Desarrollo / calidad
"vitest" "@playwright/test" "prettier" "husky" "lint-staged"
```

Backend (vía `setup.sh`):
```
wp-graphql-woocommerce   # catálogo por GraphQL
(WooCommerce ya instalado y con claves API generadas)
```

---

## 6. Checklist "listo para vender"

- [ ] Sin secretos en el repositorio (solo `.env.example`); secret-scanning en CI.
- [ ] Credenciales WooCommerce jamás visibles en el navegador (verificado).
- [ ] Login/checkout protegidos con CSRF + rate-limit + validación.
- [ ] JWT en cookies httpOnly con refresh y rotación.
- [ ] Pago real verificado por webhook; sin duplicados.
- [ ] `securityheaders.com` ≥ A; HTTPS forzado.
- [ ] Tests verdes en CI; build de producción funcional.
- [ ] White-label completo (config central, cero marca hardcodeada).
- [ ] Datos demo cargables con un comando.
- [ ] Documentación de instalación, configuración, despliegue y seguridad.
- [ ] Licencia/EULA y páginas legales incluidas.
- [ ] i18n (ES/EN) y SEO (sitemap, robots, JSON-LD) operativos.
- [ ] Accesibilidad WCAG 2.2 AA revisada.
- [ ] SemVer + CHANGELOG + política de actualizaciones y soporte.

---

## 7. Orden recomendado y dependencias entre fases

```
Fase 0/1 (seguridad base + Redis)
        └─► Fase 2 (JWT)
                └─► Fase 3 (proxy WooCommerce BFF)   ◄── requisito #2
                        └─► Fase 4 (CSRF + rate-limit) ◄── requisito #1
                                └─► Fase 5 (e-commerce)
                                        └─► Fase 6 (SEO + i18n) — transversal
                                                └─► Fase 7 (pagos)
Fase 8 (calidad/CI) — transversal, empezar desde Fase 1
Fase 9 (comercial) — cierre, requiere 1–8 estables   ◄── requisito #4
```

> **Nota de alcance:** este documento es el plan. La implementación de cada fase debe ir
> en PRs independientes con sus criterios de aceptación verificados. Recomendado fijar la
> versión `1.0.0` (vendible) solo al completar la Fase 9 y el checklist del punto 6.
