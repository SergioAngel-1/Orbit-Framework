---
description: Skill para analisis el sistema de la plantilla e-commerce WP Headless + React
---

# E-Commerce Template — Guía Completa del Sistema

> **Propósito:** Este documento es tu fuente de verdad para entender, modificar y depurar esta plantilla de e-commerce. Léelo COMPLETO antes de hacer cualquier cambio. Cada sección incluye las rutas exactas de los archivos relevantes.

---

## 1. ARQUITECTURA GENERAL

```
┌─────────────────────────────────────────────────────────────────┐
│                        FRONTEND (React)                         │
│  React 19 + TypeScript + Vite + TailwindCSS + PWA              │
│  Ruta: Frontend/src/                                            │
│                                                                 │
│  ┌──────────┐  ┌──────────┐  ┌────────┐  ┌──────────────────┐ │
│  │ Contexts  │  │ Services │  │ Hooks  │  │ Components/Pages │ │
│  └────┬─────┘  └────┬─────┘  └───┬────┘  └───────┬──────────┘ │
│       │              │            │                │             │
│       └──────────────┴────────────┴────────────────┘             │
│                          │                                       │
│                     Axios (apiConfig.ts)                         │
│                          │                                       │
│          ┌───────────────┼───────────────┐                       │
│          │ JWT + CSRF    │ OAuth 1.0a    │                       │
│          │ (producción)  │ (desarrollo)  │                       │
│          └───────┬───────┴───────┬───────┘                       │
└──────────────────┼───────────────┼───────────────────────────────┘
                   │               │
            REST API (wp-json/)    │
                   │               │
┌──────────────────┼───────────────┼───────────────────────────────┐
│                  BACKEND (WordPress Headless)                     │
│                                                                   │
│  ┌─────────────────────┐  ┌──────────────────────────────────┐   │
│  │ Plugin: site-settings│  │ Tema: Starter                    │   │
│  │ (config central,     │  │ (auth, CORS, seguridad,          │   │
│  │  REST API, admin UI) │  │  WC proxy, endpoints custom)     │   │
│  └─────────────────────┘  └──────────────────────────────────┘   │
│                                                                   │
│  ┌────────────────────┐  ┌──────────────┐  ┌────────────────┐   │
│  │ Plugin: starter-   │  │ Plugin:      │  │ Plugin:        │   │
│  │ memberships        │  │ starter-     │  │ starter-home-  │   │
│  │ (OPCIONAL)         │  │ referrals-   │  │ sections       │   │
│  │                    │  │ points       │  │ (OPCIONAL)     │   │
│  │                    │  │ (OPCIONAL)   │  │                │   │
│  └────────────────────┘  └──────────────┘  └────────────────┘   │
│                                                                   │
│  WooCommerce (productos, pedidos, categorías)                     │
└───────────────────────────────────────────────────────────────────┘
```

### Comunicación Frontend ↔ Backend

- **Producción:** Frontend en dominio público → WC Proxy seguro (`/starter/v1/wc/*`) → WooCommerce. El proxy maneja OAuth server-side, el frontend solo envía JWT.
- **Desarrollo:** Frontend puede usar OAuth 1.0a directo contra `/wc/v3/*` (controlado por `VITE_USE_WC_PROXY`).
- **Validación crítica:** En producción, si `VITE_USE_WC_PROXY !== 'true'`, la app lanza error fatal para prevenir exposición de credenciales OAuth en el frontend.

---

## 2. ESTRUCTURA DE DIRECTORIOS

### Frontend (`Frontend/src/`)

```
src/
├── App.tsx                    # Router principal, providers anidados, lazy loading
├── main.tsx                   # Entry point: PWA register, migration, iOS vh fix
├── config.ts                  # Constantes globales y fallbacks
├── config/
│   └── i18n.ts                # Configuración i18next (ES/EN)
├── contexts/                  # React Contexts (estado global)
│   ├── SiteConfigContext.tsx   # ★ Config central + CSS variables + Google Font
│   ├── AuthContext.tsx         # Auth JWT, modales login/register, user state
│   ├── CartContext.tsx         # Carrito híbrido (localStorage + server sync)
│   ├── CategoriesContext.tsx   # Categorías de WooCommerce
│   ├── HomeSectionsContext.tsx # Secciones dinámicas del home
│   ├── LanguageContext.tsx     # i18n ES/EN con prefijo de ruta (/en/*)
│   ├── MembershipContext.tsx   # Membresías y beneficios
│   ├── ModalContext.tsx        # Sistema global de modales
│   ├── ReviewsContext.tsx      # Reseñas de productos
│   ├── types/                 # Tipos de los contextos
│   └── utils/                 # Utils de auth, cart, profile
├── services/
│   ├── apiConfig.ts           # ★ Axios instances, OAuth, JWT, CSRF, interceptors
│   ├── apiServices.ts         # Barrel de todos los servicios (re-export)
│   ├── api.ts                 # Re-export público de apiServices
│   ├── auth/                  # Login, register, tokens
│   ├── cart/                  # Cart híbrido (local + server)
│   ├── payments/              # ★ PaymentGateway factory + WompiGateway
│   ├── products/              # CRUD productos WooCommerce
│   ├── categories/            # Categorías
│   ├── orders/                # Pedidos
│   ├── points/                # Puntos/moneda virtual
│   ├── system/                # Estado del sistema (features, config pública)
│   ├── reviews/               # Reseñas
│   ├── banners/               # Banners del home
│   ├── home/                  # Secciones del home
│   ├── legal/                 # Documentos legales
│   ├── membership/            # Servicio de membresías
│   ├── popups/                # Popups dinámicos
│   ├── query/                 # Cache manager para peticiones
│   ├── wompiService.ts        # Servicio directo de Wompi (legacy)
│   └── alertService.ts        # Sistema de alertas (alertify)
├── hooks/                     # Custom hooks
│   ├── usePaymentGateway.ts   # ★ Hook abstracto de pasarela de pago
│   ├── useWompi.ts            # Hook específico Wompi
│   ├── useCheckoutSubmit.ts   # Flujo completo de checkout
│   ├── useCheckoutForm.ts     # Validación del formulario
│   ├── useCheckoutPoints.ts   # Puntos en checkout
│   ├── useProductsPaginated.ts # Infinite scroll de productos
│   ├── useMembershipLevels.ts # Niveles de membresía
│   ├── useMembershipDiscount.ts # Descuentos por membresía
│   └── ...                    # 34 hooks en total
├── types/
│   ├── siteConfig.ts          # ★ Interfaces SiteConfig, SiteBranding, defaults
│   ├── woocommerce.ts         # Tipos WooCommerce (Product, Category, etc.)
│   ├── payment.ts             # Tipos PaymentGateway, PaymentTransaction
│   └── wompi.ts               # Tipos específicos Wompi
├── pages/                     # 22 páginas (lazy-loaded excepto HomePage)
├── components/                # Componentes organizados por dominio
│   ├── auth/                  # Login, Register, modales auth
│   ├── cart/                  # Carrito
│   ├── checkout/              # Proceso de pago
│   ├── home/                  # Secciones del home (banners, categorías, etc.)
│   ├── products/              # Cards, grids, filtros
│   ├── profile/               # Perfil, direcciones, tarjeta digital
│   ├── layout/                # Header, Footer, Sidebar, Navigation
│   ├── membership/            # Componentes de membresía
│   ├── ui/                    # Componentes genéricos (Loader, AddToCartButton, etc.)
│   └── ...
├── utils/
│   ├── formatters.ts          # Formato de moneda (con cache de localStorage)
│   ├── secureStorage.ts       # Wrapper de localStorage con encriptación
│   ├── fluidSizing.ts         # Sistema de tamaños fluidos responsive
│   ├── logger.ts              # Logger con niveles (dev/prod)
│   ├── errorHandler.ts        # Manejo centralizado de errores
│   ├── seo.ts                 # Utilidades SEO
│   └── ...
├── locales/                   # Traducciones i18next (ES/EN, por namespace)
└── styles/                    # CSS adicionales
```

### Backend — Plugin `site-settings` (`Wordpress/app/public/wp-content/plugins/site-settings/`)

```
site-settings/
├── site-settings.php          # ★ Clase principal (singleton), defaults, fields config
├── includes/
│   ├── helpers.php            # ★ site_get_option(), site_get_all_config(), transient cache
│   ├── class-settings-page.php # UI del admin (tabs, render_field por tipo)
│   └── class-rest-api.php     # REST API: GET/PUT /site-settings/v1/config
└── assets/
    └── admin.js               # JS para el panel admin (color pickers, media uploader)
```

### Backend — Tema `Starter` (`Wordpress/app/public/wp-content/themes/Starter/`)

```
Starter/
├── functions.php              # Entry point: require_once de todos los módulos
├── inc/
│   ├── init.php               # ★ Carga condicional de módulos (WooCommerce check)
│   ├── security/              # CSRF, JWT middleware, headers, rate limiting, REST access
│   ├── cors-functions.php     # CORS unificado
│   ├── woocommerce/           # ★ Proxy WC: orchestrator, http-client, cache, permisos
│   ├── payments/              # ★ Interface PaymentGateway + factory + WompiGateway
│   ├── wompi/                 # Integración completa Wompi (21 archivos)
│   ├── custom-auth-endpoint.php # Login/register custom endpoints
│   ├── jwt-auth-helper.php    # Helper JWT (cookie cleanup)
│   ├── rate-limiting.php      # Rate limiting por IP/usuario
│   ├── cart-endpoint.php      # Endpoints del carrito server-side
│   ├── order-validation.php   # Validación server-side de pedidos
│   ├── translation-fields.php # Campos i18n para productos/categorías
│   ├── reviews/               # Sistema de reseñas
│   ├── sitemap-generator/     # SEO sitemaps
│   └── ...                    # ~50 archivos más
└── style.css                  # Metadata del tema
```

---

## 3. FLUJOS CRÍTICOS

### 3.1 Flujo de Configuración del Sitio (SiteConfig)

```
WordPress Admin → Plugin site-settings (tabs UI)
    ↓ save
wp_options (site_settings_*)
    ↓ invalidate transient
GET /site-settings/v1/config
    ↓ helpers.php → site_get_all_config()
    ↓ (transient cache 5min + feature flags en tiempo real)
    ↓ response: { success, data: { identity, branding, currency, ... , features } }
    ↓
SiteConfigContext.tsx → fetchConfig()
    ↓ json.data ?? json  (deserialización)
    ↓ setConfig() + setCachedConfig() (localStorage 5min)
    ↓
useEffect([config.branding]) →
    ├── injectCSSVariables()     → :root CSS custom properties
    ├── injectGoogleFont()       → <link> dinámico Google Fonts
    ├── updateThemeColor()       → <meta name="theme-color">
    └── updateFavicons()         → <link rel="icon">
```

**Puntos clave:**
- La REST API envuelve la respuesta en `{ success, data: {...}, cached }`. El frontend extrae `json.data ?? json`.
- Features (`memberships`, `referrals_points`, `home_sections`, `woocommerce`) se inyectan **fuera** del transient cache para reflejar cambios inmediatos de plugins.
- El frontend cachea en `localStorage` con key `site_config_cache` y TTL de 5 minutos.
- Los defaults están definidos en DOS lugares sincronizados:
  - Backend: `site-settings.php` → `get_defaults()`
  - Frontend: `types/siteConfig.ts` → `DEFAULT_SITE_CONFIG`

### 3.2 Sistema de Theming Dinámico (Colores)

```
Admin WP configura:
  ├── 3 colores base: primary, secondary, accent (hex)
  └── 5 variaciones derivadas, cada una con:
      ├── source: 'primary' | 'secondary' | 'accent'
      ├── mode:   'darken' | 'lighten'
      └── amount: 0-100 (porcentaje)

SiteConfigContext.tsx → injectCSSVariables():
  ├── Colores base → --color-primary, --color-secondary, --color-accent
  ├── Alias semánticos → --primario, --secundario, --acento
  ├── Variaciones fijas → --color-primary-dark, --color-primary-light
  └── Variaciones configurables (via resolveVariation()):
      ├── --oscuro  → darkenColor/lightenColor(baseColor, amount)
      ├── --claro   → "
      ├── --texto   → "
      ├── --hover   → "
      └── --border  → "

tailwind.config.js consume las CSS vars:
  colors: {
    primario: 'var(--color-primary)',
    secundario: 'var(--color-secondary)',
    acento: 'var(--color-accent)',
    oscuro: 'var(--oscuro)',
    claro: 'var(--claro)',
    texto: 'var(--texto)',
    hover: 'var(--hover)',
    border: 'var(--border)',
  }

index.css define fallbacks estáticos en :root (para SSR/prerender/flash)
```

**Funciones de manipulación de color** (en `SiteConfigContext.tsx`):
- `darkenColor(hex, percent)`: resta `255 * (percent/100)` a cada canal RGB.
- `lightenColor(hex, factor)`: mezcla con blanco por factor 0-1: `channel + (255 - channel) * factor`.
- `resolveVariation(source, mode, amount)`: resuelve source→hex, clamp amount 0-100, llama darken o lighten.

### 3.3 Flujo de Autenticación

```
LandingPage (login/register) → authService.login()
    ↓
POST /starter/v1/auth/login (custom endpoint)
    ↓ JWT token + CSRF token + user data
    ↓
secureStorage.setItem('authToken', jwt)
secureStorage.setItem('csrfToken', csrf)
    ↓
AuthContext → setIsAuthenticated(true), setUser(user)
    ↓
Axios interceptors auto-adjuntan:
  - Authorization: Bearer {jwt}  (en todas las peticiones)
  - X-CSRF-Token: {csrf}        (en POST/PUT/PATCH/DELETE)
    ↓
Si CSRF expira → 403 csrf_token_invalid → auto-refresh singleton
Si JWT expira → 401 → logout automático
```

**Seguridad backend:**
- `security/csrf-protection.php`: CSRF tokens por sesión.
- `security/jwt-auth-middleware.php`: Validación JWT en endpoints protegidos.
- `security/rest-api-access.php`: Restricción de acceso a endpoints REST.
- `rate-limiting.php`: Prevención de fuerza bruta por IP/usuario.
- `jwt-auth-helper.php`: Limpieza de cookies de sesión post-logout.

### 3.4 Flujo de WooCommerce Proxy (Producción)

```
Frontend → wooCommerceApi.get('/products')
    ↓ interceptor adjunta JWT
POST/GET /starter/v1/wc/products
    ↓
class-wc-proxy-orchestrator.php
    ├── Valida JWT del usuario
    ├── Verifica permisos (class-wc-permissions.php)
    ├── Firma OAuth server-side (class-wc-credentials.php)
    ├── Cache inteligente (class-wc-cache-manager.php)
    ├── Idempotencia (class-wc-idempotency.php)
    └── Forward a WooCommerce REST API → respuesta al frontend
```

### 3.5 Flujo de Pagos (Wompi)

```
CheckoutPage → usePaymentGateway()
    ↓ gateway = getPaymentGateway(siteConfig.payments.payment_gateway)
    ↓ (factory pattern: paymentFactory.ts → WompiGateway.ts)
    ↓
generateReference(orderId) → "{site_short_name}-{orderId}-{timestamp}"
openWidget(amount, reference, ...) → Wompi checkout widget
    ↓
Backend: wompi/ (21 archivos)
    ├── Webhook endpoint para confirmar transacciones
    ├── Validación de integridad de eventos
    └── Actualización del estado del pedido en WooCommerce
```

**Patrón de abstracción:**
- `payments/interface-gateway.php` (backend) define la interfaz.
- `payments/index.php` tiene la factory.
- Frontend: `services/payments/paymentFactory.ts` + `WompiGateway.ts` implementan la interfaz TS.
- Para agregar una nueva pasarela: implementar la interfaz en ambos lados + registrar en factory.

### 3.6 Flujo i18n (Internacionalización)

```
URL: /en/catalog → LanguageContext detecta prefijo /en/
    ↓ i18n.changeLanguage('en')
    ↓
Rutas traducidas (App.tsx):
  ES: /catalogo, /reserva, /finalizar-retiro, /invitados, /membresias
  EN: /catalog, /cart, /checkout, /referrals, /memberships

Traducciones: src/locales/{es,en}/*.json (por namespace)

WooCommerce: interceptor inyecta ?lang=en en peticiones WC
Backend: translation-fields.php → meta fields i18n en productos/categorías
```

---

## 4. PROVIDERS Y SU ORDEN (App.tsx)

```tsx
<ErrorBoundary>
  <SiteConfigProvider>        // ← Carga config del sitio, inyecta CSS vars
    <Router>
      <AuthProvider>          // ← JWT, estado de usuario, modales auth
        <MembershipProvider>  // ← Membresías, beneficios, niveles
          <CartProvider>      // ← Carrito híbrido (local + server sync)
            <ModalProvider>   // ← Sistema global de modales
              <LanguageProvider>  // ← i18n ES/EN por prefijo de ruta
                <CategoriesProvider>  // ← Categorías WC
                  <AppContent />
                </CategoriesProvider>
              </LanguageProvider>
            </ModalProvider>
          </CartProvider>
        </MembershipProvider>
      </AuthProvider>
    </Router>
  </SiteConfigProvider>
</ErrorBoundary>
```

**IMPORTANTE:** El orden importa. Cada provider puede depender de los anteriores. `SiteConfigProvider` está primero porque NO depende de Router ni Auth, y otros providers pueden consumirlo.

---

## 5. FEATURE FLAGS (Plugins Opcionales)

Los plugins opcionales se detectan automáticamente en el backend:

```php
// helpers.php → site_get_active_features()
'memberships'      → plugin starter-memberships activo
'referrals_points' → plugin starter-referrals-points activo
'home_sections'    → plugin starter-home-sections activo
'woocommerce'      → WooCommerce activo
```

En el frontend, `useSiteFeatures()` retorna `SiteFeatures` y las rutas/componentes se renderizan condicionalmente:

```tsx
const features = useSiteFeatures();
{features.referrals_points && <Route path="/invitados" element={<ReferidosPage />} />}
{features.memberships && <Route path="/membresias" element={<MembershipsPage />} />}
```

---

## 6. SISTEMA DE SERVICIOS API

### Arquitectura de servicios

```
api.ts (barrel público)
  └── apiServices.ts (barrel interno, re-exporta todo)
        ├── apiConfig.ts ★ (Axios instances, OAuth, JWT, CSRF, interceptors)
        ├── auth/ → authApiService
        ├── products/ → productApiService
        ├── categories/ → categoryApiService
        ├── cart/ → userCartApiService, hybridCartService
        ├── orders/ → orderApiService
        ├── points/ → pointsApiService
        ├── system/ → systemApiService
        ├── reviews/ → reviewApiService
        ├── banners/ → bannerApiService
        ├── home/ → homeSectionApiService
        └── legal/ → legalApiService
```

### Dos instancias de Axios

1. **`api`** — API general de WordPress (`/wp-json/*`). Timeout 45s.
2. **`wooCommerceApi`** — WooCommerce (`/wp-json/starter/v1/wc/*` en producción, `/wp-json/wc/v3/*` en desarrollo). Timeout 30s.

### Cache inteligente (query/cacheManager)

El `cacheManager` gestiona cache en memoria para peticiones GET. Se invalida automáticamente en errores 401/403.

---

## 7. CONVENCIONES DE CÓDIGO

### Nomenclatura

- **Opciones WP:** Prefijo `site_settings_` + key (ej: `site_settings_branding_primary_color`).
- **API response:** Keys sin prefijo de sección, agrupadas por sección (ej: `{ branding: { branding_primary_color: '#16a34a' } }`).
- **CSS variables:** `--color-{name}` para colores base, `--{nombre-español}` para semánticos (--primario, --oscuro, --texto).
- **Tailwind:** Clases en español para colores semánticos (`bg-primario`, `text-texto`, `border-border`).
- **Servicios:** Sufijo `ApiService` internamente, alias sin sufijo al exportar (ej: `productApiService as productService`).
- **Hooks:** Prefijo `use` (estándar React).
- **Archivos PHP:** `class-` para clases, sin prefijo para funciones.

### Patrones importantes

- **Singleton:** `Site_Settings`, `Site_Settings_Page`, `Site_Settings_REST_API`.
- **Factory:** `getPaymentGateway(id)` retorna la implementación correcta.
- **Barrel exports:** Cada carpeta de servicio tiene `index.ts`.
- **Lazy loading:** Todas las páginas excepto `HomePage` usan `React.lazy()`.
- **Hybrid cart:** localStorage para guests + sincronización con server para autenticados.

### NO hacer

- **NO** importar `{ api }` desde `'../api'` en módulos que `apiServices.ts` importa → crea dependencia circular. Importar desde `'../apiConfig'` directamente.
- **NO** agregar campos al plugin sin actualizar TRES archivos sincronizados:
  1. `site-settings.php` → `get_defaults()` + `get_fields_config()`
  2. `types/siteConfig.ts` → interfaz TypeScript + `DEFAULT_SITE_CONFIG`
  3. `class-rest-api.php` → sanitización en `sanitize_value()`
- **NO** hardcodear colores hex en componentes. Usar variables CSS o clases Tailwind semánticas.
- **NO** cachear feature flags en transients. Se evalúan en tiempo real.
- **NO** poner `postcss-nesting` como plugin separado antes de tailwindcss. Usar `tailwindcss/nesting` como wrapper.
- **NO** usar `cd` en comandos de terminal (usar `cwd` en su lugar).

---

## 8. VARIABLES DE ENTORNO

### Frontend (`.env`)

```env
VITE_WP_API_URL=http://admin.starter.local     # URL base del WordPress
VITE_WC_CONSUMER_KEY=ck_xxxxx                   # Solo desarrollo (OAuth directo)
VITE_WC_CONSUMER_SECRET=cs_xxxxx                # Solo desarrollo (OAuth directo)
VITE_USE_WC_PROXY=false                         # true en producción (OBLIGATORIO)
VITE_MAP_API_KEY=                                # Google Maps (opcional)
```

### Backend

- WordPress estándar (`wp-config.php`)
- `STARTER_FRONTEND_URL` — define en `wp-config.php` para redirecciones headless.

---

## 9. TOOLCHAIN Y CONFIGURACIÓN

| Herramienta | Archivo | Notas |
|---|---|---|
| Vite | `vite.config.ts` | Build, dev server, PWA plugin |
| TypeScript | `tsconfig.json` | Strict mode |
| Tailwind CSS 3.3.5 | `tailwind.config.js` | Colores como CSS vars |
| PostCSS | `postcss.config.js` | `tailwindcss/nesting(postcss-nesting)` + tailwindcss + autoprefixer |
| i18next | `src/config/i18n.ts` | Namespaces por dominio |
| PWA | `vite-pwa` plugin | Service Worker auto-generated |

### PostCSS (configuración correcta)

```js
import nesting from 'tailwindcss/nesting/index.js';
import postcssNesting from 'postcss-nesting';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';

export default {
  plugins: [
    nesting(postcssNesting),  // DEBE ser wrapper de TW, NO plugin separado
    tailwindcss,
    autoprefixer,
  ],
}
```

---

## 10. CÓMO AGREGAR UN NUEVO CAMPO DE CONFIGURACIÓN

Ejemplo: agregar `branding_border_radius` (number).

### Paso 1: Backend defaults

```php
// site-settings.php → get_defaults()
'branding_border_radius' => 8,
```

### Paso 2: Backend fields config

```php
// site-settings.php → get_fields_config() → 'branding' → 'fields'
'branding_border_radius' => ['label' => 'Border radius (px)', 'type' => 'number', 'description' => '...'],
```

### Paso 3: Backend sanitización

```php
// class-rest-api.php → sanitize_value()
// Si es número, agregar a $number_keys
// Si es select, agregar whitelist
// Si es color, ya se detecta por strpos($key, 'color')
```

### Paso 4: Frontend tipos

```ts
// types/siteConfig.ts → SiteBranding
branding_border_radius: number;

// types/siteConfig.ts → DEFAULT_SITE_CONFIG.branding
branding_border_radius: 8,
```

### Paso 5: Frontend consumo

```ts
// SiteConfigContext.tsx → injectCSSVariables() (si es CSS var)
root.style.setProperty('--border-radius', `${branding.branding_border_radius}px`);
```

---

## 11. CÓMO AGREGAR UNA NUEVA PASARELA DE PAGO

### Backend

1. Crear clase en `inc/payments/class-{nombre}-gateway.php` implementando `Starter_Payment_Gateway`.
2. Registrar en `inc/payments/index.php` → factory.
3. Agregar opción al select en `site-settings.php` → campo `payment_gateway`.

### Frontend

1. Crear `services/payments/{Nombre}Gateway.ts` implementando la interfaz de `types/payment.ts`.
2. Registrar en `services/payments/paymentFactory.ts`.
3. El hook `usePaymentGateway` lo resuelve automáticamente desde `siteConfig.payments.payment_gateway`.

---

## 12. DEBUGGING

### Frontend

- `logger.ts` controla logs por nivel (dev muestra todo, prod solo errors).
- `errorHandler.ts` centraliza el manejo de errores con categorización.
- DevTools: inspeccionar `:root` para ver CSS variables inyectadas.
- `localStorage` keys: `site_config_cache`, `currency_config`, `site_settings_*`.

### Backend

- `WP_DEBUG` + `WP_DEBUG_LOG` → `wp-content/debug.log` (con rotación diaria vía `log-rotation.php`).
- Transient cache: `wp transient delete site_settings_config_cache` para forzar refresh.
- El plugin site-settings invalida el transient automáticamente al guardar desde el admin.

### Errores comunes

| Síntoma | Causa probable | Archivo |
|---|---|---|
| Config no se carga | Deserialización: `json.data` vs `json` | `SiteConfigContext.tsx` |
| Features no cambian | Features cacheadas en transient | `helpers.php` |
| CORS errors | CORS unificado mal configurado | `cors-functions.php` |
| 403 en POST/PUT | CSRF token expirado | `security/csrf-protection.php` |
| Colores no aplican | CSS vars no inyectadas / Tailwind purge | `SiteConfigContext.tsx`, `tailwind.config.js` |
| Circular dependency (build) | Import desde barrel que re-importa | Importar desde módulo concreto, no barrel |
| PostCSS nesting warnings | `postcss-nesting` como plugin separado | `postcss.config.js` |

---

## 13. COMANDOS ÚTILES

```bash
# Frontend
npm run dev          # Dev server (Vite)
npm run build        # Build producción (tsc + vite build)
npx tsc --noEmit     # Type check sin build

# Backend (WordPress)
wp transient delete site_settings_config_cache   # Invalidar cache de config
wp option get site_settings_branding_primary_color  # Ver un valor
```

---

## 14. ARCHIVOS CLAVE POR TAREA

| Si necesitas... | Lee primero... |
|---|---|
| Entender la config del sitio | `site-settings.php`, `helpers.php`, `SiteConfigContext.tsx`, `siteConfig.ts` |
| Cambiar colores/theming | `SiteConfigContext.tsx` (injectCSSVariables), `tailwind.config.js`, `index.css` |
| Agregar un endpoint REST | `Starter/inc/init.php` (registro), crear archivo en `inc/`, registrar en `rest_api_init` |
| Debuggear auth | `AuthContext.tsx`, `apiConfig.ts` (interceptors), `security/` |
| Modificar checkout/pagos | `usePaymentGateway.ts`, `useCheckoutSubmit.ts`, `CheckoutPage.tsx`, `payments/` |
| Agregar una página | Crear en `pages/`, lazy-load en `App.tsx`, agregar ruta ES y EN |
| Modificar carrito | `CartContext.tsx`, `cart/hybridCartService.ts`, `cart-endpoint.php` |
| Cambiar traducciones | `src/locales/{es,en}/{namespace}.json` |
| Agregar un campo admin | Ver sección 10 de este documento |
