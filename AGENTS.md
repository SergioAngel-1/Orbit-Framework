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
- **Frontend = Next.js 15 (App Router) + React 19 + TypeScript + Tailwind v4.**
  Además de ser la web pública, actúa como **BFF (Backend-for-Frontend)**: todas las
  operaciones sensibles pasan por sus *Route Handlers* (`/api/...`), nunca directo del
  navegador a WordPress.
- **Infra = Docker Compose**: MariaDB + WordPress + Redis + Next.js.

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
├── docker-compose.yml          # db + wordpress(:8080) + redis + wpcli + frontend(:3000)
├── .env.example                # TODAS las variables (raíz). Copiar a .env
├── README.md                   # Guía de arranque y comandos
├── AGENTS.md                   # este archivo
├── docs/                        # documentación de cliente y operaciones
│   ├── INSTALL.md               # instalación paso a paso
│   ├── CONFIGURATION.md         # variables de entorno
│   ├── CUSTOMIZATION.md         # guía de white-label / rebranding
│   ├── DEPLOYMENT.md            # despliegue en producción
│   ├── SECURITY.md              # modelo de amenazas y hardening
│   └── ACCESIBILIDAD.md         # auditoría WCAG 2.2 AA
│
├── backend/                    # WordPress headless (solo nuestro código propio se versiona)
│   ├── scripts/setup.sh         # instala WP + plugins + WooCommerce + datos demo (WP-CLI)
│   ├── scripts/generate-woo-keys.sh  # genera las claves ck/cs de WooCommerce
│   ├── config/uploads.ini
│   └── wp-content/mu-plugins/   # ⭐ aquí vive el comportamiento headless (ver §6.2)
│       ├── headless-config.php      # bloqueo del frontend nativo + CORS de GraphQL
│       ├── security.php             # hardening (enumeración de usuarios, pingbacks…)
│       └── woocommerce-headless.php # ajustes Store API (nonce) + CORS
│
└── frontend/                   # Next.js (web pública + BFF)
    ├── next.config.mjs          # cabeceras de seguridad + CSP + plugin next-intl + imágenes
    ├── src/
    │   ├── middleware.ts         # ⭐ i18n + barrera de Origin + refresh JWT (ver §6)
    │   ├── i18n/                 # routing, request, navigation, messages (es/en)
    │   ├── config/site.ts        # marca/URL/social centralizadas (base white-label)
    │   ├── app/
    │   │   ├── [locale]/         # ⭐ TODAS las páginas viven bajo el segmento de idioma
    │   │   ├── api/              # ⭐ el BFF: auth, store, csrf, revalidate
    │   │   ├── sitemap.ts robots.ts manifest.ts not-found.tsx layout.tsx (passthrough)
    │   ├── components/           # UI (cart, products, auth, account, checkout, analytics, i18n)
    │   └── lib/                  # ⭐ toda la lógica de servidor/cliente (ver §6)
```

Cuando dudes de una ruta, **busca en `frontend/src/lib/`**: está organizado por dominio
(`auth/`, `woocommerce/`, `security/`, `catalog/`, `account/`, `client/`).

---

## 4. Cómo está construido el frontend mental-mente

- **Lectura pública (catálogo, posts)** → GraphQL (WPGraphQL/WooGraphQL) con **ISR/SSG**.
  Cacheable, sin secretos, etiquetado con `tags: ["products"]` para revalidar por webhook.
- **Escritura / datos privados (carrito, checkout, pedidos, cuenta, auth)** → **BFF**:
  Route Handlers que validan (Zod), protegen (CSRF + rate-limit + Origin), autorizan
  (sesión + propiedad del recurso) y reenvían a WooCommerce con los secretos del servidor.
- **Cliente GraphQL** (`lib/graphql-client.ts`) elige endpoint según dónde corre: en el
  **servidor** usa la red interna de Docker (`http://wordpress`), en el **navegador** la URL
  pública. Mismo patrón en los clientes de WooCommerce.

---

## 5. Estado del proyecto

| Área | Estado |
|------|--------|
| Seguridad base (cabeceras, CORS, hardening, Redis) | ✅ |
| Autenticación JWT (cookies httpOnly + refresh) | ✅ |
| Proxy inverso a WooCommerce (BFF, ck/cs server-only) | ✅ |
| CSRF + rate-limit + idempotencia | ✅ |
| E-commerce (catálogo, carrito, checkout, cuenta) | ✅ |
| SEO + i18n (next-intl, sitemap, hreflang, JSON-LD) | ✅ |
| Pagos (capa de pasarelas enchufable Wompi/PayU/Bold…) | ✅ |
| Calidad / CI/CD / observabilidad | ✅ |
| Empaquetado comercial (licencia, docs, white-label) | ✅ |

El proyecto está **completo** y listo para comercializar. No hay fases pendientes.
Cualquier trabajo futuro es mantenimiento, integración de una pasarela real o mejora
puntual de características existentes.

---

## 6. Dónde revisar cada flujo

### 6.1 Arranque e infraestructura
- `docker-compose.yml` — servicios y variables. `README.md` — pasos exactos.
- Primer arranque: `docker compose up -d` → `docker compose run --rm wpcli` (instala todo)
  → `generate-woo-keys.sh` (claves WC) → pegar en `.env` → `docker compose up -d frontend`.

### 6.2 Comportamiento "headless" de WordPress
- `backend/wp-content/mu-plugins/headless-config.php` — bloquea el frontend nativo (redirige
  a `/wp-admin`), aplica **CORS al endpoint `/graphql`** con allowlist de orígenes.
- `backend/wp-content/mu-plugins/security.php` — bloquea enumeración de usuarios, pingbacks.
- `backend/wp-content/mu-plugins/woocommerce-headless.php` — desactiva el nonce de la Store
  API (seguro porque el BFF ya impone Origin + CSRF).
- Plugins/contenido se instalan vía `backend/scripts/setup.sh`.

### 6.3 Seguridad del frontend
- **Cabeceras + CSP**: `frontend/next.config.mjs` (función `headers()`).
- **Barrera de Origin** para escrituras `/api/*`: `frontend/src/middleware.ts`.
- **CSRF** (signed double-submit): `lib/security/csrf.ts`; el cliente obtiene el token en
  `app/api/csrf/route.ts` y lo reenvía en `X-CSRF-Token`.
- **Rate-limit** (Redis, ventana fija, fail-open): `lib/security/rate-limit.ts` + `lib/redis/client.ts`.
- **Idempotencia** (checkout): `lib/security/idempotency.ts`.
- **Guard unificado** (Origin → CSRF → rate-limit) aplicado en cada escritura:
  `lib/api/guard.ts`. Si añades un endpoint de escritura, **úsalo**.

### 6.4 Autenticación (JWT)
- Endpoints BFF: `app/api/auth/{login,register,refresh,logout,me}/route.ts`.
- Tokens en **cookies httpOnly** (nunca en `localStorage` ni en el body).
  Opciones de cookie centralizadas en `lib/security/cookies.ts`.
- Verificación local de firma con `jose`: `lib/auth/jwt.ts`.
- Sesión en Server Components: `lib/auth/session.ts` (`getSession`, `requireSession`,
  `fetchGraphQLAsViewer`).
- **Refresh transparente**: lo hace `middleware.ts` (renueva el token caducado y lo propaga
  al request actual). Operaciones GraphQL de auth en `lib/auth/mutations.ts`.

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

### 6.6 Revalidación on-demand (catálogo siempre fresco)
- WooCommerce envía un webhook a `app/api/revalidate/route.ts`, que **verifica la firma
  HMAC** (`lib/security/webhook.ts`) y hace `revalidateTag("products")`.

### 6.7 SEO + i18n
- **Routing por idioma**: todo bajo `app/[locale]/`. `es` sin prefijo (canónico), `en` en
  `/en`. Config en `i18n/routing.ts`; locale resuelto en `i18n/request.ts`.
- **Navegación locale-aware**: usa SIEMPRE `Link`/`redirect`/`useRouter` de
  `i18n/navigation.ts` (no los de `next/*`), o se pierde el idioma al navegar.
- **Textos**: `i18n/messages/es.json` y `en.json` (mantenlos paralelos). Selector:
  `components/i18n/locale-switcher.tsx`.
- **SEO**: `app/sitemap.ts` (URLs + hreflang), `app/robots.ts`, `app/manifest.ts`,
  metadatos + JSON-LD en `app/[locale]/layout.tsx` y en la ficha de producto.
- **Analítica/consentimiento**: `components/analytics/` (banner opt-in; el script real de
  GA4/Plausible aún es un stub por cablear).

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
npx next lint        # ESLint
npx next build       # ⭐ la verificación definitiva (prerender + tipos + middleware edge)
```
Pila completa: `docker compose up -d` (+ `wpcli` la primera vez). Ver `README.md`.

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
- **Rate-limit es fail-open**: si Redis cae, deja pasar (es mitigación, no barrera dura).
- **El refresh token de WPGraphQL JWT no rota**: la invalidación global se hace rotando el
  "user secret" en WordPress. Documentado en el plan (Fase 2).
- **`prose` (tipografía) no está instalado**: la descripción de producto se ve sin estilos
  enriquecidos hasta añadir `@tailwindcss/typography`. No es un bug.
- **Stripe/PayPal NO se usan**: la Fase 7 es una capa agnóstica para pasarelas LATAM.

---

## 10. Cómo trabajar en este repo (resumen para el agente)

1. **Ubica el flujo** con el §6 de este documento.
2. **Respeta el patrón BFF** y las convenciones del §7 (secretos server-only, guard en
   escrituras, navegación locale-aware, i18n paralelo).
3. **Verifica con `next build`** (y `tsc`/`lint`) antes de declarar algo terminado.
4. **Sé honesto sobre lo no verificado en vivo**: muchas cosas requieren la pila Docker con
   WordPress real (login, carrito, checkout, webhooks). Si no la levantaste, dilo.
5. **No metas secretos en el repo**: solo `.env.example` con placeholders.
