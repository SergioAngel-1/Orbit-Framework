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
| Pagos | — | Stripe (o pasarela WooCommerce) server-side |
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

# ---- Pagos (Stripe) ----
STRIPE_SECRET_KEY=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY=pk_live_xxx
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

### Fase 2 — Autenticación JWT (flujo completo)
**Objetivo:** login/registro de clientes con tokens **fuera del alcance de JS del cliente**.

- [ ] **Route Handlers de auth** (BFF):
  - `frontend/src/app/api/auth/login/route.ts` → mutación `login` de WPGraphQL JWT,
    guarda `authToken` y `refreshToken` en **cookies httpOnly + Secure + SameSite**.
  - `frontend/src/app/api/auth/refresh/route.ts` → rota el refresh token.
  - `frontend/src/app/api/auth/logout/route.ts` → borra cookies.
  - `frontend/src/app/api/auth/register/route.ts` → `registerUser` + validación.
- [ ] **Helper de sesión server-side** `frontend/src/lib/auth/session.ts`:
      lee/verifica el JWT desde cookies en Server Components y Route Handlers.
- [ ] **Refresh automático**: middleware `frontend/src/middleware.ts` que renueva el
      `authToken` si caducó usando el `refreshToken` (rotación).
- [ ] **`graphql-client.ts`**: extender para inyectar el `authToken` desde la cookie en
      peticiones autenticadas (ya soporta `authToken` por parámetro).

> **Decisión de seguridad:** **NO** usar `localStorage` para tokens (vulnerable a XSS).
> Cookies `httpOnly` + CSRF (Fase 4). El JWT nunca llega al JS del cliente.

**Criterio de aceptación:** login fija cookies httpOnly; el token no es accesible desde
`document.cookie`; refresh rota el token; logout invalida la sesión.

---

### Fase 3 — Proxy inverso a WooCommerce REST API (BFF)
**Objetivo:** todas las operaciones de comercio pasan por el servidor con credenciales
server-only. **Este es el núcleo del requisito #2.**

- [ ] **Cliente WC server-only** `frontend/src/lib/woocommerce/client.ts`:
  - Construye peticiones a `WC_API_URL` con auth **Basic** (`ck`:`cs`) o cabecera OAuth.
  - Marcado con `import "server-only";` para impedir su import desde el cliente.
  - Timeout, reintentos, normalización de errores.
- [ ] **Route Handlers proxy** bajo `frontend/src/app/api/store/`:
  - `cart/route.ts` (GET/POST/PUT/DELETE) — carrito.
  - `checkout/route.ts` (POST) — crear pedido.
  - `orders/[id]/route.ts` (GET) — pedido del usuario autenticado.
  - `customer/route.ts` (GET/PUT) — datos de la cuenta.
- [ ] **Capa de autorización**: cada handler verifica la sesión (Fase 2) y que el recurso
      pertenece al usuario (p. ej. un pedido solo lo ve su dueño).
- [ ] **Validación de entrada** con **Zod** (`frontend/src/lib/validation/`): nunca
      reenviar a WC un payload sin validar/sanear.
- [ ] **Mapa de tipos** `frontend/src/types/woocommerce.ts`.

**Criterio de aceptación:** desde el navegador no aparece nunca `ck_`/`cs_` (verificar en
Network y bundle); un usuario no puede leer pedidos de otro; payloads inválidos → 422.

---

### Fase 4 — CSRF, rate-limiting y validación
**Objetivo:** proteger los endpoints de escritura del BFF (requisito #1).

- [ ] **CSRF (double-submit cookie)**:
  - `frontend/src/lib/security/csrf.ts`: emite token en cookie no-httpOnly + lo exige en
    cabecera `X-CSRF-Token` en toda mutación.
  - Validación adicional de **`Origin`/`Referer`** contra `ALLOWED_ORIGIN`.
- [ ] **Rate limiting** `frontend/src/lib/security/rate-limit.ts` (Redis/Upstash):
  - Límites por IP + por usuario. Más estricto en `login`, `register`, `checkout`,
    `forgot-password`.
  - Respuesta `429` con `Retry-After`.
- [ ] **Middleware central** `frontend/src/middleware.ts`: aplica rate-limit y verificación
      de origen a `/api/*` antes de los handlers.
- [ ] **Sanitización/escape** de toda salida que provenga del CMS (evitar XSS en
      excerpts/descripción de producto — hoy `stripHtml` es un parche, usar un sanitizador).
- [ ] **Idempotencia** en checkout (clave de idempotencia en Redis) para evitar pedidos
      duplicados por doble clic / reintentos.

**Criterio de aceptación:** mutación sin token CSVF válido → 403; superar el umbral →
429; doble envío de checkout → un único pedido.

---

### Fase 5 — Funcionalidad e-commerce (catálogo + carrito + cuenta)
**Objetivo:** experiencia de tienda completa sobre la base segura.

- [ ] **WooGraphQL** en backend: instalar `wp-graphql-woocommerce` en `setup.sh`.
- [ ] **Catálogo (lectura, ISR)**:
  - `frontend/src/app/(shop)/products/page.tsx` — listado con paginación/filtros.
  - `frontend/src/app/(shop)/products/[slug]/page.tsx` — ficha con
    `generateStaticParams` + ISR + `generateMetadata` (SEO).
  - `frontend/src/app/(shop)/categories/[slug]/page.tsx`.
- [ ] **Carrito**: estado en servidor (sesión WC) + UI cliente (`useOptimistic`).
- [ ] **Cuenta**: `frontend/src/app/(account)/` — pedidos, direcciones, perfil
      (protegido por sesión).
- [ ] **Búsqueda** y **revalidación on-demand**: Route Handler
      `frontend/src/app/api/revalidate/route.ts` disparado por **webhooks de WooCommerce**
      (producto creado/actualizado/stock) → `revalidateTag('products')`.
- [ ] **Verificación de firma de webhooks** con `WC_WEBHOOK_SECRET` (HMAC).

**Criterio de aceptación:** navegación de catálogo estática+ISR; añadir/editar/quitar del
carrito persiste; cambio de producto en WP refleja en el front tras webhook.

---

### Fase 6 — Pagos
**Objetivo:** cobro real, server-side, sin exponer secretos.

- [ ] **Estrategia A (recomendada para headless):** Stripe Payment Intents desde el BFF.
  - `frontend/src/app/api/checkout/payment-intent/route.ts` (server-only `STRIPE_SECRET_KEY`).
  - `frontend/src/app/api/webhooks/stripe/route.ts` con verificación de firma
    (`STRIPE_WEBHOOK_SECRET`) → marca el pedido WC como pagado.
- [ ] **Estrategia B (alternativa):** redirección a pasarela WooCommerce nativa.
- [ ] **Conciliación**: el pedido en WooCommerce se crea **antes** del pago y se confirma
      vía webhook (nunca confiar en el redirect del cliente como prueba de pago).

**Criterio de aceptación:** pago de prueba (modo test) crea pedido `processing` solo tras
webhook verificado; reintentos no duplican cobro (idempotencia Fase 4).

---

### Fase 7 — Calidad, CI/CD y observabilidad
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

### Fase 8 — Empaquetado comercial (requisito #4)
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
- [ ] **i18n** (next-intl): al menos ES/EN, textos en archivos de traducción.
- [ ] **Páginas legales**: privacidad, cookies, términos, devoluciones (plantillas).
- [ ] **SEO/Analytics**: `sitemap.ts`, `robots.ts`, Open Graph, JSON-LD de producto,
      consentimiento de cookies, soporte GA4/Plausible.
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
| Pedidos duplicados | Clave de idempotencia (Redis) | 4,6 |
| Pago falsificado desde el cliente | Confirmación solo por webhook firmado | 6 |
| Webhook falso | Verificación HMAC con secreto | 5,6 |
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
"stripe"                      // pagos server-side
"@stripe/stripe-js"           // Stripe en cliente (publishable key)
"next-intl"                   // i18n
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
                                        └─► Fase 6 (pagos)
Fase 7 (calidad/CI) — transversal, empezar desde Fase 1
Fase 8 (comercial) — cierre, requiere 1–7 estables   ◄── requisito #4
```

> **Nota de alcance:** este documento es el plan. La implementación de cada fase debe ir
> en PRs independientes con sus criterios de aceptación verificados. Recomendado fijar la
> versión `1.0.0` (vendible) solo al completar la Fase 8 y el checklist del punto 6.
