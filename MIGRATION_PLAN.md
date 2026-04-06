# Plan de Migración: Flores Inc → Plantilla Reutilizable

> **Objetivo:** Convertir el proyecto en una plantilla white-label 100% reutilizable, donde cualquier negocio pueda desplegar su propia instancia cambiando solo configuración, sin tocar código fuente.

---

## Índice

1. [Resumen del Análisis](#1-resumen-del-análisis)
2. [Lo que YA es reutilizable](#2-lo-que-ya-es-reutilizable)
3. [Lo que FALTA por abstraer](#3-lo-que-falta-por-abstraer)
4. [Fase 1 — Archivo de configuración central del sitio](#fase-1--archivo-de-configuración-central-del-sitio)
5. [Fase 2 — Branding dinámico (logo, colores, fuentes)](#fase-2--branding-dinámico-logo-colores-fuentes)
6. [Fase 3 — Moneda y localización configurable](#fase-3--moneda-y-localización-configurable)
7. [Fase 4 — Plugins opcionales con feature flags](#fase-4--plugins-opcionales-con-feature-flags)
8. [Fase 5 — Eliminación de contenido hardcodeado](#fase-5--eliminación-de-contenido-hardcodeado)
9. [Fase 6 — Backend configurable (tema WordPress)](#fase-6--backend-configurable-tema-wordpress)
10. [Fase 7 — SEO y meta tags dinámicos](#fase-7--seo-y-meta-tags-dinámicos)
11. [Fase 8 — Pasarela de pago modular](#fase-8--pasarela-de-pago-modular)
12. [Fase 9 — Sistema de emails white-label](#fase-9--sistema-de-emails-white-label)
13. [Fase 10 — Documentación y scaffolding](#fase-10--documentación-y-scaffolding)
14. [Inventario de valores hardcodeados](#inventario-de-valores-hardcodeados)
15. [Orden de ejecución recomendado](#orden-de-ejecución-recomendado)

---

## 1. Resumen del Análisis

### Requisitos técnicos del servidor

| Componente | Versión |
|------------|---------|
| **Web server** | Apache |
| **PHP** | 8.2.29 |
| **Database** | MySQL 8.0.35 |
| **WordPress** | 6.9.4 |

### Arquitectura actual
- **Backend:** WordPress headless (tema `Starter` en `Wordpress/app/public/wp-content/themes/Starter/`)
- **Frontend:** React 19 + TypeScript + Vite + TailwindCSS (`Frontend/src/`)
- **Comunicación:** REST API WordPress + WooCommerce (proxy seguro en producción, OAuth directo en desarrollo)
- **Auth:** JWT (plugin `jwt-authentication-for-wp-rest-api`) + endpoint custom `/starter/v1/auth`
- **Pagos:** Wompi (pasarela colombiana) con 3 flujos: compra con tarjeta, compra de membresía, compra de Virtual Coins
- **PWA:** Service Worker registrado con `vite-plugin-pwa`
- **i18n:** `i18next` con soporte ES/EN (archivos JSON en `locales/`)
- **Caché:** W3 Total Cache (backend) + cacheManager custom (frontend) + transients (plugins)

### Plugins personalizados (3)
| Plugin | Función | Opcional para plantilla |
|--------|---------|------------------------|
| `starter-memberships` | Sistema de membresías con niveles, beneficios, integración WooCommerce | ✅ Sí |
| `starter-referrals-points` | Referidos, puntos (Virtual Coins), comisiones, redención en checkout | ✅ Sí |
| `starter-home-sections` | Secciones dinámicas del home (productos destacados por categoría/nivel) | ✅ Sí |

### Módulos del tema (`inc/`)
| Módulo | Función | Estado |
|--------|---------|--------|
| `security/` | CSRF, JWT middleware, headers, rate limiting, XML-RPC hardening | ✅ Reutilizable |
| `woocommerce/` | Proxy WC, caché, credenciales, idempotencia, permisos | ✅ Reutilizable |
| `wompi/` | Pasarela de pagos Wompi (3 flujos) | ⚠️ Específico Colombia |
| `cors-functions.php` | CORS unificado | ✅ Reutilizable (dominios configurables) |
| `rate-limiting.php` | Rate limiting por IP/usuario/endpoint | ✅ Reutilizable |
| `email-customization/` | Templates de email personalizados | ⚠️ Tiene branding hardcodeado |
| `reviews/` | Sistema de reseñas | ✅ Reutilizable |
| `contact-endpoint.php` | Formulario de contacto | ⚠️ Email destino hardcodeado |
| `translation-fields.php` | Campos de traducción para productos/categorías | ✅ Reutilizable |
| `sitemap-generator/` | Generador de sitemaps SEO | ⚠️ URLs hardcodeadas |
| `special-orders/` | Órdenes especiales (membresías/FC ocultas en WC orders) | ✅ Reutilizable |

---

## 2. Lo que YA es reutilizable

- **Arquitectura headless WP + React SPA** — patrón completamente desacoplado
- **Sistema de autenticación** — JWT + CSRF + rate limiting + cross-tab sync
- **Proxy WooCommerce seguro** — OAuth server-side, credenciales nunca expuestas en frontend
- **Sistema i18n** — estructura de locales bien organizada (ES/EN), fácil de extender
- **Contextos React** — AuthContext, MembershipContext, CartContext, LanguageContext bien modularizados
- **Sistema de caché frontend** — cacheManager con invalidación inteligente por membresía
- **Lazy loading de rutas** — code splitting con React.lazy
- **Seguridad backend** — CSRF, JWT middleware, security headers, rate limiting, XML-RPC hardening
- **PWA** — Service Worker, manifest, offline-ready
- **Fluid sizing** — sistema de tamaños responsivos
- **Error handling** — ErrorBoundary, errorHandler, logger centralizado
- **Estructura de plugins** — patrón Singleton + loader + includes bien organizado
- **WooCommerce integration** — permisos, caché, idempotencia

---

## 3. Lo que FALTA por abstraer

### 🔴 Crítico (bloquea reutilización)

| Problema | Ubicación | Impacto | Estado |
|----------|-----------|--------|--------|
| ~~Nombre "Flores Inc" hardcodeado en ~77 archivos frontend~~ | `src/**/*.{ts,tsx}` | Branding visible al usuario | ✅ Resuelto |
| ~~URL `floresinc.co` hardcodeada en SEO, schemas, seo.ts, index.html~~ | `index.html`, `seo.ts`, etc. | SEO y navegación rota | ✅ Resuelto |
| ~~Moneda COP hardcodeada con redondeo ×50~~ | `config.ts`, `formatters.ts`, `order-cop-rounding.php` | Imposible cambiar moneda | ✅ Resuelto |
| Pasarela Wompi (Colombia-only) sin abstracción | `wompi/`, `wompiService.ts`, `useWompi.ts` | Sin pasarela alternativa | ⬜ Fase 9 |
| ~~Nombres de niveles de membresía hardcodeados~~ | `starter-memberships.php`, `benefitsConfig.ts` | Niveles no personalizables | ✅ Dinámicos |
| ~~"Flores Coins" como nombre de moneda virtual hardcodeado~~ | Múltiples archivos frontend y backend | Branding de puntos fijo | ✅ "Virtual Coins" |
| ~~Redes sociales hardcodeadas~~ | `config.ts`, `index.html` | URLs específicas de Flores Inc | ✅ Resuelto |
| ~~Teléfono, email de contacto hardcodeados~~ | `index.html`, `contact-endpoint.php` | Datos de contacto fijos | ✅ Resuelto |
| ~~Dominio `admin.floresinc.co`~~ | `wp-config.php`, `vite.config.ts` | Dominio de admin fijo | ✅ Resuelto |
| ~~CORS origins hardcodeados~~ | `cors-functions.php` | Solo permite dominios Flores Inc | ✅ Resuelto |
| ~~`HEADLESS_MODE_CLIENT_URL` hardcodeado~~ | `wp-config.php`, `wp-config.prod.php` | Redirect fijo | ✅ Resuelto |
| Credenciales de BD en wp-config.prod.php | `wp-config.prod.php` | Seguridad (¡mover a env!) | ⚠️ Pendiente |

### 🟡 Importante (afecta experiencia)

| Problema | Ubicación | Impacto | Estado |
|----------|-----------|--------|--------|
| Textos de negocio en español hardcodeados en PHP | Plugins + tema | No usa sistema i18n del backend | ⚠️ Parcial |
| Contenido de páginas legales hardcodeado | `legal-functions.php`, páginas legales frontend | Contenido legal específico | ⚠️ Parcial |
| ~~Fuente "Poppins" hardcodeada en index.html~~ | `index.html` | Estilo visual fijo | ✅ Dinámico |
| ~~Color primario `#16a34a` en theme-color~~ | `index.html`, `tailwind.config.js` | Identidad visual fija | ✅ CSS vars |
| ~~Imágenes OG específicas de Flores Inc~~ | `seo.ts` OG_IMAGES | SEO visual fijo | ✅ Dinámico |
| ~~Schema.org Organization con datos Flores Inc~~ | `index.html` | SEO estructurado fijo | ✅ Dinámico |
| `VITE_STORAGE_SECRET` hardcodeado en .env | `.env` | Seguridad en desarrollo | ⚠️ Pendiente |
| ~~Geo tags "CO" (Colombia) hardcodeados~~ | `index.html` | Localización geográfica fija | ✅ Genérico |
| Slugs de ruta en español hardcodeados | `App.tsx` rutas | Solo funciona con slugs ES/EN actuales | ⚠️ Parcial |

### 🟢 Nice to have (optimización futura)

| Problema | Ubicación | Impacto |
|----------|-----------|---------|
| Plugins no detectan su activación/desactivación dinámicamente | Frontend | Carga código de plugins desactivados |
| No hay CLI de scaffolding | Proyecto | Setup manual de nuevas instancias |
| No hay sistema de temas/skins | Frontend CSS | Solo un estilo visual |

---

## Fase 1 — Configuración central del sitio (Plugin + siteConfig)

### Objetivo
Crear una fuente de verdad centralizada para TODA la identidad del negocio, gestionable desde el panel de administración de WordPress y consumible tanto por el backend (PHP) como por el frontend (React via REST API).

### Cambio respecto al plan original
En lugar de un archivo PHP estático con `define()`, se crea un **plugin de WordPress "Site Settings"** que:
- Almacena configuración en `wp_options` (editable desde WP Admin)
- Expone un endpoint REST `GET /site-settings/v1/config` para el frontend
- Provee funciones helper `site_get_option()` para tema y otros plugins
- Incluye una página de administración organizada por secciones

### Acciones

#### 1.1 Backend: Crear plugin `site-settings`

Ubicación: `Wordpress/app/public/wp-content/plugins/site-settings/`

Estructura:
```
site-settings/
  site-settings.php          # Main plugin file (header, constantes, singleton)
  includes/
    class-settings-page.php  # Página de admin con secciones y campos
    class-rest-api.php       # Endpoint REST para el frontend
    helpers.php              # Funciones globales site_get_option(), etc.
  assets/
    css/admin.css            # Estilos del panel de configuración
```

**Secciones del panel de admin:**

| Sección | Campos |
|---------|--------|
| Identidad | Nombre, nombre corto, tagline, descripci贸n |
| URLs | URL frontend, URL admin |
| Contacto | Email, tel茅fono, WhatsApp |
| Redes sociales | Facebook, Instagram, TikTok, Twitter/X |
| Branding | Color primario, color secundario, fuente, logo, favicon, imagen OG |
| Moneda | C贸digo, s铆mbolo, decimales, locale, redondeo |
| Geolocalizaci贸n | Pa铆s, regi贸n, zona horaria |
| Moneda virtual | Nombre, abreviaci贸n, 铆cono, tasa de conversi贸n |
| L铆mites | Max direcciones, edad m铆nima, items por p谩gina |
| SEO | Sufijo t铆tulo, keywords, autor |

**Endpoint REST:** `GET /site-settings/v1/config`
- P煤blico (no requiere autenticaci贸n)
- Cache con transient de 5 minutos (invalidado al guardar opciones)
- Devuelve todas las secciones como JSON estructurado

**Funciones helper:**
- `site_get_option($key, $default)` — obtiene un valor de configuraci贸n
- `site_get_section($section)` — obtiene todos los valores de una secci贸n
- `site_get_all_config()` — obtiene toda la configuraci贸n

#### 1.2 Frontend (futuro): Crear `Frontend/src/config/siteConfig.ts`

> **Nota:** El directorio `Frontend/` est谩 vac铆o a煤n. Esta acci贸n se ejecutar谩 cuando se copie el frontend de Flores Inc. El archivo consumir谩 del endpoint REST en producci贸n y tendr谩 defaults para desarrollo.

```typescript
// Consumir configuraci贸n del backend via REST API
// En build/runtime: fetch GET /site-settings/v1/config
// Fallback: valores por defecto para desarrollo local
```

#### 1.3 Limpiar `wp-config.php`

- Corregir l铆neas con errores de sintaxis (WP_HOME/WP_SITEURL comentados con basura)
- Reemplazar `'https://floresinc.co'` por referencia gen茅rica configurable
- Mantener credenciales locales (son de Local by Flywheel, no de producci贸n)

### Archivos creados
- `Wordpress/app/public/wp-content/plugins/site-settings/site-settings.php`
- `Wordpress/app/public/wp-content/plugins/site-settings/includes/class-settings-page.php`
- `Wordpress/app/public/wp-content/plugins/site-settings/includes/class-rest-api.php`
- `Wordpress/app/public/wp-content/plugins/site-settings/includes/helpers.php`
- `Wordpress/app/public/wp-content/plugins/site-settings/assets/css/admin.css`

### Archivos modificados
- `Wordpress/app/public/wp-config.php` — limpieza de errores y valores gen茅ricos

---

## Fase 2 — Renombramiento de prefijos (FloresInc → Starter) ✅ COMPLETADA (Backend + Frontend)

### Objetivo
Renombrar todas las carpetas, archivos y referencias de código que contenían "floresinc" / "FloresInc" / "FLORESINC" al nuevo prefijo genérico "starter" / "Starter" / "STARTER_".

### Cambios realizados

#### 2.1 Carpetas renombradas
| Original | Nuevo |
|----------|-------|
| `themes/FloresInc/` | `themes/Starter/` |
| `plugins/floresinc-memberships/` | `plugins/starter-memberships/` |
| `plugins/floresinc-referrals-points/` | `plugins/starter-referrals-points/` |
| `plugins/floresinc-home-sections/` | `plugins/starter-home-sections/` |
| `themes/Starter/inc/wompi/flores-coins/` | `themes/Starter/inc/wompi/virtual-coins/` |

#### 2.2 Archivos renombrados
| Original | Nuevo |
|----------|-------|
| `floresinc-memberships.php` | `starter-memberships.php` |
| `floresinc-referrals-points.php` | `starter-referrals-points.php` |
| `floresinc-home-sections.php` | `starter-home-sections.php` |
| `class-floresinc-home-sections.php` | `class-starter-home-sections.php` |
| `floresinc-styles.css` | `starter-styles.css` |
| `flores-coins-product-type.php` | `virtual-coins-product-type.php` |

#### 2.3 Reemplazos de contenido (341 archivos actualizados)

| Patrón original | Reemplazo | Tipo |
|----------------|-----------|------|
| `FLORESINC_` | `STARTER_` | Constantes PHP |
| `FloresInc_` | `Starter_` | Clases PascalCase |
| `FloresInc` | `Starter` | Nombres de tema/clase |
| `floresinc/v1` | `starter/v1` | REST API namespaces |
| `floresinc_` | `starter_` | Funciones/hooks snake_case |
| `floresinc-` | `starter-` | Slugs kebab-case |
| `floresinc.co` | `example.com` | Dominio placeholder |
| `'floresinc'` (text domain) | `'starter'` | Text domains en `__()` / `_e()` |
| `Flores Coins` | `Virtual Coins` | Nombre moneda virtual (UI) |
| `flores_coins` | `virtual_coins` | Variables/meta keys |
| `flores-coins` | `virtual-coins` | Slugs CSS/paths |
| `FLORES_COINS` | `VIRTUAL_COINS` | Constantes |
| `Flores Inc` | `E-Commerce Template` | Textos de negocio en LICENSE/README |

#### 2.4 Limpieza adicional
- `wp-config.php` — corregido bloque WP_HOME/WP_SITEURL (tenía errores de sintaxis), HEADLESS_MODE_CLIENT_URL → `https://example.com`
- `style.css` — Text Domain corregido a `starter`
- `class-order-certificate-pdf.php` — nombre de empresa ahora lee de `site_get_option()` dinámicamente
- LICENSE files de tema y plugins actualizados

#### 2.5 Frontend: Renombramiento completo ✅

| Categoría | Detalle | Archivos |
|---|---|---|
| **Namespace API** | `floresinc/v1` → `starter/v1` | 35 archivos, 92 instancias |
| **Componentes** | `FloresCoin*` → `VirtualCoin*` (archivos + contenido) | 8 archivos renombrados, 61 actualizados |
| **Locales JSON** | `Flores Coins` → `Virtual Coins` | 50 archivos JSON, 4 renombrados |
| **Meta keys WP** | `_floresinc_*` → `_starter_*` | OrdersSection, checkoutHelpers |
| **Endpoints REST** | `flores-coins` → `virtual-coins` | wompiService.ts |
| **Variables** | `FLORESINC_ENDPOINT` → `STARTER_ENDPOINT` | wompiService.ts |
| **Storage keys** | `floresinc_*` → `starter_*` | PopupManager, cacheManager |
| **Rutas tema** | `/FloresInc/` → `/Starter/` | ProductGallery, OrdersSection, CartModal, CartItemList |
| **Assets** | `Flores-coins.png` → `virtual-coins.png`, etc. | 2 archivos |
| **Colores hardcodeados** | `#B91E59` → `var(--color-primary)` | PullToRefresh |
| **Comentarios** | `@package FloresInc` → `@package Starter` | 7 archivos |
| **Config** | vite.config.ts, package.json, index.html | URLs dinámicas, geo tags genéricos |

### Verificación
- **Backend:** 0 matches de `floresinc|FloresInc|FLORESINC|Flores Inc|flores_coins|flores-coins|FLORES_COINS|Flores Coins` en todo `wp-content/`.
- **Frontend:** 0 matches de los mismos patrones en `src/`, `vite.config.ts`, `index.html`, `package.json`. Solo `package-lock.json` (auto-generado) pendiente de `npm install`.

---

## Fase 3 — Branding dinámico (logo, colores, fuentes)

### Objetivo
Que el logo, colores primarios y fuente se cambien desde configuración sin tocar código.

### Acciones

#### 3.1 Tailwind config dinámico

Modificar `tailwind.config.js` para leer colores de CSS custom properties:

```javascript
// tailwind.config.js
module.exports = {
  theme: {
    extend: {
      colors: {
        primary: 'var(--color-primary)',
        secondary: 'var(--color-secondary)',
        // ... mantener colores base de Tailwind
      },
    },
  },
};
```

#### 3.2 Inyectar CSS variables desde siteConfig

En `index.css` o `App.tsx`, generar CSS custom properties dinámicamente:

```css
:root {
  --color-primary: #16a34a;
  --color-secondary: #FF6B35;
  --font-family: 'Poppins', sans-serif;
}
```

#### 3.3 Logo dinámico

Crear componente `<SiteLogo />` que lea de `siteConfig.branding.logo` en vez de importar imagen estática.

#### 3.4 Google Fonts dinámico

Generar el `<link>` de Google Fonts en `index.html` dinámicamente basado en `siteConfig.branding.font`, o moverlo a un script de build.

### Archivos afectados (Frontend — pendiente)
- `frontend/index.html` — font link, theme-color, apple-mobile-web-app-title
- `frontend/tailwind.config.js` — colores
- `frontend/src/index.css` — CSS variables
- Todos los componentes que importan logo/imágenes de marca directamente

### ✅ Backend completado

#### Helpers de branding añadidos al plugin Site Settings
- `site_get_primary_color()`, `site_get_secondary_color()`, `site_get_font()`, `site_get_logo_url()`, `site_get_favicon_url()`
- `site_darken_color()`, `site_lighten_color()` — derivar hover/light-bg dinámicamente
- `site_get_email_branding()` — array completo para emails (colores, fuente, logo, nombre, URLs)
- `site_hex_to_rgb()` — conversión hex→RGB para TCPDF

#### Template de email centralizado
- Creado `email-template.php` con funciones: `starter_email_css()`, `starter_email_header()`, `starter_email_footer()`, `starter_email_wrap()`
- CSS dinámico usando colores/fuentes de Site Settings con fallbacks
- Anti-clipping de Gmail incluido

#### Archivos refactorizados (branding hardcodeado → dinámico)
| Archivo | Cambio |
|---------|--------|
| `email-customization.php` | Remitente, logo, colores → dinámicos |
| `welcome-email.php` | 3 funciones (~400 líneas CSS duplicado eliminadas) |
| `password-reset-email.php` | Colores, nombre, template centralizado |
| `contact-endpoint.php` | 2 funciones de email refactorizadas |
| `order-email-endpoint.php` | Email de orden con branding dinámico |
| `class-order-certificate-pdf.php` | Colores RGB, metadatos, links, nombre empresa → dinámicos |
| `admin-functions.php` | Mensajes de WhatsApp con nombre dinámico |
| `info-sidebar.php` | Nombre del proyecto en admin → dinámico |

#### Función de logo renombrada
- `starter_get_logo_url()` → `starter_get_email_logo_url()` (prioriza Site Settings, fallback a archivos del tema)
- Todas las llamadas actualizadas en `contact-endpoint.php` y `order-email-endpoint.php`

#### REST API
- `GET /site-settings/v1/config` ya resuelve IDs de imagen a URLs completas (líneas 83-87 de `helpers.php`)

---

## Fase 4 — Moneda y localización configurable

### Objetivo
Permitir cambiar la moneda del sitio (COP, USD, EUR, etc.) desde configuración.

### Acciones

#### 4.1 Refactorizar `formatters.ts`

```typescript
import siteConfig from '../config/siteConfig';

export const formatPrice = (amount: number): string => {
  const { code, locale, decimals } = siteConfig.currency;
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency: code,
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(amount);
};

export const roundCurrency = (amount: number): number => {
  const multiple = siteConfig.currency.roundingMultiple;
  if (!multiple || multiple <= 1) return Math.round(amount);
  return Math.ceil(amount / multiple) * multiple;
};
```

#### 4.2 Backend: Refactorizar `order-cop-rounding.php`

Leer `SITE_CURRENCY_ROUNDING` en lugar de hardcodear `50`:

```php
$rounding = defined('SITE_CURRENCY_ROUNDING') ? SITE_CURRENCY_ROUNDING : 1;
$total = ceil($total / $rounding) * $rounding;
```

#### 4.3 Wompi → moneda dinámica

El servicio Wompi actualmente asume COP. Si se cambia de pasarela, la moneda debe ser configurable.

### Archivos afectados (Frontend — pendiente)
- `frontend/src/utils/formatters.ts` — todas las funciones de formato de precio
- `frontend/src/utils/checkoutHelpers.ts` — cálculos de totales
- `frontend/src/services/wompiService.ts` — moneda en transacciones
- `frontend/src/hooks/useCheckoutPoints.ts` — conversión puntos-moneda

### ✅ Backend completado

#### Helpers de moneda añadidos al plugin Site Settings
- `site_get_currency_code()` — código ISO 4217 (prioriza WooCommerce, fallback Site Settings)
- `site_get_currency_symbol()` — símbolo ($, €, £)
- `site_get_currency_decimals()` — decimales (prioriza WooCommerce)
- `site_get_currency_locale()` — locale (ej: es-CO, en-US)
- `site_get_currency_rounding()` — múltiplo de redondeo (ej: 50 para COP, 1 para USD)
- `site_get_currency_config()` — array completo de configuración
- `site_round_currency($amount)` — redondeo al múltiplo configurado (ceil)
- `site_format_price($amount)` — formatea con wc_price() o manual

#### Archivos refactorizados
| Archivo | Cambio |
|---------|--------|
| `order-cop-rounding.php` | Redondeo genérico configurable; se autodesactiva si múltiplo ≤ 1 |
| `minimum-order-settings.php` | Fallback usa helpers dinámicos en vez de 'COP'/'$' |
| `wompi-endpoint.php` | `currency` dinámica desde Site Settings |
| `wompi-history/wompi-api.php` | Fallback dinámico |
| `wompi-history/page.php` | Display de montos usa `site_format_price()` |
| `order-validation.php` | Format price dinámico, buffers de redondeo dinámicos |
| `card-payment/endpoints.php` | `ceil(/50)*50` → `site_round_currency()`, conversion_rate dinámica |
| `virtual-coins/endpoints.php` | Redondeo dinámico en validación de monto |
| `virtual-coins/processor.php` | Redondeo dinámico en validación de monto |
| `membership-purchase/endpoints.php` | Redondeo dinámico en validación de monto |
| `membership-purchase/processor.php` | Redondeo dinámico en validación de monto |
| `order-fc-transactions.php` | `conversion_rate` dinámica desde Site Settings |

#### Funciones renombradas
- `starter_ceil_to_50cop()` → `starter_round_to_currency_multiple()` (usa `site_round_currency()`)
- `starter_round_order_totals_to_cop()` → `starter_round_order_totals()` (genérico)

---

## Fase 5 — Plugins opcionales con feature flags

### Objetivo
Que los 3 plugins personalizados (memberships, referrals-points, home-sections) sean verdaderamente opcionales: si están desactivados, el frontend se adapta sin errores.

### Acciones

#### 5.1 Feature flags en siteConfig (ya definidos en Fase 1)

```typescript
features: {
  memberships: true,
  referrals: true,
  points: true,
  // ...
}
```

#### 5.2 Backend: Endpoint de features activas

~~Crear endpoint `GET /starter/v1/features`~~ → **Implementado dentro de `GET /site-settings/v1/config`** que incluye `features` automáticamente.

```php
// Ejemplo original (ya NO se usa — integrado en site-settings):
register_rest_route('starter/v1', '/features', [
    'methods' => 'GET',
    'callback' => function() {
        return [
            'memberships' => is_plugin_active('starter-memberships/starter-memberships.php'),
            'referrals'   => is_plugin_active('starter-referrals-points/starter-referrals-points.php'),
            'homeSections' => is_plugin_active('starter-home-sections/starter-home-sections.php'),
        ];
    },
    'permission_callback' => '__return_true',
]);
```

#### 5.3 Frontend: Context de features

Crear `FeaturesContext.tsx` que cargue las features activas al inicio y las exponga via `useFeatures()`:

```typescript
const { isEnabled } = useFeatures();
if (isEnabled('memberships')) { /* mostrar UI de membresías */ }
```

#### 5.4 Condicionar rutas y componentes

En `App.tsx`, renderizar rutas de membresías/referidos/wallet solo si el feature está activo:

```tsx
{isEnabled('memberships') && <Route path="/membresias" element={<MembershipsPage />} />}
{isEnabled('referrals') && <Route path="/invitados" element={<ReferidosPage />} />}
{isEnabled('points') && <Route path="/fondo-de-aportes" element={<WalletPage />} />}
```

#### 5.5 Condicionar MembershipProvider

Si memberships está desactivado, el `MembershipProvider` siempre devuelve nivel 0 sin hacer peticiones API.

#### 5.6 Condicionar menú y navegación

Los componentes de menú (Header, MobileMenu, Footer) deben ocultar links a features desactivadas.

### Archivos afectados (Frontend — pendiente)
- Nuevo: `frontend/src/contexts/FeaturesContext.tsx`
- `frontend/src/App.tsx` — rutas condicionales
- `frontend/src/contexts/MembershipContext.tsx` — bypass si desactivado
- `frontend/src/components/layout/Header.tsx` — menú condicional
- `frontend/src/components/layout/Footer.tsx` — links condicionales
- `frontend/src/components/layout/MobileMenu.tsx` — menú condicional
- `frontend/src/pages/CheckoutPage.tsx` — sección de puntos condicional
- `frontend/src/pages/HomePage.tsx` — secciones condicionales

### ✅ Backend completado

#### Feature flags integrados en plugin Site Settings
- `site_get_features_registry()` — mapa de features con plugin slug, check function y label
- `site_get_active_features()` — detecta plugins activos vía `is_plugin_active()` + `function_exists()`
- `site_is_feature_active($key)` — verificar feature individual
- Integrado en `site_get_all_config()` → REST API expone `features` automáticamente

#### Features registradas
| Feature key | Plugin | Check function |
|-------------|--------|----------------|
| `memberships` | `starter-memberships` | `Starter_Memberships()` |
| `referrals_points` | `starter-referrals-points` | `Starter_RP()` |
| `home_sections` | `starter-home-sections` | `starter_home_sections()` |
| `woocommerce` | `woocommerce` | `WC()` |

#### Guards corregidos / añadidos
| Archivo | Problema | Fix |
|---------|----------|-----|
| `woocommerce-admin-customizations.php` | `Starter_RP()->get_options()` sin guard (2 instancias) | Añadido `function_exists('Starter_RP')` |
| `reviews/order-rating.php` | `Starter_RP()->get_options()` sin guard | Añadido `function_exists('Starter_RP')` |

#### Auditoría: 48 archivos del tema verificados
- Todas las llamadas a `Starter_Memberships::` ya tenían `class_exists()` ✅
- Todas las llamadas a `starter_rp_add_points` ya tenían `function_exists()` ✅
- Todas las llamadas a `starter_activate_user_membership` ya tenían `function_exists()` ✅
- 3 llamadas a `Starter_RP()->get_options()` sin guard → corregidas ✅

---

## Fase 6 — Eliminación de contenido hardcodeado ✅ COMPLETADA (Backend + Frontend)

### Objetivo
Reemplazar TODAS las referencias directas a "Flores Inc", URLs, teléfonos, emails, y redes sociales por valores de `siteConfig`.

### ✅ Frontend: Completado

Todos los residuos de branding "Flores Inc" eliminados del frontend:

| Patrón | Solución aplicada | Estado |
|--------|-------------------|--------|
| `floresinc/v1` (namespace API) | `starter/v1` | ✅ 35 archivos |
| `FloresCoin*` (componentes) | `VirtualCoin*` | ✅ 8 archivos renombrados |
| `Flores Coins` (textos UI) | `Virtual Coins` (dinámico vía i18n) | ✅ 50 locales + 41 TSX |
| `_floresinc_*` (meta keys) | `_starter_*` | ✅ 2 archivos |
| `@package FloresInc` (JSDoc) | `@package Starter` | ✅ 7 archivos |
| `/FloresInc/` (rutas tema) | `/Starter/` | ✅ 4 archivos |
| `floresinc_*` (storage keys) | `starter_*` | ✅ 2 archivos |
| Colores hardcodeados `#B91E59` | `var(--color-primary)` | ✅ PullToRefresh |
| `adminflores.local` (devHosts) | eliminado | ✅ apiConfig.ts |
| Assets con "Flores" | renombrados | ✅ 2 archivos |
| Geo tags "CO" (Colombia) | genéricos "US" | ✅ index.html |
| `vite.config.ts` URLs hardcodeadas | dinámicas con `wpApiUrl` | ✅ |
| `package.json` nombre | `starter-ecommerce` | ✅ |

### ✅ Backend: Completado (en Fase 2)

0 matches de branding antiguo en todo `wp-content/`.

---

## Fase 7 — Backend configurable (tema WordPress)

### Objetivo
El tema WordPress debe funcionar para cualquier negocio sin modificar archivos PHP.

### Acciones

#### 7.1 CORS dinámico

`cors-functions.php` actualmente tiene origins hardcodeados. Cambiar a:

```php
function floresinc_get_allowed_origins() {
    // Leer desde wp-config.php o opciones
    $origins = defined('ALLOWED_CORS_ORIGINS') 
        ? explode(',', ALLOWED_CORS_ORIGINS) 
        : [];
    
    if (wp_get_environment_type() !== 'production') {
        $origins = array_merge($origins, [
            'http://localhost:5173',
            'http://localhost:5174',
        ]);
    }
    
    return $origins;
}
```

Y en `wp-config.php`:
```php
define('ALLOWED_CORS_ORIGINS', 'https://midominio.com,https://www.midominio.com,https://admin.midominio.com');
```

#### 7.2 wp-config.prod.php → template

Crear `wp-config.prod.example.php` sin credenciales reales:

```php
define('DB_NAME', '%%DB_NAME%%');
define('DB_USER', '%%DB_USER%%');
define('DB_PASSWORD', '%%DB_PASSWORD%%');
// ...
```

> ⚠️ **URGENTE:** El archivo `wp-config.prod.php` actual contiene credenciales de BD y JWT secret key en el repositorio. Mover a variables de entorno o .env inmediatamente.

#### 7.3 Emails

`email-customization/` tiene templates con logo y colores de Flores Inc. Hacerlos dinámicos leyendo de opciones de WP (`get_option('site_logo')`, `get_option('site_primary_color')`).

#### 7.4 Contacto

`contact-endpoint.php` tiene email de destino hardcodeado. Leer de `SITE_CONTACT_EMAIL` o `get_option('admin_email')`.

### Archivos afectados
- `backend/.../inc/cors-functions.php`
- `backend/.../wp-config.prod.php` (template)
- `backend/.../inc/email-customization/`
- `backend/.../inc/contact-endpoint.php`
- `backend/.../inc/sitemap-generator/`

---

## Fase 8 — SEO y meta tags dinámicos

### Objetivo
Que todo el SEO (meta tags, schemas, OG images, sitemaps) se genere a partir de `siteConfig`.

### Acciones

#### 8.1 `seo.ts` — leer de siteConfig

```typescript
const BASE_URL = siteConfig.baseUrl;
const SITE_NAME = siteConfig.name;
const DEFAULT_IMAGE = `${BASE_URL}${siteConfig.branding.ogImage}`;
```

#### 8.2 `index.html` — schema.org desde template

El schema Organization, SiteNavigationElement, etc. deben generarse en build time.

#### 8.3 Sitemaps backend

El generador de sitemaps debe leer `FLORESINC_FRONTEND_URL` para las URLs.

### Archivos afectados
- `frontend/src/utils/seo.ts`
- `frontend/src/hooks/useSEO.ts`
- `frontend/index.html`
- `backend/.../inc/sitemap-generator/`

---

## Fase 9 — Pasarela de pago modular ✅ COMPLETADA

### Objetivo
Abstraer la pasarela de pago para soportar Wompi, Stripe, MercadoPago, etc.

### Estrategia adoptada

Se optó por una **capa de abstracción no destructiva** en lugar de reescribir los flujos existentes:
- Los módulos de negocio (`wompiService.ts`, `useWompi.ts`, `inc/wompi/`) se mantienen intactos — contienen lógica de negocio probada (3DS grace periods, DOM detection, server-side verification, polling, etc.)
- Se creó una capa de interfaz genérica (`PaymentGateway`) que nuevos componentes y futuras pasarelas pueden implementar
- `WompiGateway` envuelve las operaciones core de Wompi adaptándolas a la interfaz genérica
- El factory selecciona la pasarela activa desde `SiteConfig → payments.payment_gateway`

### ✅ Frontend: Archivos creados

| Archivo | Propósito |
|---------|-----------|
| `src/types/payment.ts` | Tipos genéricos: `PaymentGateway`, `PaymentTransaction`, `PaymentWidgetOptions`, etc. |
| `src/services/payments/WompiGateway.ts` | Implementación de `PaymentGateway` para Wompi (normaliza tipos, maneja widget) |
| `src/services/payments/paymentFactory.ts` | Factory con registry de pasarelas + `getPaymentGateway(id)` |
| `src/services/payments/index.ts` | Barrel export del módulo |
| `src/hooks/usePaymentGateway.ts` | Hook genérico que lee pasarela de `SiteConfig`, maneja init/widget/config |

### ✅ Frontend: Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `src/types/siteConfig.ts` | Añadido `SitePayments` interface + `payments` en `SiteConfig` + default `'wompi'` |
| `src/hooks/useWompi.ts` | Añadida nota de deprecación suave → `@see usePaymentGateway` |

### ✅ Backend: Archivos creados

| Archivo | Propósito |
|---------|-----------|
| `inc/payments/interface-gateway.php` | Interfaz PHP `Starter_Payment_Gateway` (7 métodos obligatorios) |
| `inc/payments/class-wompi-gateway.php` | `Starter_Wompi_Gateway` implementando la interfaz (delega a `Starter_Wompi_Config`) |
| `inc/payments/index.php` | Loader con registry + `starter_get_payment_gateway()` + `starter_get_available_gateways()` |

### ✅ Backend: Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `inc/init.php` | Carga `payments/index.php` antes de `wompi/index.php` |
| `plugins/site-settings/site-settings.php` | Añadido `payment_gateway` a defaults + sección "Pasarela de Pago" con campo select |
| `plugins/site-settings/includes/class-settings-page.php` | Soporte para tipo de campo `select` en render + wp_type |

### Arquitectura resultante

```
Frontend:
  types/payment.ts                    ← Interfaz genérica
  types/wompi.ts                      ← Tipos Wompi-específicos (sin cambios)
  services/payments/
    index.ts                          ← Barrel export
    paymentFactory.ts                 ← Factory con registry
    WompiGateway.ts                   ← Adaptador Wompi → PaymentGateway
  services/wompiService.ts            ← Servicio de negocio Wompi (sin cambios)
  hooks/usePaymentGateway.ts          ← Hook genérico (para nuevos componentes)
  hooks/useWompi.ts                   ← Hook Wompi (componentes existentes)

Backend:
  inc/payments/
    index.php                         ← Loader + factory
    interface-gateway.php             ← Interfaz PHP
    class-wompi-gateway.php           ← Implementación Wompi
  inc/wompi/                          ← Módulos de negocio Wompi (sin cambios)
```

### Cómo añadir una nueva pasarela (ej: Stripe)

1. **Frontend:** Crear `src/services/payments/StripeGateway.ts` implementando `PaymentGateway`
2. **Frontend:** Registrar en `paymentFactory.ts` → `gatewayRegistry`
3. **Backend:** Crear `inc/payments/class-stripe-gateway.php` implementando `Starter_Payment_Gateway`
4. **Backend:** Registrar en `inc/payments/index.php` → `starter_get_gateway_registry()`
5. **Backend:** Crear `inc/stripe/` con endpoints, processors, DB (análogo a `inc/wompi/`)
6. **Admin:** Seleccionar "Stripe" en Site Settings → Pasarela de Pago

---

## Fase 10 — Sistema de emails white-label

### Objetivo
Los emails transaccionales deben usar logo, colores y nombre del sitio configurables.

### Acciones

#### 10.1 Templates con placeholders

Los templates de email en `email-customization/` deben usar:
- `{{site_name}}` → `SITE_NAME`
- `{{site_logo}}` → URL del logo (opción de WP o constante)
- `{{primary_color}}` → Color primario del sitio
- `{{frontend_url}}` → URL del frontend
- `{{contact_email}}` → Email de contacto

#### 10.2 Panel de admin para personalizar emails

Crear página de admin donde se pueda:
- Subir logo para emails
- Definir colores
- Previsualizar templates

### Archivos afectados
- `backend/.../inc/email-customization/email-customization.php`
- `backend/.../inc/email-customization/welcome-email.php`
- `backend/.../inc/order-email-endpoint.php`

---

## Fase 11 — Documentación y scaffolding

### Objetivo
Facilitar la creación de nuevas instancias del proyecto.

### Acciones

#### 11.1 README actualizado

Actualizar `README.md` con:
- Instrucciones de instalación desde template
- Guía de configuración de `siteConfig`
- Lista de features opcionales
- Guía de configuración de pasarela de pago
- Guía de personalización de branding

#### 11.2 Script de scaffolding

Crear `scripts/setup.js` interactivo:

```bash
npx @floresinc/create-app mi-tienda
# Pregunta: Nombre del sitio? → "Mi Tienda"
# Pregunta: URL del frontend? → "https://mitienda.com"
# Pregunta: Moneda? → USD
# Pregunta: Pasarela de pago? → Stripe
# Pregunta: Activar membresías? → No
# → Genera siteConfig.ts y wp-config.php
```

#### 11.3 `.env.example` files

Crear archivos `.env.example` tanto para frontend como backend con todas las variables documentadas.

#### 11.4 Docker compose (opcional)

Crear `docker-compose.yml` para levantar WordPress + MySQL + React dev server en un solo comando.

---

## Inventario de valores hardcodeados

### Frontend — Valores migrados a siteConfig ✅

| Valor | Estado | Fuente actual |
|-------|--------|---------------|
| Nombre del sitio | ✅ Dinámico | `SiteConfigContext` → `identity.site_name` |
| URL del sitio | ✅ Dinámico | `SiteConfigContext` → `urls.*` |
| Moneda (código, símbolo, decimales, locale) | ✅ Dinámico | `SiteConfigContext` → `currency.*` |
| Redondeo monetario | ✅ Dinámico | `SiteConfigContext` → `currency.rounding_multiple` |
| Nombre moneda virtual | ✅ Dinámico | `SiteConfigContext` → `virtual_currency.*` |
| Teléfono, email, WhatsApp | ✅ Dinámico | `SiteConfigContext` → `contact.*` |
| Redes sociales | ✅ Dinámico | `SiteConfigContext` → `social.*` |
| Color primario/secundario | ✅ CSS vars | `SiteConfigContext` → `branding.*` |
| Fuente | ✅ Google Fonts dinámico | `SiteConfigContext` → `branding.branding_font` |
| Logo, favicon, imagen OG | ✅ Dinámico | `SiteConfigContext` → `branding.*` |
| Límites (direcciones, edad, items/página) | ✅ Dinámico | `SiteConfigContext` → `limits.*` |
| SEO (título, keywords, autor) | ✅ Dinámico | `SiteConfigContext` → `seo.*` |
| Geo tags | ✅ Genéricos | `index.html` (fallback US, sobrescrito dinámicamente) |
| Feature flags | ✅ Dinámico | `SiteConfigContext` → `features.*` |

### Backend — Valores migrados ✅

| Valor | Estado | Fuente actual |
|-------|--------|---------------|
| Dominio frontend | ✅ | `HEADLESS_MODE_CLIENT_URL` en wp-config.php |
| Dominio admin | ✅ | `WP_HOME` / `WP_SITEURL` en wp-config.php |
| CORS origins | ✅ | `ALLOWED_CORS_ORIGINS` en wp-config.php |
| Nombre moneda virtual | ✅ | Plugin site-settings → `virtual_currency_name` |
| Email de contacto | ✅ | Plugin site-settings → `contact_email` |
| Branding (colores, fuente, logo) | ✅ | Plugin site-settings → `branding_*` |
| DB credentials | ⚠️ Pendiente | **Mover a env vars en producción** |
| JWT secret | ⚠️ Pendiente | **Mover a env vars en producción** |

---

## Orden de ejecución recomendado

| # | Fase | Esfuerzo | Prioridad | Dependencias | Estado |
|---|------|----------|-----------|--------------|--------|
| 1 | Configuración central — Plugin Site Settings | 🟢 Bajo | 🔴 Crítica | Ninguna | ✅ Completada |
| 2 | Renombramiento de prefijos (FloresInc → Starter) | 🟡 Medio | 🔴 Crítica | Fase 1 | ✅ Completada |
| 3 | Branding dinámico (logo, colores, fuentes) | 🟡 Medio | 🟡 Alta | Fase 1 | ✅ Backend + Frontend |
| 4 | Moneda y localización configurable | 🟡 Medio | 🟡 Alta | Fase 1 | ✅ Backend + Frontend |
| 5 | Plugins opcionales con feature flags | 🟡 Medio | 🔴 Crítica | Fase 1 | ✅ Backend + Frontend |
| 6 | Eliminación de contenido hardcodeado (frontend) | 🔴 Alto | 🔴 Crítica | Fase 1, 2 | ✅ Completada |
| 7 | Backend configurable (CORS, emails, contacto) | 🟡 Medio | 🟡 Alta | Fase 1 | ✅ Completada |
| 8 | SEO y meta tags dinámicos | 🟡 Medio | 🟡 Alta | Fase 1, 7 | ✅ Completada (en Fase 6) |
| 9 | Pasarela de pago modular | 🔴 Alto | 🟢 Media | Fase 1 | ✅ Completada |
| 10 | Sistema de emails white-label | 🟡 Medio | 🟢 Media | Fase 7 | ✅ Completada (en Fase 7) |
| 11 | Documentación y scaffolding | 🟡 Medio | 🟢 Media | Todas | ⬜ Pendiente |

### ⚠️ Acción inmediata de seguridad

**Antes de cualquier fase:** Remover credenciales de `wp-config.prod.php` del repositorio y moverlas a variables de entorno del servidor. El archivo contiene:
- Contraseña de base de datos en texto plano
- JWT secret key
- Credenciales de WooCommerce

---

## Estimación de esfuerzo total

| Fase | Categoría | Horas estimadas | Estado |
|------|-----------|----------------|--------|
| 1 | Plugin Site Settings | 4-6h | ✅ |
| 2 | Renombramiento de prefijos | 4-6h | ✅ |
| 3 | Branding dinámico | 6-8h | ✅ |
| 4 | Moneda configurable | 4-6h | ✅ |
| 5 | Feature flags | 8-12h | ✅ |
| 6 | Hardcoded cleanup (frontend) | 12-16h | ✅ |
| 7 | Backend configurable | 6-8h | ✅ |
| 8 | SEO dinámico | 4-6h | ✅ (en Fase 6) |
| 9 | Pasarela modular | 16-24h | ✅ |
| 10 | Emails white-label | 4-6h | ✅ (en Fase 7) |
| 11 | Docs + scaffolding | 8-12h | ⬜ |
| | **TOTAL** | **~76-110h** | **10/11** |

---

> **Nota:** Este plan preserva toda la funcionalidad existente. Cada fase es incremental y no rompe la aplicación actual. Se puede ejecutar gradualmente en sprints de 1-2 semanas.
