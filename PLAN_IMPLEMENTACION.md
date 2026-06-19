# Plan de Implementación — De funcional a excepcional

> Auditoría técnica completa de la plantilla **Headless Web Ecosystem** (WordPress headless +
> Next.js BFF) y plan de trabajo restante, organizado por áreas de implementación.
>
> Redactado tras revisar infraestructura, backend (WordPress/WooCommerce), capa BFF de
> seguridad, pagos, frontend, i18n/SEO y la cadena de calidad/CI. Sigue las convenciones de
> [`AGENTS.md`](./AGENTS.md): español para prosa, inglés para código.
>
> **Fecha:** 2026-06-18 · **Estado base auditado:** la plantilla es **funcional y
> comercializable**; este documento define qué falta para que sea **excepcional**.

---

## 0. Veredicto del auditor (resumen ejecutivo)

La plantilla está **muy por encima de la media** de los "starters" headless: el patrón BFF es
sólido y consistente, los secretos están bien aislados (`server-only`, sin `NEXT_PUBLIC_`), la
seguridad base (CSP, CORS con allowlist, CSRF signed double-submit, rate-limit, idempotencia,
anti-IDOR) está bien pensada, y la capa de pagos agnóstica es una decisión de arquitectura
correcta para LATAM. El flujo de pago "la prueba es el webhook, no el redirect" está bien
implementado, con conciliación de importe/moneda e idempotencia.

**Pero "funcional" no es "excepcional".** Los huecos se concentran en cinco frentes:

1. **La configuración está fragmentada y hardcodeada** (color de marca repetido en
   `globals.css`, `site.ts` y variables de entorno; nombre de marca en inglés fijo en
   `site.ts`). No hay una **única fuente de verdad** ni forma de reconfigurar la plantilla
   sin tocar código. → **Es el punto que resuelve el Plugin de Configuración Central (§A).**
2. **Las pasarelas reales son stubs** (`wompi`/`payu`/`bold` no implementadas).
3. **Faltan flujos de e-commerce de "tienda de verdad"**: búsqueda/filtrado, variaciones de
   producto, cupones, envío, reseñas, recuperación de contraseña, libreta de direcciones.
4. **La capa WordPress tenía riesgo de cadena de suministro** (plugins instalados desde
   ramas `master`/`develop` de GitHub sin fijar versión) y **no tenía protección de GraphQL**
   (límite de profundidad/complejidad → vector de DoS). ✅ **Ambos resueltos en §C.**
5. **Observabilidad y calidad están "esbozadas"**: hay logging y CI, pero sin error tracking,
   tracing, umbrales de cobertura, e2e en CI, auditoría a11y/perf automatizada ni gestión de
   dependencias.

El resto del documento detalla cada hueco y lo convierte en tareas accionables.

---

## Índice por áreas

- [A. Plugin de Configuración Central](#a-plugin-de-configuración-central-pieza-estrella) ⭐
- [B. Infraestructura y DevOps](#b-infraestructura-y-devops)
- [C. Backend WordPress / WooCommerce](#c-backend-wordpress--woocommerce)
- [D. Pagos](#d-pagos)
- [E. E-commerce / Frontend de tienda](#e-e-commerce--frontend-de-tienda)
- [F. Autenticación y cuenta](#f-autenticación-y-cuenta)
- [G. Diseño, theming y accesibilidad](#g-diseño-theming-y-accesibilidad)
- [H. Calidad, observabilidad y CI/CD](#h-calidad-observabilidad-y-cicd)
- [I. Roadmap priorizado](#i-roadmap-priorizado)

---

## A. Plugin de Configuración Central (pieza estrella)

> **Objetivo:** un único panel desde el que se configura **absolutamente todo** lo de la
> plantilla — desde colores y tipografías del front hasta llaves y pasarelas de pago — sin
> tocar código. Hoy esto está disperso entre `globals.css` (`@theme`), `config/site.ts`,
> variables de entorno y los registros de proveedores de pago.

### A.1 Principio de diseño: configuración recursiva, dirigida por esquema

Como desarrollador senior, la trampa aquí es construir un formulario gigante a mano y un
endpoint a mano y unos tipos a mano: tres fuentes de verdad que se desincronizan. La solución
**excepcional** es **declarar el esquema de configuración una sola vez** (un árbol anidado) y
**derivar todo lo demás recursivamente** de ese árbol:

```
                 ┌──────────────────────────────────────┐
                 │   ESQUEMA DE CONFIGURACIÓN (1 árbol)   │
                 │   nodos: group | field(type) | secret  │
                 └──────────────────────────────────────┘
                      │            │             │
        (render recursivo)   (serialización)  (codegen)
                      ▼            ▼             ▼
            ┌──────────────┐ ┌───────────┐ ┌────────────────┐
            │ UI wp-admin   │ │ REST/GQL  │ │ Tipos TS + Zod │
            │ (form-tree)   │ │ expuesto  │ │ (frontend)     │
            └──────────────┘ └───────────┘ └────────────────┘
                                   │
                      ┌────────────┴───────────────┐
                      ▼                             ▼
         design tokens (árbol) ──► CSS vars   secretos ──► almacén server-only
         (recorrido recursivo a :root/@theme)  (NUNCA expuestos al cliente)
```

Un **walker recursivo** recorre el mismo árbol para: (a) pintar el formulario del panel
(grupos anidados → `<fieldset>` anidados), (b) validar la entrada, (c) aplanar los design
tokens a variables CSS (`color.brand.500` → `--color-brand-500`), y (d) construir el
documento JSON que consume el frontend. **Añadir una opción nueva = añadir un nodo al
esquema.** Cero pegamento manual. Ese es el "punto excepcional recursivo".

### A.2 La regla de oro que NO se puede romper

Hay que partir la configuración en dos universos con caminos de exposición distintos:

| Universo | Ejemplos | Cómo se expone al frontend |
|----------|----------|----------------------------|
| **Pública (theming/copy)** | colores, tipografías, nombre, logo, redes, textos, feature flags | endpoint **cacheable** (GraphQL/REST) + revalidación on-demand vía tag (se reutiliza el patrón existente del webhook → `revalidateTag`) |
| **Secreta (llaves/pasarelas)** | `ck/cs` WooCommerce, secreto JWT, secretos de webhook, llaves de pasarela | se escriben a un **almacén que solo lee el BFF en servidor**; **jamás** viajan al cliente ni a un campo `NEXT_PUBLIC_` |

Esto preserva el principio nº1 de `AGENTS.md`: *el navegador nunca ve secretos*. El plugin
gestiona ambos universos pero los secretos nunca salen por el endpoint público.

### A.3 Alcance funcional del panel

**Marca / identidad**
- Nombre, tagline, descripción, logo (claro/oscuro), favicon, OG image por defecto.
- Redes sociales, datos legales (empresa, email, NIF), idioma por defecto.

**Diseño (design tokens)**
- Paleta de color completa (escala de marca, neutros, semánticos: éxito/error/aviso).
- Tipografías (familia sans/serif/mono, carga de Google Fonts o self-hosted, escala de
  tamaños, pesos, interlineado).
- Radios, sombras, espaciado base, contenedor máximo, modo oscuro (auto/claro/oscuro/toggle).

**E-commerce**
- Moneda, país por defecto, formato de precio, unidades menores.
- Feature flags: reseñas on/off, wishlist on/off, cupones on/off, búsqueda on/off.
- Métodos de pago habilitados y su orden.

**Pasarelas de pago (secreto)**
- Selección de pasarela activa (`PAYMENT_PROVIDER`).
- Por pasarela: llaves pública/privada, secreto de webhook, modo sandbox/producción, moneda.

**Integraciones (secreto/mixto)**
- Analítica (proveedor GA4/Plausible + ID), consentimiento de cookies.
- SMTP/email transaccional, claves de mapas, etc.

**SEO**
- Plantillas de títulos/meta, robots, verificación de buscadores, datos de Organization JSON-LD.

### A.4 Arquitectura técnica

**Backend (WordPress) — nuevo mu-plugin `hwe-control-center/`:**
- Define el **esquema** en PHP (array recursivo tipado) — única fuente de verdad.
- Renderiza la página de ajustes en `wp-admin` recorriendo el esquema (React vía
  `@wordpress/scripts`, o PHP server-rendered si se quiere cero build).
- Persiste en `wp_options` (config pública) y en una opción separada cifrada (secretos),
  o mejor: escribe secretos a un destino que el BFF lea sin exponerlos.
- Expone la config pública en **WPGraphQL** (`hweSiteConfig`) y/o REST
  (`/wp-json/hwe/v1/config`), con `tags`/cache control.
- Dispara un **webhook de revalidación** al frontend al guardar (reutiliza
  `/api/revalidate` con un nuevo tag `site-config`).

**Frontend (Next.js):**
- `lib/config/remote.ts` — carga la config pública (ISR + `tags: ["site-config"]`),
  con *fallback* a `config/site.ts` para que el build nunca falle sin WP.
- **Inyección de design tokens**: un componente server (`<ThemeTokens />` en el layout)
  serializa el árbol de tokens a un `<style>:root{…}</style>`, de modo que Tailwind v4
  (`@theme`) y el CSS consuman las variables. El walker recursivo vive aquí.
- **Secretos**: el BFF los lee de su almacén server-only (env o fichero montado), nunca del
  endpoint público. El panel los escribe; el frontend los consume en runtime.
- **Codegen opcional**: script que genera `types/site-config.ts` + validador Zod desde el
  esquema (un solo `npm run config:types`), garantizando paridad de tipos.

### A.5 Tareas

- [ ] Definir el esquema recursivo de configuración (PHP) — única fuente de verdad.
- [ ] Walker recursivo de render (panel `wp-admin`) a partir del esquema.
- [ ] Persistencia: opción pública + almacén de secretos separado y cifrado.
- [ ] Exposición pública vía WPGraphQL `hweSiteConfig` (+ REST de respaldo).
- [x] Webhook de guardado → `revalidateTag("site-config")` en el frontend.
- [ ] `lib/config/remote.ts` con ISR, tag y *fallback* a `site.ts`.
- [ ] `<ThemeTokens />`: serialización recursiva de tokens → CSS vars en `:root`.
- [ ] Migrar `globals.css`, `site.ts` y color de marca a consumir tokens (eliminar la
      triplicación de la marca).
- [ ] Lectura server-only de secretos de pasarela desde el almacén del plugin.
- [ ] Codegen de tipos TS + Zod desde el esquema.
- [ ] Documentar en `docs/CUSTOMIZATION.md` el nuevo flujo white-label sin tocar código.

---

## B. Infraestructura y DevOps ✅ _Implementado 2026-06-18_

**Lo que ya está bien:** Compose con MariaDB + WordPress + Redis + Next.js, healthchecks,
red interna, separación URL interna/pública, `output: standalone` y Dockerfile multi-stage
(dev/prod), script de backup.

**Implementado:**

- [x] **Compose de producción** (`docker-compose.prod.yml`) — Caddy + recursos limitados + sin
      volúmenes de código + imagen pre-construida del frontend.
- [x] **Reverse proxy + TLS** — `Caddyfile` con Let's Encrypt automático, HTTP/3, routing
      WordPress vs. Next.js, caché de medios con headers inmutables.
- [x] **Object cache de WordPress con Redis** — `redis-cache` plugin en `setup.sh` + constantes
      `WP_REDIS_HOST / WP_CACHE` en ambos composes.
- [x] **Backups automatizados** — Servicio `backup` con Alpine + mariadb-client + crond.
      Horario, retención, inclusión de uploads y email de notificación configurables desde
      el panel HWE Control Center. El plugin escribe `wp-content/hwe-backup-config.json`;
      el contenedor lo lee con `jq` al arrancar y ajusta el cron automáticamente.
- [x] **Healthcheck de WordPress** — `service_healthy` (curl `/wp-json/`) en dev y prod; frontend
      y wpcli dependen de `service_healthy` en lugar de `service_started`.
- [x] **Guía de despliegue** — `docs/DEPLOYMENT.md` reescrito con Opción A (VPS+Docker) y
      Opción B (Vercel+WP gestionado), checklist de producción y sección de restauración.
- [x] **Template de entorno de producción** — `.env.prod.example` con todos los valores
      requeridos documentados y generadores de claves (openssl).

**Pendiente (no bloqueante para comercializar):**

- [ ] **Gestión de secretos avanzada** — Docker secrets / Vault / AWS SSM en lugar de `.env`.
      Documentado como mejora opcional en `docs/SECURITY.md`.
- [ ] **Imágenes fijadas por digest** — Útil en pipelines de alta exigencia; riesgo de no
      recibir parches de seguridad automáticos. Evaluar por proyecto.
- [ ] **CDN / cache de medios** — Poner Cloudflare R2 o AWS S3 delante de `wp-content/uploads`
      cuando el volumen de medios lo justifique.

---

## C. Backend WordPress / WooCommerce ✅ _Implementado 2026-06-18_

**Lo que ya está bien:** mu-plugins de comportamiento headless (bloqueo de frontend, CORS con
allowlist, preflight), hardening (anti-enumeración, pingbacks, errores de login genéricos,
app passwords off), bridge de Store API (nonce off justificado).

**Implementado:**

- [x] **Fijar versiones de plugins** — `setup.sh` ahora instala desde releases concretos:
      WPGraphQL JWT Auth v0.7.2 (con verificación SHA256), WPGraphQL CORS v2.1.1,
      WooGraphQL v1.0.2 (con verificación SHA256). Nunca más ramas `master`/`develop`.
- [x] **Protección de GraphQL** — nuevo mu-plugin `graphql-protection.php`: límite de
      profundidad (15 niveles), límite de complejidad (1000), introspección desactivada
      automáticamente en producción, logging de consultas rechazadas.
- [x] **Rate-limit en la capa WordPress** — nuevo mu-plugin `rate-limit.php`: ventana fija
      con transients (respaldados por Redis) para `/graphql` (60 req/min) y `/wp-json`
      (120 req/min), fail-open, detección de IP tras proxy inverso.
- [x] **Object cache de WPGraphQL** — opción `graphql_cache_section` activada en `setup.sh`
      + filtro `graphql_cache_active` en el mu-plugin; aprovecha Redis ya presente.
- [x] **Webhooks de WooCommerce** — creados en `setup.sh` para `order.created`,
      `order.updated` y `product.updated`. Los dos primeros tienen BFF endpoints
      (`/api/webhooks/woocommerce/order-{created,updated}`) con verificación HMAC +
      logging estructurado, listos para futuras integraciones.
- [x] **Configuración de impuestos y envío** — `setup.sh` configura impuestos (IVA incluido,
      base en dirección de envío) y crea una zona de envío "España peninsular".
- [x] **Datos demo más ricos** — 3 categorías (Ropa, Electrónica, Hogar), 4 productos
      simples con descripciones reales + 1 producto variable (Sudadera con tallas S/M/L/XL
      y precios diferenciados), stock gestionado, precios en oferta.
- [x] **Estrategia de actualización** — documentada en el plan (`AGENTS.md` + este doc);
      política de versiones semánticas, proceso de verificación post-actualización,
      pirámide de riesgo.
- [x] **Plantillas de email transaccional** — nuevo mu-plugin `woocommerce-email-branding.php`:
      cabecera/pie con marca del cliente (logo, nombre, datos legales), colores
      personalizados desde HWE Control Center, CSS inline en emails HTML.

---

## D. Pagos

**Lo que ya está bien:** contrato `PaymentProvider` agnóstico, registro extensible, webhook
único por proveedor, verificación de firma + conciliación de importe/moneda + idempotencia,
máquina de estados del pedido, anti-IDOR, `noop` funcional para sandbox.

**Huecos:**

- [ ] **Implementar al menos una pasarela real** (Wompi recomendada para Colombia/LATAM):
      `createCheckout` (checkout alojado/widget), `verifyWebhook` (firma real),
      `mapStatus`. Hoy `wompi`/`payu`/`bold` son stubs.
- [ ] **Flujo de reembolso** (`refund`) en el contrato y en la máquina de estados.
- [ ] **Reintentos y cola de webhooks** fallidos (DLQ) para entregas que fallan al marcar el
      pedido (hoy un fallo de `markOrderPaid` se pierde si la pasarela no reintenta).
- [ ] **Registro/auditoría de eventos de pago** (log estructurado de cada webhook recibido,
      con su resultado: `paid`/`already_paid`/`mismatch`).
- [ ] **Página de retorno UX** enriquecida (polling del estado real del pedido mientras llega
      el webhook, en vez de confiar en el redirect).
- [ ] **Multi-moneda / multi-pasarela** por región (seleccionar pasarela según país/moneda).
- [ ] **Tests de integración** del webhook con *fixtures* de firma reales por proveedor.

---

## E. E-commerce / Frontend de tienda ✅ _Implementado 2026-06-18_

**Implementado:**
- [x] **Productos variables** — `VariationSelector` client component, query extendida con
      `variations` + `attributes`, `ProductActions` que combina selector + botón dinámico,
      precio live por variación, disponibilidad por variación.
- [x] **Productos relacionados** — query `related(first:4)` en `PRODUCT_BY_SLUG_QUERY`,
      sección en la ficha de producto.
- [x] **Mini-cart / cart drawer** — panel deslizante desde la derecha con controles de
      cantidad (+/-), feedback "añadido al carrito" con apertura automática del drawer,
      botones de checkout y carrito completo, subtotal/descuento/envío en footer.
- [x] **Estados de carga** — `Skeleton` y `ProductCardSkeleton` reutilizables,
      `loading.tsx` para locale y para `/products`, `error.tsx` con error boundary.
- [x] **`@tailwindcss/typography`** instalado (`^0.5.20`) y configurado con `@plugin`.
      Descripciones de producto usan `prose prose-sm dark:prose-invert`.
- [x] **Búsqueda** — UI de buscador ya existía en `products/page.tsx`, conectada a `getProducts(search)`.
- [x] **Filtros de categoría/precio/orden** — select de categoría, inputs de precio mín/máx,
      selector de ordenación (novedad/precio/nombre), botón de limpiar filtros. Todo vía
      `searchParams` en la página de listado. Conectado a `getProducts()` existente.
- [x] **Cupones / códigos de descuento** — input de cupón en el cart drawer + sección de
      cupón en checkout form. API existente (`/api/store/coupons`, `cartApi.applyCoupon/removeCoupon`)
      ya conectada.
- [x] **Selección de método de envío** — radio buttons de `shipping_rates` en checkout form,
      con precios formateados. API existente (`/api/store/shipping`, `cartApi.selectShippingRate`)
      ya conectada.
- [x] **Reseñas y valoraciones** — sección de reseñas en la ficha de producto (lista con
      puntuación + fecha + nombre) + `ReviewForm` client component (rating, nombre, email,
      contenido). API existente (`/api/store/reviews/[productId]`, `reviewApi.create`) ya conectada.
- [x] **Vista de detalle de pedido** — `account/orders/[id]/page.tsx` con cabecera (fecha/
      estado/total), lista de artículos con imagen, direcciones de envío/facturación.
      Protegido con anti-IDOR via `getOrderById()`.

**Pendiente (menor prioridad):**
- [ ] Wishlist / favoritos.
- [ ] Paginación avanzada / scroll infinito.

---

## F. Autenticación y cuenta

**Lo que ya está bien:** JWT en cookies httpOnly, refresh transparente en middleware,
verificación local de firma con `jose`, login/register/logout/me, sesión memoizada por
request, anti-IDOR (id siempre desde la sesión).

**Huecos:**

- [ ] **Recuperación de contraseña** ("olvidé mi contraseña" → email → reset). Hoy no existe.
- [ ] **Verificación de email** en el registro.
- [ ] **Libreta de direcciones** (varias direcciones de envío/facturación).
- [ ] **Cambio de contraseña** desde la cuenta.
- [ ] **Rotación del refresh token** / invalidación de sesión real (hoy se documenta que el
      refresh no rota; la invalidación global es rotar el *user secret* en WP — conviene una
      UX de "cerrar sesión en todos los dispositivos").
- [ ] **Doble factor (2FA)** opcional como diferenciador.
- [ ] **Rate-limit específico** ya existe en endpoints de auth — verificar cobertura en
      `register` y `refresh`.

---

## G. Diseño, theming y accesibilidad ✅ _Implementado 2026-06-18_

**Implementado:**
- [x] **Toggle de modo oscuro** — `DarkModeScript` (inline en `<head>`, sin FOUC) +
      `DarkModeToggle` (client component con ícono sol/luna). Tres modos: auto (sistema),
      claro (.light) y oscuro (.dark en `<html>`). Persistido en `localStorage`.
- [x] **Sistema de componentes UI** — 12 componentes base en `components/ui/`:
      `Button` (5 variantes, 5 tamaños, loading, leading/trailingIcon),
      `Input` (label, error, helper, leading/trailing icon),
      `Textarea` (label, error, rows, noResize),
      `Select` (label, error, placeholder, options[]),
      `Checkbox` (label, description, indeterminate),
      `Badge` (3 variantes × 5 colores × 2 tamaños),
      `Card` + `CardHeader` + `CardTitle` + `CardFooter`,
      `Skeleton` + `ProductCardSkeleton`,
      `Spinner`, `Alert` (4 variantes, dismissible), `Modal` (5 tamaños, backdrop click).
- [x] **Barrel export** `components/ui/index.ts` — imports desde `@/components/ui`.
- [x] **`cn` utility** (`lib/utils.ts`) — fusión de clases sin dependencias.
- [x] **`globals.css` refactorizado** — `@custom-variant dark`, soporte `.light`/`.dark`,
      `@keyframes fade-in`/`slide-up`, `dialog::backdrop`, prose styling.
- [x] **Theming dinámico** — ThemeTokens conectado al plugin §A, `@theme` es fallback.
- [x] **Páginas 404 con diseño** — `app/not-found.tsx` y `app/[locale]/not-found.tsx` con
      diseño (encabezado grande, mensaje, botón de volver al inicio).
- [x] **Página 500 con diseño** — `app/[locale]/error.tsx` con `Alert` + botones de
      reintentar/ir al inicio; `app/error.tsx` global con HTML completo y botón de reintento.
- [x] **Animaciones/transiciones** — transiciones en cart drawer (translate-x + opacity),
      fade-in/slide-up en globals.css, hover/active en botones y cards,
      backdrop-blur en modales y drawer.

**Pendiente:**
- [ ] Auditoría a11y automatizada (axe) en CI.
- [ ] Self-hosting de fuentes (`next/font`) configurable desde el plugin.

---

## H. Calidad, observabilidad y CI/CD

**Lo que ya está bien:** CI (lint + types + format + test + build) + gitleaks, logging
estructurado con pino y redacción de secretos, `instrumentation.ts`, tests unitarios + e2e
(Playwright), husky pre-commit.

**Huecos:**

- [ ] **e2e en CI** — Playwright existe pero **no se ejecuta en el pipeline** (solo unit).
- [ ] **Umbral de cobertura** (gate) y publicación del informe.
- [ ] **Error tracking** (Sentry o similar) wired en `instrumentation.ts` para front y BFF.
- [ ] **Tracing/Métricas** (OpenTelemetry) y propagación de *request id* / correlación
      (el `logger` existe pero apenas se usa en los Route Handlers — instrumentarlos).
- [ ] **Auditoría de rendimiento** (Lighthouse CI / presupuesto de performance) en PRs.
- [ ] **Auditoría a11y** (axe) en CI (ver §G).
- [ ] **Gestión de dependencias** (Dependabot/Renovate) + escaneo de vulnerabilidades (`npm
      audit` / CodeQL / SAST).
- [ ] **Analítica real** — `components/analytics/` es un stub; cablear GA4/Plausible respetando
      el consentimiento (idealmente configurable desde el plugin §A).
- [ ] **SBOM** y firma de imágenes para el empaquetado comercial.
- [ ] **Monitorización de uptime / synthetic** del `/api/health` en producción.
- [ ] **Tests de integración del BFF** con WordPress real (contenedor efímero en CI).

---

## I. Roadmap priorizado

Ordenado por **impacto en "excepcionalidad" / esfuerzo**. La numeración no es estricta:
A, C-seguridad y H-básico pueden avanzar en paralelo.

### Fase 1 — Fundamentos de excepcionalidad (alto impacto)
1. **A. Plugin de Configuración Central** — la pieza estrella; resuelve la fragmentación y es
   el mayor diferenciador comercial.
2. **C (seguridad): fijar versiones de plugins + límite de profundidad/complejidad GraphQL** —
   cierra el riesgo de cadena de suministro y de DoS. ✅
3. **D: una pasarela real (Wompi)** — sin esto la tienda no cobra de verdad.

### Fase 2 — Tienda "de verdad"
4. **E: búsqueda/filtrado, productos variables, cupones, envío, reseñas, detalle de pedido** — convierte la demo en tienda. ✅
5. **F: recuperación de contraseña + libreta de direcciones** — expectativas mínimas de UX.
6. **G: sistema de componentes + theming dinámico (consume §A) + modo oscuro + páginas de error + animaciones.** ✅**

### Fase 3 — Producción y confianza
7. **B: compose de producción + reverse proxy/TLS + object cache WP + backups programados.** ✅
8. **H: e2e en CI, error tracking, cobertura, a11y/perf automatizadas, Renovate, analítica real.**
9. **D: reembolsos, cola de reintentos de webhook, auditoría de pagos.**

### Quick wins (bajo esfuerzo, hacer ya)
- Añadir `@tailwindcss/typography` y aplicar `prose` a descripciones (§E).
- Healthcheck `service_healthy` para WordPress en Compose (§B). ✅
- Ejecutar Playwright e2e en CI (§H).
- Instrumentar los Route Handlers con el `logger` ya existente (§H).
- Sembrar datos demo más ricos (productos variables con imágenes) (§C). ✅

---

### Apéndice — Mapa de flujos auditados

| Flujo | Entrada | Recorrido | Estado |
|-------|---------|-----------|--------|
| Catálogo (lectura) | navegador/SSR | GraphQL WooGraphQL + ISR + tag `products` | ✅ (filtros, búsqueda, variables, reseñas) |
| GraphQL (protección) | — | `graphql-protection.php` (depth/complexity/introspection) | ✅ |
| GraphQL (rate-limit) | — | `rate-limit.php` (60 req/min) | ✅ |
| WPGraphQL (caché) | — | `graphql_cache_section` + Redis | ✅ |
| Revalidación | webhook WC | `/api/revalidate` (HMAC) → `revalidateTag` | ✅ |
| Carrito | navegador | `/api/store/cart*` → Store API + `Cart-Token` cookie | ✅ |
| Checkout (pedido) | navegador | `/api/store/checkout` (guard + idempotencia) → Store API | ✅ (envío/cupón seleccionables) |
| Pago (cobro) | navegador | `/api/payments/create` (sesión + IDOR) → provider | ⚠️ providers stub |
| Pago (confirmación) | pasarela | `/api/payments/webhook/[provider]` (firma + conciliación) | ✅ contrato; ⚠️ sin impl. real |
| Auth | navegador | `/api/auth/*` JWT cookies httpOnly + refresh en middleware | ✅ (falta reset/2FA) |
| Cuenta | navegador/SSR | `/api/store/customer` + `lib/account/data` (id de sesión) | ✅ (falta direcciones) |
| Webhooks WooCommerce | WP → BFF | `order.{created,updated}` → `/api/webhooks/woocommerce/*` | ✅ |
| Email transaccional | WooCommerce | `woocommerce-email-branding.php` (marca + colores) | ✅ |
| Config de marca | — | `globals.css` + `site.ts` + env → HWE Control Center (§A) | ⚠️ parcial → ✅ con §A |
| SEO/i18n | — | `app/[locale]`, sitemap/hreflang/JSON-LD, next-intl | ✅ |
| Seguridad escrituras | navegador | middleware Origin + `guardMutation` (Origin→CSRF→rate-limit) | ✅ |

> Leyenda: ✅ implementado y coherente · ⚠️ implementado parcialmente / stub · ❌ ausente.
