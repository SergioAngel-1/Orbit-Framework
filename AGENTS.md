# AGENTS.md — Guía de contexto para agentes

> Léeme antes de tocar nada. Este archivo te explica **qué es el proyecto, cómo está
> pensado y dónde mirar para cada cosa**. No es una referencia API exhaustiva: es el mapa
> mental para que entiendas el sistema rápido y trabajes sin romper sus principios.

---

## 1. Qué es esto (en 30 segundos)

Una **plantilla de e-commerce headless** lista para producción y comercializable:

- **Backend = WordPress + WooCommerce**, en modo **headless** (sin frontend nativo).
  Solo expone datos vía API (GraphQL para leer, REST para escribir). El panel `/wp-admin`
  sigue siendo la herramienta de gestión de contenido/pedidos.
- **Frontend = Next.js 16 (App Router, Turbopack) + React 19.2 + TypeScript + Tailwind v4.**
  Además de ser la web pública, actúa como **BFF (Backend-for-Frontend)**: todas las
  operaciones sensibles pasan por sus *Route Handlers* (`/api/...`), nunca directo del
  navegador a WordPress.
- **Infra = Docker Compose** (producción) o **híbrido** (desarrollo: WordPress y frontend nativos, DB/Redis en Docker).

El idioma del proyecto (comentarios, docs, UI) es **español**; el código y los nombres
técnicos están en inglés. Mantén esa convención.

---

## 2. El principio que lo explica casi todo: **el BFF**

```
Navegador  ──HTTPS──►  Next.js (BFF / Route Handlers)  ──red interna──►  WordPress/WooCommerce
  - cookies httpOnly       - guarda los secretos                          - WPGraphQL (lectura)
  - token CSRF             - valida, limita, autoriza                     - WC REST (escritura)
  - SIN secretos           - habla con WP por la red Docker               - WooGraphQL (catálogo)
```

**Reglas de oro (no las rompas):**
1. **El navegador nunca ve secretos.** Credenciales de WooCommerce (`ck/cs`), secreto JWT,
   secretos de webhooks, etc. viven **solo en el servidor**. Si una variable es secreta,
   **no** lleva el prefijo `NEXT_PUBLIC_`.
2. **Todo lo que escribe o lee datos privados pasa por el BFF** (`frontend/src/app/api/...`),
   no por llamadas directas del cliente a WordPress.
3. **Los módulos de servidor llevan `import "server-only";`** para que el bundler falle si
   alguien los importa desde el cliente por error.
4. **La prueba de un pago es el webhook verificado**, nunca el redirect del cliente
   (relevante en la fase de pagos).

---

## 3. Mapa del repositorio

```
/
├── docker-compose.yml          # dev: db + wordpress + redis + wpcli + frontend (todo en contenedores)
├── docker-compose.prod.yml     # prod: + caddy (TLS) + backup; sin puertos WP/Redis al host
├── Caddyfile                   # reverse proxy prod (TLS, HTTP/3); /graphql público restringido
├── .env.example / .env.prod.example  # TODAS las variables. Copiar a .env / .env.prod
├── README.md  AGENTS.md  CHANGELOG.md  LICENSE  EULA.md
├── docs/                        # documentación de cliente y operaciones
│   ├── INSTALL.md  CONFIGURATION.md  CUSTOMIZATION.md  DEPLOYMENT.md
│   ├── SECURITY.md  ACCESIBILIDAD.md
│   ├── RUNBOOK.md               # ⭐ operación: backup/restore, rotación de secretos, incidentes
│   ├── GO-LIVE.md               # ⭐ checklist "de cero a producción"
│   └── COMPATIBILITY.md         # matriz de versiones (WP/Woo/PHP/Node/Next…)
│
├── backend/                    # WordPress headless (solo nuestro código propio se versiona)
│   ├── scripts/setup.sh         # instala WP + plugins + WooCommerce + datos demo (WP-CLI)
│   ├── scripts/generate-woo-keys.sh  # genera las claves ck/cs de WooCommerce
│   ├── scripts/backup.sh  scripts/restore.sh  # ⭐ copia y restauración (DB + uploads)
│   ├── scripts/backup-entrypoint.sh  backup-cron.sh  seed-demo.sh
│   ├── config/uploads.ini
│   └── wp-content/mu-plugins/   # ⭐ comportamiento headless + endpoints propios (ver §6.2/§6.4)
│       ├── headless-config.php      # bloqueo del frontend nativo + CORS de GraphQL
│       ├── security.php             # hardening (enumeración de usuarios, pingbacks…)
│       ├── woocommerce-headless.php # ajustes Store API (nonce) + CORS
│       ├── woocommerce-email-branding.php # emails transaccionales con marca
│       ├── graphql-protection.php   # límites de profundidad/complejidad + introspección off
│       ├── rate-limit.php           # rate-limit de /graphql y /wp-json (XFF de confianza)
│       ├── hwe-auth.php             # ⭐ REST: reset pass, verificación email, 2FA, logout-all
│       └── hwe-control-center/      # plugin de config central + secretos cifrados (AES-GCM)
│
└── frontend/                   # Next.js (web pública + BFF)
    ├── next.config.mjs          # cabeceras de seguridad + CSP (incl. hosts analítica) + i18n
    ├── tests/                   # unit (Vitest) + e2e (Playwright: smoke, axe, purchase opt-in)
    ├── src/
    │   ├── instrumentation.ts   # arranque: guard de secretos + Sentry + log de inicio
    │   ├── proxy.ts             # ⭐ i18n + barrera de Origin + refresh JWT (ver §6). Next 16 renombró middleware→proxy
    │   ├── i18n/                # routing, request, navigation, messages (es/en)
    │   ├── config/site.ts       # marca/URL/social centralizadas (base white-label)
    │   ├── app/
    │   │   ├── [locale]/        # ⭐ TODAS las páginas viven bajo el segmento de idioma
    │   │   ├── api/             # ⭐ el BFF: auth (+2fa,+email,+logout-all), store, payments,
    │   │   │                    #    webhooks, csrf, revalidate, health (+/live)
    │   │   ├── sitemap.ts robots.ts manifest.ts not-found.tsx layout.tsx (passthrough)
    │   ├── components/          # UI (cart, products, auth, account, checkout, analytics, i18n)
    │   └── lib/                 # ⭐ toda la lógica de servidor/cliente (ver §6)
```

Cuando dudes de una ruta, **busca en `frontend/src/lib/`**: está organizado por dominio
(`auth/` —incl. `revocation.ts`—, `woocommerce/` —incl. `order-events.ts`—, `security/`
—`csrf`, `rate-limit`, `idempotency`, `lock`, `replay`, `secret-guard`, `webhook`, `origin`,
`cookies`, `sanitize`—, `catalog/`, `account/`, `payments/`, `config/`, `http/`, `redis/`,
`observability/`, `validation/`, `client/`).

---

## 4. Cómo está construido el frontend mental-mente

- **Lectura pública (catálogo, posts)** → GraphQL (WPGraphQL/WooGraphQL) con **ISR/SSG**.
  Cacheable, sin secretos, etiquetado con `tags: ["products"]` para revalidar por webhook.
- **Escritura / datos privados (carrito, checkout, pedidos, cuenta, auth)** → **BFF**:
  Route Handlers que validan (Zod), protegen (CSRF + rate-limit + Origin), autorizan
  (sesión + propiedad del recurso) y reenvían a WooCommerce con los secretos del servidor.
- **Cliente GraphQL** (`lib/graphql-client.ts`) elige endpoint según dónde corre: en el
  **servidor** usa la red interna de Docker (`http://wordpress`) o local (`http://localhost:8080`),
  en el **navegador** la URL pública. Mismo patrón en los clientes de WooCommerce.

---

## 5. Estado del proyecto

> Actualizado: **2026-06-18**. Estados realistas (✅ completo · 🟡 parcial · 🧪 stub).

| Área | Estado |
|------|--------|
| Seguridad base (cabeceras, CORS, hardening, Redis) | ✅ |
| Autenticación JWT (cookies httpOnly + refresh) | ✅ |
| Proxy inverso a WooCommerce (BFF, ck/cs server-only) | ✅ |
| CSRF + rate-limit + idempotencia | ✅ |
| E-commerce (catálogo, carrito, **checkout ligado a cliente**, cuenta) | ✅ |
| Cuenta avanzada (wishlist, reseñas, direcciones — **meta atómica con lock**) | ✅ |
| Cupones y envío (Store API) | ✅ |
| Verificación de email (**opcional**, con expiración de token) | 🟡 (no bloqueante) |
| 2FA TOTP (**secreto cifrado AES-GCM + códigos de recuperación**) | ✅ |
| SEO + i18n (next-intl, sitemap, hreflang, JSON-LD) | ✅ |
| Analítica + consentimiento (Plausible/GA4 con opt-in + eventos e-commerce) | ✅ |
| Pagos: capa agnóstica + `noop` funcional | ✅ |
| Pagos: pasarelas reales (Wompi/PayU/Bold) | 🧪 stubs (pendiente integrar 1 real) |
| Webhooks de pedido (transición real + dispatcher de efectos) | 🟡 (email al cliente lo hace Woo; ganchos ERP por conectar) |
| HWE Control Center (config central + secretos cifrados) | ✅ |
| Calidad / CI/CD (unit de seguridad/auth + e2e de compra opt-in) | ✅ |
| Observabilidad (Sentry + pino + correlación request-id + alertas) | ✅ (métricas OTel pendientes) |
| Empaquetado comercial (licencia, docs, white-label) | ✅ |

El núcleo está **listo para producción**. Lo pendiente es endurecimiento adicional,
una pasarela real, cobertura de tests e2e con la pila WP, y mejoras puntuales descritas
en el plan. El flujo crítico de compra (login → carrito → checkout → pago → pedido en
cuenta) está **íntegro** tras ligar el pedido al cliente autenticado.

---

## 6. Dónde revisar cada flujo

### 6.1 Arranque e infraestructura
- `docker-compose.yml` — servicios y variables. `README.md` — pasos exactos.
- Primer arranque: `docker compose up -d` → `docker compose run --rm wpcli` (instala todo)
  → `generate-woo-keys.sh` (claves WC) → pegar en `.env` → `docker compose up -d frontend`.
- **Producción**: `docker-compose.prod.yml` + `Caddyfile` (TLS, HTTP/3). Servicios con
  `security_opt: no-new-privileges`. GraphQL público restringido a POST/OPTIONS en Caddy.
- **Backups/restore**: `backend/scripts/backup.sh` (+ contenedor `backup` con cron) y
  `backend/scripts/restore.sh`. Procedimientos y simulacro en **`docs/RUNBOOK.md`**.
- **Sondas**: liveness `GET /api/health/live`; readiness `GET /api/health?ready=1` (503 si
  alguna dependencia está caída). `GET /api/health` (sin flag) siempre 200 con detalle.

### 6.2 Comportamiento "headless" de WordPress
- `backend/wp-content/mu-plugins/headless-config.php` — bloquea el frontend nativo (redirige
  a `/wp-admin`), aplica **CORS al endpoint `/graphql`** con allowlist de orígenes.
- `backend/wp-content/mu-plugins/security.php` — bloquea enumeración de usuarios, pingbacks.
- `backend/wp-content/mu-plugins/woocommerce-headless.php` — desactiva el nonce de la Store
  API (seguro porque el BFF ya impone Origin + CSRF).
- Plugins/contenido se instalan vía `backend/scripts/setup.sh`.

### 6.3 Seguridad del frontend
- **Cabeceras + CSP**: `frontend/next.config.mjs` (función `headers()`).
- **Barrera de Origin** para escrituras `/api/*`: `frontend/src/proxy.ts` (función `proxy`).
- **CSRF** (signed double-submit): `lib/security/csrf.ts`; el cliente obtiene el token en
  `app/api/csrf/route.ts` y lo reenvía en `X-CSRF-Token`.
- **Rate-limit** (Redis, ventana fija): `lib/security/rate-limit.ts` + `lib/redis/client.ts`.
  Fail-open por defecto; con `strict: true` usa un **fallback en memoria** si Redis cae
  (se aplica en auth: login/registro/2FA/forgot/reset).
- **Idempotencia** (checkout): `lib/security/idempotency.ts`.
- **Guard unificado** (Origin → CSRF → rate-limit) aplicado en cada escritura:
  `lib/api/guard.ts`. Si añades un endpoint de escritura, **úsalo**.
- **Guard de arranque**: `lib/security/secret-guard.ts` (llamado desde `instrumentation.ts`)
  **aborta el boot en producción** si los secretos siguen con valores por defecto o son cortos.
- **IP del cliente anti-spoofing**: `lib/http/request-ip.ts` usa `TRUSTED_PROXY_COUNT`
  (por defecto 1) para no fiarse del primer valor (falsificable) de `X-Forwarded-For`.
  Equivalente en WP: constante `HWE_TRUSTED_PROXY_COUNT` en `rate-limit.php`.
- **Revocación de sesión**: `lib/auth/revocation.ts` (blocklist en Redis). `logout` revoca el
  access token; `getSession` lo comprueba. `app/api/auth/logout-all` rota el secreto JWT del
  usuario en WP (invalida refresh tokens). Fail-open si Redis no responde.
- **Anti-replay de webhooks**: `lib/security/replay.ts` (`markEventOnce`) en los webhooks de
  pago y de Woo (`order-created/updated`), más rate-limit por IP. Defensa frente a reenvío de
  eventos firmados capturados.
- **Secretos del Control Center**: cifrados con **AES-256-GCM** y clave dedicada
  `HWE_SECRETS_KEY` (desacoplada del JWT); lectura compatible con el formato legacy CBC.

### 6.4 Autenticación (JWT)
- Endpoints BFF: `app/api/auth/{login,register,refresh,logout,me}/route.ts`.
- Tokens en **cookies httpOnly** (nunca en `localStorage` ni en el body).
  Opciones de cookie centralizadas en `lib/security/cookies.ts`.
- Verificación local de firma con `jose`: `lib/auth/jwt.ts`.
- Sesión en Server Components: `lib/auth/session.ts` (`getSession`, `requireSession`,
  `fetchGraphQLAsViewer`).
- **Refresh transparente**: lo hace `proxy.ts` (renueva el token caducado y lo propaga
  al request actual). Operaciones GraphQL de auth en `lib/auth/mutations.ts`.
- **Endpoints WP propios** (mu-plugin `backend/.../hwe-auth.php`, namespace `hwe/v1`):
  recuperación/reseteo de contraseña, verificación de email y 2FA. El BFF se autentica
  ante ellos con `Authorization: Bearer <authToken>`.

#### 6.4.1 Verificación de email (opcional, no bloqueante)
- Política **opcional**: se dispara el email tras el registro (`register/route.ts` →
  WP `send-verification`, best-effort) pero **no** bloquea compra ni login.
- Token con **expiración (24 h)** y timestamp en WP. UI: `app/[locale]/verify-email`,
  reenvío en `resend-verification`. Estado expuesto en `me` (`email_verified`).
- Para hacerla obligatoria en el futuro: bloquear checkout/zona privada si `!email_verified`.

#### 6.4.2 2FA (TOTP)
- Flujo: `2fa/setup` (genera secreto) → `2fa/activate` (verifica código y **devuelve los
  códigos de recuperación UNA vez**) → en login, si está activo, `login` responde
  `requires_2fa` + token efímero (5 min) y el cliente completa en `verify-2fa`.
- El **secreto TOTP se cifra en reposo** en WP con **AES-256-GCM** (`hwe-auth.php`,
  clave dedicada `HWE_2FA_KEY` con fallback a `AUTH_KEY`). Verificación TOTP con `otplib` v13.
- **Códigos de recuperación**: 10 por usuario, guardados **hasheados**; se consumen de uno
  en uno (`2fa-recovery/verify`). Sirven en `verify-2fa` (campo `recoveryCode`).
- **`2fa-status` no es público**: requiere el secreto interno del BFF
  (`X-HWE-Internal-Secret` = `HWE_REVALIDATION_SECRET`) o que el usuario consulte su propio id.
- **Desactivar exige re-verificación** (código TOTP o de recuperación), no solo sesión.

### 6.5 WooCommerce (catálogo, carrito, pedidos, cuenta)
- **Dos APIs, dos clientes** (clave para no confundirse):
  - `lib/woocommerce/client.ts` → **WC REST `wc/v3`** con `ck/cs`. Para **pedidos y cliente**
    (datos administrativos). Aquí va la **autorización por propietario** (anti-IDOR).
  - `lib/woocommerce/store-client.ts` → **Store API `wc/store/v1`** (sin ck/cs, con
    `Cart-Token`). Para **carrito y checkout**. El token se guarda en cookie httpOnly
    (`lib/woocommerce/cart-cookie.ts`).
- Endpoints BFF: `app/api/store/{cart, cart/items, checkout, customer, orders/[id]}/route.ts`.
- Catálogo (lectura, GraphQL + ISR): consultas en `lib/woocommerce/queries.ts`, datos en
  `lib/catalog/products.ts`, páginas en `app/[locale]/products` y `app/[locale]/categories`.
- Datos de cuenta server-side: `lib/account/data.ts`.
- UI de tienda: `components/products/`, `components/cart/` (estado global en
  `cart-context.tsx`), `components/checkout/`, `components/account/`.
- Cliente del carrito en el navegador: `lib/client/store-api.ts` (+ `lib/client/csrf.ts`).
- **Pedido ligado al cliente (anti-pedido-invitado):** el checkout reenvía el JWT a la
  Store API (`storeFetch({ authToken })`) y, como salvaguarda idempotente, `ensureOrderOwner()`
  fija `customer_id` vía wc/v3 tras crear el pedido. Sin esto el comprador no podría pagar/ver
  su pedido (las comprobaciones anti-IDOR exigen `order.customer_id === session.userId`).
- **Cuenta avanzada:** wishlist, reseñas (`reviews/[productId]`), direcciones, cupones y envío.
  Wishlist y direcciones guardan en `customer.meta_data`; sus secciones de
  lectura-modificación-escritura van **serializadas por usuario** con `lib/security/lock.ts`
  (lock Redis `SET NX`, fail-open) para evitar lost-update concurrente.

### 6.6 Revalidación on-demand (catálogo siempre fresco)
- WooCommerce envía un webhook a `app/api/revalidate/route.ts`, que **verifica la firma
  HMAC** (`lib/security/webhook.ts`) y hace `revalidateTag("products")`.

### 6.6.1 Webhooks de pedido y efectos
- `app/api/webhooks/woocommerce/order-{created,updated}/route.ts` verifican la firma de Woo y
  delegan en `lib/woocommerce/order-events.ts`.
- **Transición real**: `recordAndDiffStatus()` persiste el último estado en Redis para calcular
  el cambio (el payload de Woo solo trae el estado actual).
- `dispatchOrderEffects()` centraliza los efectos. **Los emails AL CLIENTE los envía
  WooCommerce de forma nativa** (no los dupliques aquí). Este dispatcher es para integraciones
  operativas (ERP/Slack/cola) vía `ORDER_NOTIFICATION_WEBHOOK_URL` (opcional).

### 6.7 SEO + i18n
- **Routing por idioma**: todo bajo `app/[locale]/`. `es` sin prefijo (canónico), `en` en
  `/en`. Config en `i18n/routing.ts`; locale resuelto en `i18n/request.ts`.
- **Navegación locale-aware**: usa SIEMPRE `Link`/`redirect`/`useRouter` de
  `i18n/navigation.ts` (no los de `next/*`), o se pierde el idioma al navegar.
- **Textos**: `i18n/messages/es.json` y `en.json` (mantenlos paralelos). Selector:
  `components/i18n/locale-switcher.tsx`.
- **SEO**: `app/sitemap.ts` (URLs + hreflang), `app/robots.ts`, `app/manifest.ts`,
  metadatos + JSON-LD en `app/[locale]/layout.tsx` y en la ficha de producto.
- **Analítica/consentimiento**: `components/analytics/` (banner opt-in). El script se carga
  **solo tras consentimiento**; soporta **Plausible** (recomendado) y **GA4** según
  `NEXT_PUBLIC_ANALYTICS_PROVIDER`. Eventos e-commerce con `trackEvent()`
  (`add_to_cart` en `cart-context`, `purchase` en `checkout/return` vía `PurchaseTracker`).
  Los hosts del proveedor se autorizan en la CSP de `next.config.mjs` (`getAnalyticsHosts`).

### 6.8 Pagos (Fase 7 — capa de pasarelas agnóstica)
- **Contrato e implementación** en `lib/payments/`: `types.ts` (`PaymentProvider`,
  `PaymentError`), `registry.ts` (registro + selección por `PAYMENT_PROVIDER`),
  `signature.ts` (HMAC/SHA + `safeEqual`), `orders.ts` (máquina de estados del pedido vía
  wc/v3) y `providers/` (`noop` funcional + stubs `wompi`/`payu`/`bold`).
- **Endpoints BFF** en `app/api/payments/`: `create` (POST, sesión + guard + propiedad →
  `createCheckout`), `webhook/[provider]` (server-to-server, **sin CSRF/Origin**, valida
  firma + importe + moneda → marca pagado, idempotente) y `return` (GET, solo estado). La
  página UX de retorno está en `app/[locale]/checkout/return`.
- **Regla de oro:** la prueba de pago es **el webhook verificado**, nunca el redirect. El
  pedido se crea en WooCommerce *antes* del pago (estado `pending`) y solo pasa a
  `processing` desde el webhook. El `CheckoutForm` consume `createCheckout` sin saber qué
  pasarela hay detrás.
- **Añadir una pasarela** (Wompi/PayU/Bold…) = implementar `PaymentProvider` en
  `providers/<nombre>.ts`, registrarlo en `providers/index.ts`, poner sus credenciales
  server-only y `PAYMENT_PROVIDER=<nombre>`. **Sin tocar el checkout.**

---

## 7. Convenciones que NO debes romper

- **Secretos sin `NEXT_PUBLIC_`** y en módulos `server-only`. El navegador no ve `ck/cs`,
  ni el secreto JWT, ni secretos de webhook.
- **Escrituras** (`POST/PUT/PATCH/DELETE`) en `/api/*`: pasan por `guardMutation`
  (Origin + CSRF + rate-limit). El cliente primero pide token a `/api/csrf`.
- **Enlaces y redirecciones internas**: desde `@/i18n/navigation`, no desde `next/link` /
  `next/navigation` (excepto `notFound`, que se queda en `next/navigation`).
- **Páginas estáticas bajo `[locale]`** deben llamar `setRequestLocale(locale)`; las páginas
  que dependen del usuario (carrito, checkout, cuenta, auth) van `force-dynamic`.
- **Mensajes i18n paralelos**: si añades una clave en `es.json`, añádela en `en.json`.
- **Importes de la Store API** vienen en **unidades menores** (céntimos): formatéalos con
  `formatStoreAmount` de `lib/format.ts`. Los precios de WooGraphQL vienen ya formateados:
  usa `formatPrice`.
- **HTML del CMS** que se inyecte con `dangerouslySetInnerHTML` debe pasar por
  `sanitizeHtml` (`lib/security/sanitize.ts`).
- **Tailwind v4 es CSS-first**: el tema vive en `globals.css` (`@theme`), no hay
  `tailwind.config`. Colores de marca: `brand`, `brand-dark`, `brand-light`.

---

## 8. Cómo correr y verificar

Desde `frontend/`:
```bash
npm run dev          # desarrollo
npx tsc --noEmit     # tipos
npm run lint         # ESLint (flat config; `next lint` se eliminó en Next 16)
npm run test         # tests unitarios (Vitest): seguridad, auth, pagos, validación
npm run test:coverage# cobertura (umbrales en vitest.config.ts)
npx next build       # ⭐ la verificación definitiva (prerender + tipos + middleware edge)
```

**Pila completa — dos modos:**

| Modo | Comando | Descripción |
|------|---------|-------------|
| **Docker puro** | `docker compose up -d` + `wpcli` | Todo en contenedores (más lento en Windows) |
| **Híbrido** (recom.) | `docker compose up -d db redis` + `start-local.bat` + `npm run dev` | WordPress y frontend nativos; DB/Redis en Docker |

Para desarrollo pesado, el **modo híbrido** evita la latencia de bind mounts Docker
(WordPress nativo con `php -S 0.0.0.0:8080`, DB en puerto `3307`, frontend con `npm run dev`).
Ver `docs/INSTALL.md` y `README.md` para instrucciones detalladas.

E2E (Playwright): `npx playwright test` (smoke + accesibilidad axe). El e2e de **compra
completa** (`tests/e2e/purchase.spec.ts`) es opt-in: requiere la pila sembrada y
`E2E_FULL=1` (opcionalmente `E2E_PRODUCT_ID`).

**Antes de dar por terminado un cambio en el frontend, corre `next build`.** Atrapa la
mayoría de problemas (límites server/client, RSC, edge runtime, prerender por locale).

---

## 9. Decisiones y "gotchas" que te ahorrarán tiempo

- **`export default nextConfig` es obligatorio** en `next.config.mjs`: sin él, el plugin de
  next-intl no se aplica y todo render estático falla con "Couldn't find next-intl config".
- **Root layout passthrough**: `app/layout.tsx` solo devuelve `children`; el `<html>` real
  está en `app/[locale]/layout.tsx`. Necesario para el `not-found` global.
- **El middleware corre en edge**: no puede abrir TCP a Redis. Por eso **rate-limit e
  idempotencia están en los Route Handlers (Node)**, no en el middleware. El middleware solo
  hace cosas edge-compatibles (Origin, locale, refresh JWT vía fetch).
- **CSP usa `'unsafe-inline'` en scripts** a propósito: una CSP con nonce obligaría a render
  dinámico y rompería el ISR/SSG. Documentado como endurecimiento opcional (Fase 8).
- **Rate-limit es fail-open por defecto**, pero los endpoints de auth usan `strict: true`,
  que degrada a un **contador en memoria** si Redis cae (no se queda sin límite).
- **Sesión revocable**: `logout` revoca el access token (blocklist en `lib/auth/revocation.ts`,
  consultada en `getSession`); `/api/auth/logout-all` rota el "user secret" en WP e invalida los
  refresh tokens. El refresh **no rota en cada uso** (limitación del plugin WPGraphQL JWT).
- **`@tailwindcss/typography` YA está instalado** (en `devDependencies`): `prose` está
  disponible para las descripciones de producto. *(Corrige una nota antigua que decía lo contrario.)*
- **Sentry YA está cableado**: `sentry.{client,server,edge}.config.ts` + `src/instrumentation.ts`.
  El logger usa `pino` con eventos estructurados.
- **Stripe/PayPal NO se usan**: la capa de pagos es agnóstica para pasarelas LATAM; solo
  `noop` está implementado de extremo a extremo (las reales son stubs).
- **El secreto interno del BFF** para llamadas server-to-server protegidas (p. ej. `2fa-status`)
  es `HWE_REVALIDATION_SECRET`, enviado en la cabecera `X-HWE-Internal-Secret`.
- **No dupliques emails transaccionales**: WooCommerce ya los envía al cambiar el estado del
  pedido (ver `woocommerce-email-branding.php`). El dispatcher de `order-events` es para
  integraciones, no para correos al cliente.
- **Correlación por `request-id`**: Caddy genera `X-Request-Id`, el `middleware` lo reutiliza/
  crea y lo devuelve en la respuesta, y los clientes WP lo propagan vía un AsyncLocalStorage
  (`lib/observability/request-context.ts`). Para correlacionar tus logs con los de WP en un
  handler, envuélvelo en `runWithRequestId(getOrCreateRequestId(request.headers), …)` y usa
  `requestLogger()`. Ver `docs/OBSERVABILITY.md`.
- **Los logs no llevan PII sensible**: pino redacta `authorization`/`cookie`/`password`/`token`;
  no se registran emails ni tarjetas. `userId` es un id numérico pseudónimo; la IP solo para
  rate-limit/abuso. Retención y política en `docs/OBSERVABILITY.md`.

---

## 10. Cómo trabajar en este repo (resumen para el agente)

1. **Ubica el flujo** con el §6 de este documento.
2. **Respeta el patrón BFF** y las convenciones del §7 (secretos server-only, guard en
   escrituras, navegación locale-aware, i18n paralelo).
3. **Verifica con `next build`** (y `tsc`/`lint`) antes de declarar algo terminado.
4. **Sé honesto sobre lo no verificado en vivo**: muchas cosas requieren la pila Docker con
   WordPress real (login, carrito, checkout, webhooks). Si no la levantaste, dilo.
5. **No metas secretos en el repo**: solo `.env.example` con placeholders.

---

## 11. Documentación (para clientes y operación)

- **Cliente/instalación**: `docs/INSTALL.md`, `docs/CONFIGURATION.md`, `docs/CUSTOMIZATION.md`,
  `docs/DEPLOYMENT.md`, `docs/SECURITY.md`, `docs/ACCESIBILIDAD.md`.
- **Operación**: `docs/RUNBOOK.md` (backup/restore probado, rotación de secretos, cierre de
  sesiones, incidentes) y `docs/GO-LIVE.md` (checklist de cero a producción, incluye el guard
  de secretos que **aborta el arranque** en prod con valores por defecto).
- **Observabilidad**: `docs/OBSERVABILITY.md` (correlación request-id, eventos de alerta,
  retención de logs y PII).
- **Compatibilidad**: `docs/COMPATIBILITY.md` (matriz de versiones soportadas).
- **Cambios**: `CHANGELOG.md` (Keep a Changelog + SemVer).
