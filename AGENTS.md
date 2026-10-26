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
- **Frontend = Next.js 16 (App Router, Turbopack) + React 19 + TypeScript + Tailwind v4.**
  Además de ser la web pública, actúa como **BFF (Backend-for-Frontend)**: todas las
  operaciones sensibles pasan por sus *Route Handlers* (`/api/...`), nunca directo del
  navegador a WordPress.
- **Infra = Docker Compose** (producción) o **híbrido** (desarrollo: WordPress y frontend nativos, DB/Redis en Docker).

El idioma del proyecto (comentarios, docs, UI) es **español**; el código y los nombres
técnicos están en inglés. Mantén esa convención.

### 1.1 Qué es "el framework" y qué es "responsabilidad de cada instancia"

**Decisión de arquitectura (léela antes de tocar `frontend/`):** el framework es
**backend + arquitectura**, no un generador de UI. Dos capas muy distintas viven en el mismo
repo y es clave no confundirlas:

| Capa | Qué incluye | ¿Quién la mantiene? |
|---|---|---|
| **Núcleo del framework** | WordPress headless + WooCommerce (`backend/`, salvo las rutas de instancia), el **BFF**: `frontend/src/app/api/*` y `frontend/src/lib/*` (auth, seguridad, proxy a WooCommerce, pagos, config del HWE Control Center), `frontend/src/proxy.ts`, `frontend/src/components/ui/*` (primitivas sin negocio: Button, Input, Modal…) y los **11 módulos** (`backend/wp-content/plugins/hwe-*`) | El framework. Se actualiza, se documenta, se versiona como una unidad reutilizable entre instancias (`docs/UPGRADE.md`). |
| **Responsabilidad de cada proyecto/instancia** | **Rutas reservadas** que el framework nunca toca: `backend/wp-content/mu-plugins/instance/`, los plugins `hwe-instance-*`, `frontend/src/instance/*` y `frontend/tests/e2e/instance/` (E2E de la instancia). Más `frontend/src/components/**` (excepto `ui/`) y **todas las vistas** bajo `frontend/src/app/[locale]/*` (layout visual, home, listados, fichas, marketing, footer…), que se heredan **una vez** al clonar como starter neutro | **La instancia.** Cámbialo, bórralo, reescríbelo — es 100% tuyo desde el clone. |

**El contrato de instancia** (cómo enchufa su código sin tocar el núcleo; detalle en
`docs/PLUGIN_DEVELOPMENT.md §0` y los `README.md` de las tres carpetas `instance/`):
- **Backend:** `hwe-instance-loader.php` carga los `.php` de primer nivel de `mu-plugins/instance/`
  (orden alfabético; un archivo roto se registra y no tumba el sitio). La instancia se engancha
  con hooks de nombre estable: `hwe_control_center_schema` (campos solo bajo el grupo `instance`),
  `hwe_control_center_field_types`, `hwe_control_center_saved`, `hwe_revalidation_tags`,
  `hwe_geo_packs`, `hwe_product_fields`, `hwe_order_item_details`, `hwe_landings_static_entries`,
  `hwe_banners_decor_presets`… `HWE_CORE_CONTRACT_VERSION` es la versión del contrato.
- **Frontend:** `src/instance/registry.ts` exporta un `InstanceRegistry` (**solo datos**, nunca
  componentes: tags ISR, landings con sección única, campos de producto, visuales decorativos,
  `publicPaths` y `sitemap` —páginas propias y exclusiones de sitemap/`llms.txt`—) y es
  el **único** módulo de instancia que el núcleo importa. Una regla de ESLint impide que
  `lib/`, `app/api/`, `proxy.ts` y `components/ui/` importen `@/components/**` (salvo `ui/`) o
  `@/instance/**` (salvo `@/instance/registry`). `config.d.ts` tipa `SiteConfig.instance`.
- **E2E:** `frontend/tests/e2e/instance/` es de la instancia (el framework solo versiona su
  `README.md`). `playwright.config.ts` ignora esa carpeta en el framework, así que sus specs no
  entran ni en `npm run e2e` ni en el CI del framework aunque se haga merge de él. Con la variable
  de repositorio `HWE_INSTANCE=true`, el job `e2e` del CI ejecuta **solo** esa carpeta en sus dos
  pasadas (normal y módulos apagados); sin specs ahí, el job pasa.
- **Puerta anti-instancia** (`.github/scripts/check-anti-instance.sh`, job de CI): el repo no puede
  contener nombres, slugs ni supuestos de ninguna instancia concreta (se escanea todo salvo
  `examples/` y `docs/superpowers/`). El patrón también caza palabras comunes del español que
  contienen un token prohibido: usa sinónimos y no escribas nombres de instancia en ningún archivo.
- **Ejemplo vivo:** `examples/instance-minimal/` se superpone al repo en el job «contrato» de CI
  (tests PHP y TS, tipos y build). Si cambias el contrato, ese job es el que lo dice.

**Por qué:** la UI de e-commerce siempre termina con decisiones de un negocio concreto
(categorías, tono de marca, qué secciones tiene el footer…). Si el framework la "posee", esas
decisiones se filtran al framework base — esto ya pasó una vez (un mu-plugin y componentes de
un cliente real quedaron mezclados en el repo base) y volverá a pasar si se sigue tratando la
UI como parte del núcleo. La solución no es "genericizar mejor" — es que el framework deje de
prometer nada sobre esa capa.

**Lo que SÍ es un contrato estable** entre el backend/BFF y cualquier frontend (el heredado o
uno construido desde cero): el esquema de WPGraphQL/WooGraphQL, los endpoints REST del BFF
bajo `app/api/*`, el endpoint público `/wp-json/hwe/v1/config` (marca/diseño/flags), y el
contrato de auth (cookies httpOnly + CSRF). Ese contrato está documentado en
**`docs/FRONTEND_CONNECT.md`** — es la referencia que sobrevive aunque reescribas toda la UI.

**El CI también es núcleo.** `.github/**` no se edita en una instancia (ni siquiera para
adaptarlo a su repositorio): se declara la **variable de repositorio `HWE_INSTANCE=true`** y el
propio CI se salta la puerta anti-instancia —perseguiría los tokens de la instancia— y el job
«contrato» —que superpone `examples/instance-minimal/` y pisaría su `registry.ts`—. Sin la
variable, el CI se comporta como el del framework (fail-closed). Ver
`docs/CREATE_INSTANCE.md`, «CI de la instancia».

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
├── docker-compose.prod.yml     # prod: + caddy (TLS) + backup + wp-cron; sin puertos WP/Redis al host
├── Caddyfile  caddy/           # reverse proxy prod (TLS, HTTP/3); /graphql público restringido; sitios opcionales
├── .env.example / .env.prod.example  # TODAS las variables. Copiar a .env / .env.prod
├── README.md  AGENTS.md  CHANGELOG.md  LICENSE  EULA.md
├── docs/                        # documentación de cliente y operaciones
│   ├── INSTALL.md  CONFIGURATION.md  CUSTOMIZATION.md  DEPLOYMENT.md
│   ├── CREATE_INSTANCE.md       # ⭐ crear una instancia nueva (clonar + `wp hwe setup`)
│   ├── FRONTEND_CONNECT.md      # ⭐ el CONTRATO backend/BFF (estable) + inventario de lo heredado (no estable)
│   ├── FRONTEND_BUILD.md        # ⭐ para agentes: entrevista de negocio → plan de vistas (responsabilidad de la instancia)
│   ├── SECURITY.md  ACCESIBILIDAD.md  UPGRADE.md  PLUGIN_DEVELOPMENT.md
│   ├── RUNBOOK.md               # ⭐ operación: backup/restore, rotación de secretos, incidentes
│   ├── GO-LIVE.md               # ⭐ checklist "de cero a producción"
│   └── COMPATIBILITY.md         # matriz de versiones (WP/Woo/PHP/Node/Next…)
│
├── backend/                    # WordPress headless (solo nuestro código propio se versiona)
│   ├── scripts/setup.sh         # instala WP + plugins + WooCommerce + tema base + config de instancia (WP-CLI)
│   ├── scripts/generate-secrets.sh   # genera y escribe los secretos en .env/.env.local
│   ├── scripts/generate-woo-keys.sh  # genera y escribe las claves ck/cs de WooCommerce
│   ├── scripts/instance.config.example.json  # plantilla de `wp hwe setup` (marca/diseño/ecommerce/seo…)
│   ├── scripts/backup.sh  scripts/restore.sh  # ⭐ copia y restauración (DB + uploads)
│   ├── scripts/verify-security.sh  verify-modules.sh  verify-integration.sh  # ⭐ verificación EN VIVO contra la pila
│   ├── scripts/warm-cache.sh                 # calienta la caché tras desplegar
│   ├── tests/                   # PHPUnit + Brain Monkey (`composer test`); dobles de WP en tests/Stubs/
│   ├── scripts/backup-entrypoint.sh  backup-cron.sh  seed-demo.sh
│   ├── config/uploads.ini
│   └── wp-content/
│       ├── themes/hwe-headless-base/  # ⭐ único tema versionado: sin funcionalidad real (headless),
│       │                              #    evita depender de un tema de wordpress.org (ver setup.sh)
│       ├── plugins/              # ⭐ los 11 módulos opcionales `hwe-<id>` (ver §6.9); WooCommerce y demás los instala setup.sh
│       └── mu-plugins/           # ⭐ comportamiento headless + endpoints propios (ver §6.2/§6.4)
│           ├── hwe-instance-loader.php  # carga `instance/*.php` (TU código; ver §1.1)
│           ├── instance/                #   rutas de la instancia: el framework solo versiona su README
│           ├── hwe-bff-only.php         # ⭐ WordPress solo atiende al BFF (secreto interno)
│           ├── hwe-bff-secret.php       # comprobación compartida de ese secreto (bff-only + rate-limit)
│           ├── hwe-content-i18n/        # traducción de contenido a N idiomas (ver §6.10)
│           ├── hwe-geo/                 # paquetes por país (ver §6.11)
│           ├── hwe-seo/  hwe-product-fields/  hwe-order-item-details.php  hwe-smtp.php  hwe-svg-uploads.php …
│           ├── headless-config.php      # bloqueo del frontend nativo + CORS de GraphQL
│           ├── security.php             # hardening (enumeración de usuarios, pingbacks…)
│           ├── woocommerce-headless.php # ajustes Store API (nonce) + CORS
│           ├── hwe-email-chrome.php      # emails transaccionales con marca (+ themes/hwe-headless-base/woocommerce/emails/)
│           ├── graphql-protection.php   # límites de profundidad/complejidad + introspección off
│           ├── rate-limit.php           # rate-limit de /graphql y /wp-json (XFF de confianza; exento el BFF)
│           ├── hwe-auth.php             # ⭐ REST: reset pass, verificación email, 2FA, logout-all
│           ├── hwe-auth/                #   su lógica por responsabilidad (traits de HWE_Auth_Endpoints)
│           └── hwe-control-center/      # ⭐ plugin de config central + secretos cifrados (AES-GCM).
│               ├── Schema.php               # fuente única de verdad de TODOS los campos de config
│               ├── SchemaGroups/            #   los grupos del núcleo por tema (traits de Schema)
│               ├── Cli.php                  # comando `wp hwe setup <json>` (ver docs/CREATE_INSTANCE.md)
│               └── RestApi.php               # expone los campos `public` en /wp-json/hwe/v1/config
│
└── frontend/                   # Next.js (web pública + BFF)
    ├── next.config.mjs          # cabeceras de seguridad + CSP (incl. hosts analítica) + i18n
    ├── tests/                   # e2e (Playwright: smoke, axe, módulos, purchase opt-in) + unit sueltos; los tests de `src/` van COLOCADOS junto al código
    ├── src/
    │   ├── instrumentation.ts   # arranque: guard de secretos + Sentry + log de inicio
    │   ├── proxy.ts             # ⭐ i18n + barrera de Origin + refresh JWT (ver §6). Next 16 renombró middleware→proxy
    │   ├── i18n/                # routing, request, navigation, messages (es/en + los idiomas de la instancia)
    │   ├── instance/            # 🧩 TU código de instancia: registry.ts, config.d.ts, lib/ (ver §1.1)
    │   ├── app/
    │   │   ├── [locale]/        # ⚠️ NO es núcleo del framework (ver §1.1) — vistas heredadas
    │   │   │                    #    una vez al clonar, responsabilidad de cada instancia
    │   │   ├── api/             # ⭐ el BFF (SÍ es núcleo): auth (+2fa,+email,+logout-all), store,
    │   │   │                    #    payments, webhooks, csrf, revalidate, health (+/live), contact
    │   │   ├── sitemap.xml/ sitemap/[segmento]/ _sitemap/  robots.txt/ _robots/  llms.txt/ _llms/
    │   │   ├── manifest.ts  not-found.tsx  layout.tsx (passthrough)
    │   ├── components/          # ⚠️ NO es núcleo del framework, salvo `ui/` (primitivas sin negocio).
    │   │                        #    layout/marketing/products/forms/cart/checkout/account/auth/i18n
    │   │                        #    se heredan al clonar y pasan a ser responsabilidad de la instancia.
    │   │                        #    Inventario de lo heredado: docs/FRONTEND_CONNECT.md §B.2.
    │   └── lib/                 # ⭐ toda la lógica de servidor/cliente (SÍ es núcleo, ver §6).
    │                            #    `lib/config/` = config dinámica del negocio (getSiteConfig(), §6.7);
    │                            #    `lib/loyalty/` = monedero y referidos (módulo `loyalty`, §6.9);
    │                            #    `lib/memberships/` = niveles, carné y carrito (módulo `memberships`, §6.9)
```

Cuando dudes de una ruta, **busca en `frontend/src/lib/`**: está organizado por dominio
(`auth/` —incl. `revocation.ts`—, `woocommerce/` —incl. `order-events.ts`—, `security/`
—`csrf`, `rate-limit`, `idempotency`, `lock`, `replay`, `secret-guard`, `webhook`, `origin`,
`cookies`, `sanitize`, `internal-secret`, `signed-routes`—, `catalog/`, `account/`, `payments/`,
`config/` —incl. `modules.ts`—, `api/` —`guard`, `flags`, `modules`—, `navigation/`, `http/`,
`redis/`, `observability/`, `validation/`, `client/`, `instance/` (tipos del contrato),
`i18n/` (`pickLocalized`, slugs por idioma), `geo/`, `shipping/`, `landings/`, `seo/`,
`analytics/`, `checkout/`, `blog/`, `wordpress/` —`wpFetch`—).

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

> Esta tabla se congela en cada release; el estado vivo entre releases está en
> `CHANGELOG.md` (`[Sin publicar]`). Actualizada por última vez: **v2.2.0**.
> Estados realistas (✅ completo · 🟡 parcial · 🧪 stub).

| Área | Estado |
|------|--------|
| Seguridad (cabeceras, CORS, hardening, `hwe-bff-only` con secreto, guard de arranque) | ✅ |
| Autenticación JWT (cookies httpOnly + refresh + revocación + cambio de contraseña) | ✅ |
| Proxy inverso a WooCommerce (BFF, ck/cs server-only, `wpFetch` único) | ✅ |
| CSRF + rate-limit + idempotencia (el duplicado ya detectado nunca procede; ver §9) | ✅ |
| E-commerce (catálogo, carrito, checkout ligado a cliente **o de invitado**, cuenta) | ✅ |
| Cuenta avanzada (wishlist, reseñas, direcciones — **meta atómica con lock**) | ✅ |
| Cupones (reglas, avisos) y envío (por ciudad fija o **por distancia** con pin del cliente, extras, gratis por opción) | ✅ (módulos `coupon-rules` y `shipping`) |
| Fidelización (puntos: ganar, caducar, canjear y transferir; referidos N1/N2) | ✅ (módulo `loyalty`) |
| Verificación de email (**opcional**, con expiración de token) | 🟡 (no bloqueante) |
| 2FA TOTP (secreto cifrado AES-GCM, recuperación, **sin tokens antes del segundo factor**) | ✅ |
| SEO + GEO (metadatos, JSON-LD, sitemap segmentado, robots y llms.txt por idioma) | ✅ |
| i18n: contenido del CMS a N idiomas (`hwe-content-i18n`) + slugs por idioma | ✅ |
| Paquetes por país (`hwe-geo`: dirección, documento, teléfono) | ✅ (`CO` y `generic`) |
| Analítica + consentimiento (GA4/GTM/Plausible/Meta/TikTok/HubSpot con opt-in, CAPI) | ✅ |
| Pagos: capa agnóstica + Wompi y Aval Pay de punta a punta + `noop` de pruebas | ✅ |
| Pagos: PayU y Bold | 🧪 stubs (501) |
| Webhooks de pedido (transición real + dispatcher de efectos) | 🟡 (email al cliente lo hace Woo; ganchos ERP por conectar) |
| HWE Control Center (config central, tipos de campo extensibles, secretos cifrados, backups desde el panel) | ✅ |
| Contrato de instancia (`mu-plugins/instance/`, `src/instance/`, registro, ejemplo vivo) + puerta anti-instancia | ✅ |
| Membresías (niveles y beneficios editables, catálogo exclusivo con acceso anticipado, descuento de socio, envíos gratis, carné con QR y regalos por referido) | ✅ (módulo `memberships`) |
| 11 módulos opcionales (`banners`, `shipping`, `product-landing`, `landings`, `consent-log`, `site-reviews`, `coupon-rules`, `customer-identity`, `external-integration`, `loyalty`, `memberships`) | ✅ |
| Campos de producto y detalles de línea de pedido declarativos | ✅ |
| Calidad / CI (lint, tipos, formato, i18n, Vitest, PHPUnit, build, audit, gitleaks, e2e con stub, «contrato», anti-instancia) | ✅ |
| Verificación en vivo (`verify-security/modules/integration.sh`) | ✅ (la compra completa `E2E_FULL` es opt-in) |
| Observabilidad (Sentry + pino + correlación request-id + alertas) | ✅ (métricas OTel pendientes) |
| Empaquetado comercial (licencia, docs, white-label) | ✅ |

El núcleo está **listo para producción**. Lo pendiente es endurecimiento adicional, más
paquetes de país, las pasarelas PayU/Bold, y verificar en vivo lo que el CI solo prueba con el
stub (compra completa, segunda pasada e2e sin módulos). El flujo crítico de compra (login o
invitado → carrito → checkout → pago por webhook → pedido en cuenta) está **íntegro**.

---

## 6. Dónde revisar cada flujo

### 6.1 Arranque e infraestructura
- `docker-compose.yml` — servicios y variables. `README.md` — pasos exactos.
- Primer arranque: `docker compose up -d` → `docker compose run --rm wpcli` (instala todo)
  → `generate-woo-keys.sh` (claves WC) → pegar en `.env` → `docker compose up -d frontend`.
- **Producción**: `docker-compose.prod.yml` + `Caddyfile` (TLS, HTTP/3). Servicios con
  `security_opt: no-new-privileges`. GraphQL público restringido a POST/OPTIONS en Caddy.
  Caddy sirve `/wp-content/*` desde disco (volumen del núcleo en solo lectura; los `.php` van a
  Apache); `wp-cron` ejecuta los eventos programados por WP-CLI (`DISABLE_WP_CRON`); el
  `Dockerfile` del frontend **congela** `WORDPRESS_PUBLIC_URL` (CSP + `remotePatterns`) y falla
  pronto sin ella. WordPress y el BFF comparten Redis: WordPress usa prefijo + flush selectivo,
  o un `wp cache flush` borraría las listas de revocación. Sitios opcionales de Caddy (panel en
  `admin.<dominio>`): `caddy/README.md`. Tras desplegar: `backend/scripts/warm-cache.sh`. Un test
  (`tests/unit/deploy-config.test.ts`) fija todo esto.
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
- **Tema**: `backend/wp-content/themes/hwe-headless-base` es el único tema que se versiona y
  el único que queda instalado tras `setup.sh` (borra todos los temas bundled de wordpress.org).
  No renderiza nada público — es headless, el frontend vive en Next.js. Solo satisface el
  requisito de WP de tener un tema activo (y las declaraciones de soporte que WooCommerce
  espera de cualquier tema).
- **Config de instancia**: `wp hwe setup <archivo.json>` (comando WP-CLI, `Cli.php`) siembra
  marca/diseño/redes/legal/ecommerce/SEO/envío/GEO/backups desde un JSON en un solo paso —
  ver `docs/CREATE_INSTANCE.md`. Se aplica automáticamente al final de `setup.sh` si existe
  `backend/scripts/instance.config.json` (plantilla: `instance.config.example.json`).
- Plugins/contenido se instalan vía `backend/scripts/setup.sh`.

### 6.3 Seguridad del frontend
- **Cabeceras + CSP**: `frontend/next.config.mjs` (función `headers()`).
- **Barrera de Origin** para escrituras `/api/*`: `frontend/src/proxy.ts` (función `proxy`).
- **CSRF** (signed double-submit): `lib/security/csrf.ts`; el cliente obtiene el token en
  `app/api/csrf/route.ts` y lo reenvía en `X-CSRF-Token`.
- **Rate-limit** (Redis, ventana fija): `lib/security/rate-limit.ts` + `lib/redis/client.ts`.
  Fail-open por defecto; con `strict: true` usa un **fallback en memoria** si Redis cae
  (se aplica en auth: login/registro/2FA/forgot/reset).
- **Idempotencia** (checkout): `lib/security/idempotency.ts`. Falla cerrado solo ante un
  duplicado **ya detectado** (si `SET NX` confirma la clave y la lectura falla → `conflict`, nunca
  `proceed`). Sin Redis, o si falla el propio `SET NX`, deja pasar y lo registra (`error`): no se
  sabe si hay duplicado y bloquear todo el checkout por una caída de Redis cuesta más que el
  riesgo de un doble envío.
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
- **WordPress solo atiende al BFF**: el mu-plugin `hwe-bff-only` responde 404 a GraphQL, la Store
  API y `hwe/v1/auth|consent|loyalty` salvo con `X-HWE-Internal-Secret` (= `HWE_REVALIDATION_SECRET`,
  comparación en tiempo constante) o desde WP-CLI. **Toda** llamada del BFF a WordPress pasa por un
  cliente que lo añade (`wpFetch` en `lib/wordpress/internal-fetch.ts`, o los clientes de GraphQL,
  wc/v3 y Store API); un test (`lib/security/wordpress-calls.test.ts`) rompe si una ruta nueva usa
  `fetch` a WordPress por su cuenta. `proxy.ts` es la única excepción (edge). Verificación en vivo:
  `sh backend/scripts/verify-security.sh`.
- **Rate-limit de WordPress y BFF**: `/graphql` (60/min) y `/wp-json/` (120/min) se limitan por IP
  en `rate-limit.php`, pero las llamadas con el **secreto interno válido** (la misma comprobación de
  `hwe-bff-only`, en `hwe-bff-secret.php`) **no gastan** ese presupuesto: por la red interna todas
  llegan con la IP del contenedor del frontend y unas decenas de compradores dejaban la tienda en
  429. Sin cabecera, con un secreto incorrecto o sin `HWE_REVALIDATION_SECRET` se cuentan como
  siempre; `wp-login.php` se cuenta siempre. El abuso a través del BFF lo acota el rate-limit por
  usuario/IP del BFF (`guardMutation`).
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
  `getAuthToken`). Los datos del usuario pasan por el BFF (`wc/v3` con comprobación de
  propietario), no por GraphQL como «viewer».
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
  `requires_2fa` + un **id opaco de un solo uso** (5 min; campo `ephemeralToken`, que NO es
  un JWT) y el cliente completa en `verify-2fa`. Las credenciales reales NUNCA viajan al
  navegador antes del segundo factor: viven en Redis (`lib/auth/pending-login.ts`). Sin
  Redis, el login con 2FA **falla cerrado** (503). El id se reclama con `GETDEL` atómico y
  se invalida tras 5 códigos incorrectos (hay que volver a iniciar sesión).
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
- **Compra de invitado:** `ecommerce.checkout_login_required` decide quién compra. Sin sesión, el
  pedido es de invitado y su `order_key` va en la cookie httpOnly `hwe_guest_order`
  (`lib/checkout/`): autoriza cobro, estado y «gracias» (`canAccessOrder`, anti-IDOR) y el pedido
  pasa a la cuenta al abrir sesión con el mismo correo (`claimGuestOrder`).
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

### 6.7 Config dinámica del negocio, vistas y feature flags
- **`getSiteConfig()`** (`lib/config/index.ts`) es la ÚNICA fuente de verdad de la config de
  marca/negocio en el frontend: hace fetch server-only (cacheado con `cache()` de React) a
  `/wp-json/hwe/v1/config` con fallback a `CONFIG_DEFAULTS` si WP no responde. **No existe
  ningún otro sitio "estático" con la URL/marca del sitio** — si ves un valor de marca
  hardcodeado o un import que no sea `getSiteConfig()`, es un bug (había uno: el sitemap y
  `robots.txt` usaban una URL de build-time en vez de `config.brand.url`; corregido). Si WordPress no
  responde, `isFallbackSiteConfig(config)` lo dice: quien publica URLs absolutas (sitemap, robots,
  llms.txt) se calla en vez de publicar el dominio por defecto.
- **Flags de funcionalidad opcional** (`config.ecommerce.reviews_enabled`, `wishlist_enabled`,
  `coupons_enabled`, `search_enabled`, configurables desde `wp-admin → HWE Config`): **toda
  vista que renderice esa funcionalidad debe comprobar el flag primero**. Ejemplos ya
  aplicados: `components/products/product-card.tsx` (wishlist), la sección de reseñas en
  `app/[locale]/products/[slug]/page.tsx`, el cupón en `cart-drawer.tsx`/`checkout-form.tsx`,
  el enlace de wishlist en `account/layout.tsx`. Si añades una vista nueva que use una de
  estas funcionalidades, replica el patrón — no la muestres incondicionalmente.
- **Menús**: se gestionan en WP (Apariencia → Menús) y se leen con `getMenu(area, locale)`
  (`lib/navigation/menu.ts`, fail-soft a `null` → fallback local del componente).   **Banners**: los gestiona el plugin **HWE Banners** (CPT `hwe_banner`, REST
  `hwe-banners/v1/banners`). El lector core `lib/banners/` (`getBanner` / `getBanners`) los
  consume con ISR (tag `banners`), gateado por `config.banners.enabled`. Desde el HWE
  Control Center solo se activa/desactiva; la autoría vive en el plugin.
  **Logo**: `config.brand.logo` (vacío = nombre del sitio como texto).
- **Recuerda §1.1**: todo lo de esta sección que vive en `components/**` (salvo `ui/`) y en
  `app/[locale]/*` es código **heredado, no núcleo del framework**. `docs/FRONTEND_CONNECT.md`
  documenta el contrato backend/BFF estable y, aparte, un inventario de ese código heredado tal
  como quedó al escribir esto — no una promesa de que seguirá existiendo o actualizándose.
  Para levantar el frontend de una instancia nueva (qué preguntar del negocio antes de tocar
  vistas, propias o heredadas): **`docs/FRONTEND_BUILD.md`**.
- **Routing por idioma**: todo bajo `app/[locale]/`. `es` sin prefijo (canónico), `en` en
  `/en`. Config en `i18n/routing.ts`; locale resuelto en `i18n/request.ts`.
- **Navegación locale-aware**: usa SIEMPRE `Link`/`redirect`/`useRouter` de
  `i18n/navigation.ts` (no los de `next/*`), o se pierde el idioma al navegar.
- **Textos**: `i18n/messages/es.json` y un catálogo por cada idioma secundario de
  `routing.locales` (`en.json` en el framework; una instancia monolingüe solo tiene `es`).
  Selector: `components/i18n/locale-switcher.tsx`.
- **SEO/GEO**: `lib/seo/` es la única fuente (`jsonld`, `opengraph`, `title`, `meta-description`,
  `page-metadata`, `urls`, `breadcrumbs`, `listing`, `safe-json-ld`, `og-static`). Rutas de máquina:
  sitemap segmentado (`app/_sitemap/entries.ts`), `robots.txt` (`app/_robots/build.ts`) y
  `llms.txt` por idioma (`app/_llms/build.ts`); una instancia añade páginas y excluye rutas del
  sitemap/`llms.txt` con **datos** en `InstanceRegistry.sitemap` (`lib/instance/sitemap.ts`), sin
  editar `entries.ts`; las funciones puras viven en carpetas privadas
  `_x/` porque un route handler solo puede exportar métodos HTTP. Toda página indexable declara
  `pageMetadata`; toda vista privada `privateMetadata` (lo impone un test sobre `PRIVATE_PATHS`);
  todo JSON-LD pasa por `safeJsonLd`. Todo lee `getSiteConfig()`, nada tiene la URL o la marca
  hardcodeada.
- **Utilidades de resistencia/rendimiento**: `lib/http/fetch-retry`, `lib/wordpress/media` +
  `components/ui/cms-image`, `lib/prerender-budget`, `scripts/static-generation-options.mjs`,
  `lib/navigation/safe-return` (`?return=` anti open-redirect) y `lib/i18n/client-messages`
  (solo viajan al navegador los namespaces de los Client Components).
- **Blog**: `lib/blog/` (lecturas, `category-format`, `post-content`, `post-view`,
  `extract-product-links`…), `/api/blog/posts` (scroll infinito, con topes) y las vistas
  `blog/`, `blog/category/[slug]`, `blog/archive`. Criterio único de disponibilidad de una
  categoría: `categoryPageAvailable`. Vistas con filtro: `noindex, follow` y sin `alternates`.
- **Analítica/consentimiento**: `components/analytics/` (banner opt-in) y `lib/analytics/`. Los
  servicios (GA4, GTM, Plausible, Meta Pixel + Conversions API, TikTok, HubSpot) salen de
  `config.integrations` (Control Center); `resolveTrackingServices` es la ÚNICA fuente de qué hay
  que consentir. Los scripts cargan **solo tras «Aceptar»**. Eventos con la forma de GA4
  (`lib/analytics/ecommerce.ts`) vía `trackEvent()` (`view_item` en la ficha, `add_to_cart` en
  `cart-context`, `begin_checkout` en el checkout, `purchase` en `checkout/return`). La compra
  también sale desde el servidor (`sendPurchaseToMeta`, tras el webhook verificado) con el
  contexto que el checkout guardó en el pedido. Hosts de la CSP: `csp-analytics.mjs`
  (`CSP_ANALYTICS_PROVIDERS` los estrecha). La decisión vive en `localStorage["cookie-consent"]`
  (JSON: generación + alcance de servicios + comprobante + cola, `consent-storage.ts`). Con el
  módulo `consent-log`, cada decisión se registra como prueba (`/api/consent` →
  `consent-ledger.ts` → plugin `hwe-consent-log`, cookie `hwe_consent`) y la respuesta al banner
  se mide en Redis sin identificar a nadie (`/api/consent-stats`).

### 6.8 Pagos (Fase 7 — capa de pasarelas agnóstica)
- **Contrato e implementación** en `lib/payments/`: `types.ts` (`PaymentProvider`,
  `PaymentError`), `registry.ts` (registro + selección por `PAYMENT_PROVIDER`),
  `signature.ts` (HMAC/SHA + `safeEqual`), `orders.ts` (máquina de estados del pedido vía
  wc/v3) y `providers/` (`wompi` y `avalpay` de punta a punta, `noop` de pruebas, stubs `payu`/`bold`).
  La pasarela activa y sus credenciales vienen del Control Center (`credentials.ts`, caché en
  memoria); `PAYMENT_PROVIDER` solo la anula. Medios del checkout (pago en línea, link por
  WhatsApp, efectivo): `checkout-options.ts`, ver `docs/FRONTEND_CONNECT.md` (A.2 «Pagos»).
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

### 6.9 Módulos opcionales
- Un módulo es un plugin regular `backend/wp-content/plugins/hwe-<id>/` (con `README.md` técnico
  y `MANUAL.md` para editores). Catálogo: `banners`, `shipping`, `product-landing`, `landings`,
  `consent-log`, `site-reviews`, `coupon-rules`, `customer-identity`, `external-integration`,
  `loyalty`, `memberships` (qué hace cada uno: `docs/CONFIGURATION.md`, «Módulos»).
- Se encienden con la clave `modules` de `instance.config.json` (`wp hwe setup` activa los
  listados y **desactiva los demás**) o desde `wp-admin → Plugins`. La config pública expone
  `modules: { <id>: boolean }`, calculado según si el plugin está activo.
- **Envío por distancia (`shipping`):** cada ciudad es de tarifa fija o por distancia (nunca ambas).
  Las tarifas las calcula WooCommerce con el método `hwe_city_rate` (`ShippingStatus` es la única
  decisión; `Pricing`, el único precio). La distancia sale de proveedores intercambiables
  (`hwe_shipping_distance_providers`; Google, OpenRouteService o línea recta + Nominatim, que es el
  respaldo) y la clave de mapas **nunca sale de WordPress**. El pin del cliente viaja por la extensión
  `hwe_shipping` de la Store API y vale solo mientras coincidan calle, ciudad y país; sin pin válido no
  hay tarifa y el checkout del BFF responde 409. Contrato completo en `docs/FRONTEND_CONNECT.md`
  («Envío por distancia»); detalle en el `README.md` del plugin.
- **Con el módulo apagado**: el lector devuelve vacío o nulo, sus rutas BFF responden 404
  (`requireModule(config, id)` en `lib/api/modules.ts`, igual que `requireFlag` para los flags de
  la tienda) y su UI no se pinta (`isModuleEnabled`). Cada módulo registra su tag ISR en
  `hwe_revalidation_tags`. Comprobación en vivo: `sh backend/scripts/verify-modules.sh`; en CI hay
  una segunda pasada e2e sin módulos (`tests/e2e/modules-off.spec.ts`; en una instancia, los
  specs de su carpeta `tests/e2e/instance/` contra la instalación sin módulos).
- **`loyalty`** suma sus propios interruptores en `config.loyalty` (`points_enabled`,
  `referrals_enabled`, `transfers_enabled`): `requireLoyalty` (`lib/loyalty/guard.ts`) corta con
  404 la ruta de la función apagada, aunque el plugin esté activo. El canje viaja por la Store API
  (extensión `hwe-loyalty`) y la prueba de un pedido pagado con puntos es la meta
  `_hwe_loyalty_paid_with_points`, escrita contra el libro; nunca el navegador.
- **`memberships`** (niveles y ventajas, catálogo exclusivo y carné): el plugin
  `hwe-memberships` recorta el producto bloqueado **en WordPress** (GraphQL por lista blanca,
  Store API —también por slug—, `wc/v3` del BFF y `wp/v2/product`; con requisitos en el catálogo la
  búsqueda compara solo el nombre); el frontend solo pide `hweMembership` cuando
  `config.modules.memberships` está encendido (`membershipsCatalogEnabled()`), porque el campo no
  existe en el esquema apagado. El BFF expone `/api/memberships/{levels,me,history,prices,
  products/[slug],card,verify/[token]}` y `POST|DELETE /api/store/cart/free-delivery`; todos con
  la guarda del módulo (`requireModule` o `requireMembershipCard` para el carné) y el `user_id`
  de la sesión. El carrito publica `extensions.hwe-memberships`
  (`level`, `member_discount` en unidades menores, `free_delivery`, `blocked_items`,
  `purchase_notice`). El checkout responde **409** con un `reason` estable si el nivel cambió con
  el carrito lleno. Contrato completo: `docs/FRONTEND_CONNECT.md` («Membresías»). `loyalty` y
  `shipping` solo se tocan por sus hooks publicados.
- Añadir un módulo: ver `docs/PLUGIN_DEVELOPMENT.md` (README + MANUAL, tag ISR, lector con
  `requireModule`, tests PHPUnit con dobles en `backend/tests/Stubs/`, stub de CI y
  `STUB_SITE_CONFIG_EXTRA`).

### 6.10 Contenido a N idiomas
- Backend: mu-plugin `hwe-content-i18n`. Un campo declarado en el registro `hwe_translatable_fields`
  guarda su traducción en metas `_hwe_<campo>_<sufijo>` (`pt-BR` → `pt_br`) y GraphQL expone
  `translation(locale)` y `hweSlugFrom(postType, locale, slug)`. Los idiomas son `i18n.locales`
  del Control Center (los secundarios; `brand.locale` es el principal).
- Frontend: `pickLocalized(entity, field, locale)` (`lib/i18n/`) y los lectores leen
  `translation(locale)`; cada idioma tiene su **slug** y la URL con otro slug responde **308** a la
  canónica. Sin traducción no hay hreflang ni entrada en el sitemap. `routing.ts` debe coincidir
  con `i18n.locales` (`instrumentation.ts` lo avisa) y cada idioma necesita su
  `messages/<idioma>.json` (`npm run i18n:check`).

### 6.11 Paquetes por país
- `hwe-geo` (mu-plugin) sirve el paquete del país de `ecommerce.country` en `GET hwe/v1/geo`
  (ignora parámetros): divisiones, ciudades, tipos de documento y patrones, prefijo telefónico y
  forma de la dirección. Paquetes: `CO` y `generic` (todo texto libre); se añaden o sustituyen
  con el filtro `hwe_geo_packs`.
- Frontend: `getGeoPack()` (`lib/geo/`, tag ISR `geo`). Checkout, cliente y libreta de
  direcciones validan el estado contra el paquete y responden **422** si no resuelve (no caen a
  un valor por defecto). El módulo `customer-identity` usa los tipos de documento del paquete.

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
- **Mensajes i18n paralelos**: si añades una clave en `es.json`, añádela en el catálogo de cada
  idioma secundario de `routing.locales` (`en.json` en el framework; una instancia monolingüe no
  tiene ninguno y `npm run i18n:check` no compara nada).
- **Importes de la Store API** vienen en **unidades menores** (céntimos): formatéalos con
  `formatStoreAmount` de `lib/format.ts`. Los precios de WooGraphQL vienen ya formateados:
  usa `formatPrice`.
- **HTML del CMS** que se inyecte con `dangerouslySetInnerHTML` debe pasar por
  `sanitizeHtml` (`lib/security/sanitize.ts`).
- **Tailwind v4 es CSS-first**: el tema vive en `globals.css` (`@theme`), no hay
  `tailwind.config`. Colores de marca (clases utilitarias, nunca hex hardcodeado):
  `brand`, `brand-dark`, `brand-light`, `secondary`, `secondary-dark`, `accent`, `surface`.
- **Módulos y flags se respetan en el BFF**, no solo en la UI: `requireModule` / `requireFlag`
  al inicio de la ruta (404). Un campo, ruta o texto de **una instancia concreta** no entra en el
  núcleo: va en `mu-plugins/instance/`, `src/instance/` o un plugin `hwe-instance-*` (§1.1).
- **Ningún fallo se traga en silencio**: lo que degrada se registra con un `event` estructurado
  (`logReadFailure`, `logRedisFailure`, `graphql.request_failed`; `error_log('[hwe] <módulo>: …')`
  en WordPress). Un control de seguridad que falla abierto se registra como `error`.
- **Seguridad falla cerrada, contenido falla suave**: sin secreto/Redis/salts el control responde
  cerrado; una lectura de contenido cae a su valor por defecto. Las fichas propagan un fallo de
  WordPress (500) y no lo convierten en `notFound()`.
- **La marca/URL del sitio SIEMPRE sale de `getSiteConfig()`**, nunca de una constante o env
  var leída directamente en un componente. **Los flags `config.ecommerce.*_enabled` deben
  gatear la UI correspondiente** (no solo afectar JSON-LD). Ver §6.7 y `docs/FRONTEND_CONNECT.md`.

---

## 8. Cómo correr y verificar

Desde `frontend/`:
```bash
npm run dev            # desarrollo
npm run type-check     # tsc --noEmit
npm run lint           # ESLint (flat config; `next lint` se eliminó en Next 16)
npm run format:check   # Prettier (src/ y tests/); `npm run format` lo aplica
npm run i18n:check     # paridad de mensajes entre idiomas (claves, forma, argumentos)
npm run test           # Vitest: tests COLOCADOS junto al código + tests/unit
npm run test:coverage  # umbrales de trinquete en vitest.config.ts (se suben, nunca se bajan)
npm run audit:gate     # npm audit con allowlist (el gate de CI)
npx next build         # ⭐ la verificación definitiva (prerender + tipos + middleware edge)
```
Desde `backend/`: `composer test` (PHPUnit 12 + Brain Monkey; los dobles de WordPress viven en
`tests/Stubs/`; Brain Monkey no ejecuta los callbacks de `add_filter`, usa `expectApplied`).

**Pila completa — dos modos:**

| Modo | Comando | Descripción |
|------|---------|-------------|
| **Docker puro** | `docker compose up -d` + `wpcli` | Todo en contenedores (más lento en Windows) |
| **Híbrido** (recom.) | `docker compose up -d db redis` + `start-local.bat` + `npm run dev` | WordPress y frontend nativos; DB/Redis en Docker |

Para desarrollo pesado, el **modo híbrido** evita la latencia de bind mounts Docker
(WordPress nativo con `php -S 0.0.0.0:8080`, DB en `DB_PORT` —`3307` por defecto—, frontend con
`npm run dev`). Varias pilas en la misma máquina: `HWE_CONTAINER_PREFIX`, `COMPOSE_PROJECT_NAME`,
`DB_PORT` y `REDIS_PORT` (`docs/CREATE_INSTANCE.md` §2); los `verify-*.sh` resuelven el contenedor
por servicio de Compose y abortan si es de otra pila.
Ver `docs/INSTALL.md` y `README.md` para instrucciones detalladas.

E2E (Playwright): `npx playwright test` (smoke + accesibilidad axe + módulos) contra una app ya
levantada. En CI y en local corre contra un **stub** de GraphQL/WordPress
(`.github/scripts/stub-graphql.mjs`; la config pública del stub se amplía con
`STUB_SITE_CONFIG_EXTRA`). El e2e de **compra completa** (`tests/e2e/purchase.spec.ts`) es
opt-in: requiere la pila sembrada y `E2E_FULL=1` (opcionalmente `E2E_PRODUCT_ID`).

**Verificación en vivo contra la pila** (lo que un mock no ve; ejecútalos al cerrar una fase):
`sh backend/scripts/verify-security.sh`, `verify-modules.sh` y `verify-integration.sh`.

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
- **Rate-limit es fail-open por defecto**, pero los endpoints de escritura de auth (`guardMutation`) usan `strict: true`,
  que degrada a un **contador en memoria** si Redis cae (no se queda sin límite).
- **Sesión revocable**: `logout` revoca el access token (blocklist en `lib/auth/revocation.ts`,
  consultada en `getSession`); `/api/auth/logout-all` y el **cambio de contraseña** rotan el "user
  secret" en WP e invalidan de verdad los refresh tokens (el plugin JWT 0.7 no compara ese secreto
  por sí solo: lo impone un filtro propio, `enforceRefreshTokenSecret`). El refresh **no rota en
  cada uso** (limitación del plugin WPGraphQL JWT).
- **`@tailwindcss/typography` YA está instalado** (en `devDependencies`): `prose` está
  disponible para las descripciones de producto. *(Corrige una nota antigua que decía lo contrario.)*
- **Sentry YA está cableado**: `sentry.{server,edge}.config.ts` + `src/instrumentation.ts` y, en el
  navegador, `src/instrumentation-client.ts` (carga diferida; sin `NEXT_PUBLIC_SENTRY_DSN` no viaja
  el SDK). Replay es opt-in (`NEXT_PUBLIC_SENTRY_REPLAY`). Ver `docs/OBSERVABILITY.md` §2.1.
  El logger usa `pino` con eventos estructurados.
- **Redis es compartido entre WordPress y el BFF**: WordPress usa el prefijo `hwe-wp:` y un flush
  *selectivo*; un `wp cache flush` a secas borraría las listas de revocación, el rate-limit y la
  idempotencia del BFF.
- **Un route handler solo puede exportar métodos HTTP**: la lógica pura de sitemap/robots/llms.txt
  vive en carpetas privadas `_x/` (`app/_sitemap`, `_robots`, `_llms`).
- **Fichas y listas con Suspense duplican texto en los tests e2e** (marcador oculto del streaming):
  acota los `getByText` a `#main-content`. Los status HTTP de `notFound()` en vistas en streaming
  son 200: comprueba el contenido.
- **Tras cambiar la config del stub de CI** borra `frontend/.next/cache` y recompila: la caché de
  datos de Next conserva la config vieja entre builds.
- **`next` va con versión exacta**: el parche de cabeceras ISR (`scripts/patch-next-isr-headers.mjs`,
  `postinstall`) comprueba la versión y falla ruidosamente si cambia; súbela a propósito.
- **`WORDPRESS_PUBLIC_URL` es build-arg obligatorio** del `Dockerfile` del frontend (CSP y
  `remotePatterns` se congelan al construir): sin ella la imagen no se construye.
- **Tests**: los de `src/` van colocados; cada fix de seguridad se prueba en rojo contra el
  código anterior y, si es una propiedad crítica, con una mutación. En PHPUnit 12 usa
  `#[RunTestsInSeparateProcesses]` a nivel de clase si el test define constantes. Los tests con
  `fetch` simulado no ven que una ruta no exista o rechace un argumento: añade la verificación en
  vivo (`verify-*.sh`).
- **Stripe/PayPal NO se usan**: la capa de pagos es agnóstica para pasarelas LATAM; solo
  `wompi` y `avalpay` están de punta a punta; `noop` es solo de pruebas (su webhook solo se
  atiende si es la activa) y PayU/Bold siguen siendo stubs (501).
- **El secreto interno del BFF** para llamadas server-to-server protegidas (p. ej. `2fa-status`)
  es `HWE_REVALIDATION_SECRET`, enviado en la cabecera `X-HWE-Internal-Secret`.
- **No dupliques emails transaccionales**: WooCommerce ya los envía al cambiar el estado del
  pedido (ver `hwe-email-chrome.php` y las plantillas del tema base). El dispatcher de `order-events` es para
  integraciones, no para correos al cliente.
- **Correlación por `request-id`**: Caddy genera `X-Request-Id`, el `proxy` lo reutiliza/
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
3. **Verifica con `next build`** (y `tsc`/`lint`/`i18n:check`, y `composer test` si tocaste PHP)
   antes de declarar algo terminado. El CI además falla si aparece un resto de instancia (§1.1).
4. **Sé honesto sobre lo no verificado en vivo**: muchas cosas requieren la pila Docker con
   WordPress real (login, carrito, checkout, webhooks). Si no la levantaste, dilo.
5. **No metas secretos en el repo**: solo `.env.example` con placeholders.

---

## 11. Documentación (para clientes, operación y agentes)

- **Cliente/instalación**: `docs/INSTALL.md`, `docs/CREATE_INSTANCE.md` (crear una instancia
  nueva: clonar + `wp hwe setup` + `generate-secrets.sh`), `docs/CONFIGURATION.md`,
  `docs/CUSTOMIZATION.md`, `docs/DEPLOYMENT.md`, `docs/SECURITY.md`, `docs/ACCESIBILIDAD.md`,
  `docs/PLUGIN_DEVELOPMENT.md` (el contrato núcleo ↔ instancia y desarrollar plugins y módulos).
- **Actualizar**: `docs/UPGRADE.md` (procedimiento de actualización de una instancia y registro de
  cambios incompatibles v1 → v2).
- **Para agentes que van a tocar el frontend de una instancia** (recuerda §1.1: UI/vistas NO
  son núcleo del framework):
  - `docs/FRONTEND_CONNECT.md` — el contrato backend/BFF estable (GraphQL, REST del BFF,
    `/wp-json/hwe/v1/config`, flags, auth) que cualquier frontend debe respetar, más un
    inventario de la UI heredada al clonar (qué hay, qué está conectado, qué es un building
    block sin usar) — ese inventario es una foto del momento, no algo que el framework
    mantenga.
  - `docs/FRONTEND_BUILD.md` — entrevista de características del negocio (catálogo, sedes,
    funcionalidades opcionales, contenido editorial, envío/pagos, SEO/GEO, idiomas) y cómo
    traducir las respuestas a `instance.config.json` + un plan concreto de vistas a construir
    (reutilizando lo heredado, o desde cero).
- **Operación**: `docs/RUNBOOK.md` (backup/restore probado, rotación de secretos, cierre de
  sesiones, incidentes) y `docs/GO-LIVE.md` (checklist de cero a producción, incluye el guard
  de secretos que **aborta el arranque** en prod con valores por defecto).
- **Observabilidad**: `docs/OBSERVABILITY.md` (correlación request-id, eventos de alerta,
  retención de logs y PII).
- **Compatibilidad**: `docs/COMPATIBILITY.md` (matriz de versiones soportadas).
- **Cambios**: `CHANGELOG.md` (Keep a Changelog + SemVer).
