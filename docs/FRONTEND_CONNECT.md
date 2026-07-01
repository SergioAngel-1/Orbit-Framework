# Conectar el frontend con el framework — guía para agentes

> Audiencia: un agente que va a **construir o adaptar las vistas** del frontend Next.js para
> una instancia concreta. Este documento NO cubre el "chore" de infraestructura (Docker,
> `.env`, secretos, WooCommerce) — eso ya está en `docs/INSTALL.md` y `docs/CREATE_INSTANCE.md`.
> Aquí el tema es: **qué páginas y componentes ya existen, cómo sacan sus datos, cuáles están
> conectados a una vista real y cuáles son piezas disponibles sin usar todavía**, para que
> puedas componer el frontend de un negocio concreto sin reinventar ni romper el patrón.
>
> Si vas a levantar el frontend de una instancia nueva desde cero (o adaptarlo a un negocio
> específico), primero pasa por **`docs/FRONTEND_BUILD.md`** — esa guía te hace la entrevista
> de características del negocio y te devuelve un plan de qué construir; este documento es la
> referencia que usas mientras lo construyes.

---

## 1. El principio: todo lo que se ve viene de `getSiteConfig()` o de GraphQL/Store API

No hay una segunda fuente de verdad. Antes de escribir una vista nueva, entiende esto:

```
WordPress (wp-admin → HWE Config)  ──/wp-json/hwe/v1/config──►  getSiteConfig()  ──►  vistas
WooCommerce (catálogo/carrito)     ──WPGraphQL / Store API────►  lib/catalog, lib/woocommerce  ──►  vistas
```

- **`getSiteConfig()`** (`frontend/src/lib/config/index.ts`) es la ÚNICA fuente de marca/negocio:
  nombre, tagline, descripción, redes sociales, datos legales, colores/tipografía, flags de
  ecommerce, SEO, envío, GEO. Es `server-only`, cacheada por request, con fallback a
  `CONFIG_DEFAULTS` si WordPress no responde. **Nunca hardcodees marca/URL/colores en un
  componente** — si necesitas un dato de negocio, sale de aquí.
- **Catálogo** (productos/categorías): `lib/catalog/products.ts` (`getProducts`,
  `getProductBySlug`, `getProductSlugs`, `getCategory`, `getCategories`) — GraphQL con ISR.
- **Carrito/checkout**: `components/cart/cart-context.tsx` (estado cliente) + `lib/client/store-api.ts`
  → BFF (`app/api/store/*`) → Store API de WooCommerce.
- **Blog**: `lib/blog/posts.ts` — GraphQL con ISR.

Regla práctica: **si un Server Component necesita mostrar algo del negocio, llama a
`getSiteConfig()` (y/o a `lib/catalog`/`lib/blog`) y pasa los datos como props a los
componentes de presentación** (muchos de `components/` son "tontos": reciben todo por props y
no hacen fetch propio — eso los hace reutilizables entre instancias).

### 1.1 El patrón de feature flags (`config.ecommerce.*_enabled`)

El HWE Control Center expone 4 interruptores de funcionalidad opcional:
`reviews_enabled`, `wishlist_enabled`, `coupons_enabled`, `search_enabled`
(`wp-admin → HWE Config → Tienda`). **Toda vista que renderice esa funcionalidad debe
comprobar el flag antes de mostrarla** — si no, un dueño de tienda desactiva "reseñas" en el
panel y la UI las sigue mostrando (esto era un bug real, corregido; no lo reintroduzcas).

Ejemplos ya aplicados — cópialos como plantilla:

| Flag | Dónde se comprueba | Qué oculta si es `false` |
|---|---|---|
| `wishlist_enabled` | `components/products/product-card.tsx`, `app/[locale]/account/layout.tsx` | Botón de wishlist en la tarjeta de producto; enlace "Wishlist" en el menú de cuenta |
| `reviews_enabled` | `app/[locale]/products/[slug]/page.tsx` | Toda la sección de reseñas (lista + formulario) |
| `coupons_enabled` | `components/cart/cart-drawer.tsx`, `components/checkout/checkout-form.tsx` | Campo de cupón (recibido como prop `couponsEnabled`, resuelto en el Server Component padre con `getSiteConfig()`) |
| `search_enabled` | `lib/seo/jsonld.ts` (`SearchAction`) | — `components/products/search-modal.tsx` existe pero **no está conectado a ninguna página todavía** (ver §3); cuando lo conectes, gátalo también con este flag |

---

## 2. Inventario de páginas (`frontend/src/app/[locale]/`)

Leyenda: ✅ **completa** (usa datos reales, sin componentes de relleno) · 🧱 **mínima**
(funciona pero es un esqueleto — buen punto de partida, no un diseño terminado) · —
no aplica.

| Ruta | Estado | Componentes clave | Notas |
|---|---|---|---|
| `/` (home) | 🧱 | `PostCard`, `FaqSection` | Solo título/descripción + últimos posts + FAQ (`config.geo.faq`). No usa `HeroCarousel`, `PageHero`, `TrustBar`, `CategoryCard` — todos disponibles, ninguno conectado aquí. Primer candidato a rediseñar por negocio. |
| `/products` | 🧱 | `InfiniteProductGrid` | Filtro con un `<form>` HTML plano (`search`/`category`/`minPrice`/`maxPrice`/`sort` por query string). No usa `FilterChips`, `SortDropdown`, `SearchModal`, `CategoryCard` — todos disponibles, ninguno conectado. |
| `/products/[slug]` | ✅ | `ProductActions`, `VariationSelector`, `ReviewForm` (gateado), `ProductGrid` (relacionados) | Ficha de producto completa: imagen, precio, variantes, JSON-LD `Product` + `BreadcrumbList`. |
| `/categories/[slug]` | ✅ | `ProductGrid` | Listado por categoría con JSON-LD breadcrumb. |
| `/blog` | ✅ | `PostCard` | Listado de entradas. |
| `/blog/[slug]` | ✅ | — | Entrada individual, JSON-LD `Article`. |
| `/about` | ✅ | — | 100% `getSiteConfig()` (descripción, fundador, áreas de expertise, JSON-LD `Person`) — sin componentes de layout nuevos, buen ejemplo de vista "de config pura". |
| `/contact` | ✅ | `ContactForm` | Usa `config.legal.email` + `config.social.*`. Sin teléfono/sedes por defecto (el schema no tiene esos campos — pásalos como prop si el negocio los necesita, ver §4). |
| `/cart` | ✅ | `CartView` | — |
| `/checkout`, `/checkout/return` | ✅ | `CheckoutForm` | Cupón gateado por `coupons_enabled` (ver §1.1). |
| `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email` | ✅ | `*-form.tsx` de `components/auth/` | Flujo de auth completo (JWT + 2FA opcional). |
| `/account/*` | ✅ | `components/account/*` | Perfil, pedidos, direcciones, wishlist (gateado), 2FA, cambio de contraseña. |
| `/legal/[slug]` | ✅ | — | Páginas legales (privacidad/cookies/términos/devoluciones) desde `i18n/messages`. |

**Lectura clave**: el "backend de negocio" (catálogo, cuenta, checkout, auth) está completo y
probado. Lo que falta trabajar por instancia son las **vistas de marketing/descubrimiento**
(home, listado de productos) — ahí es donde vive la mayoría del trabajo de "construir el
frontend de un negocio concreto". `docs/FRONTEND_BUILD.md` te ayuda a decidir qué meter ahí.

---

## 3. Catálogo de componentes por dominio

Formato por componente: **qué hace** — *de dónde saca datos* — estado de conexión.

### `components/layout/` — armazón de página

- **`SiteHeader`** — nav + carrito + selector de idioma + modo oscuro. *Traducciones (`nav`,
  `site`) + `useCart()`.* ✅ Conectado en `app/[locale]/layout.tsx` (todas las páginas).
- **`SiteFooter`** — columnas de enlaces + redes sociales + copyright. *`getSiteConfig()`
  (tagline, `config.social.*`) + traducciones (`footer`, `nav`, `site`).* Acepta `columns?:
  FooterColumn[]` opcional — por defecto usa las páginas ya existentes (tienda/blog/sobre
  nosotros/contacto/legal), sin inventar categorías de catálogo. Pásalo explícito si el
  negocio necesita otra estructura (p. ej. columnas por categoría real de producto). ✅
  Conectado en `layout.tsx`.
- **`TrustBar`** — franja de 4 badges de confianza bajo el header. *Traducciones
  (`trustBar.*`: `natural`, `freeShipping`, `returns`, `securePayment`).* 🧱 **Disponible,
  no conectada a ningún layout.** Las 4 etiquetas son copy de ejemplo (p. ej. "100% Natural"
  no encaja con cualquier vertical) — si la usas, ajusta las labels/i18n al negocio o
  sustituye alguna por otra genérica (envío/devoluciones/pago seguro si encajan mejor).
- **`HeroCarousel`** — carrusel de slides con imagen/título/CTA. *Recibe `slides: HeroSlide[]`
  por prop — no hace fetch propio.* 🧱 Disponible, no conectado. Pensado para la home.
- **`PageHero`** — cabecera de página con breadcrumb opcional, título/subtítulo, fondo con o
  sin overlay. *Recibe todo por props.* 🧱 Disponible, no conectada — buen candidato para dar
  identidad a `/products`, `/blog`, `/about` en vez del `<h1>` plano actual.
- **`Breadcrumb`** — usado internamente por `PageHero`; también usable suelto.

### `components/marketing/` — tarjetas de contenido editorial

- **`AllyCard`** — tarjeta de "aliado/partner" (logo, nombre, descripción, link). *Recibe
  todo por props.* 🧱 Disponible, no conectada. Si el negocio tiene partners/aliados reales,
  necesitas una fuente de datos (WordPress no trae un CPT para esto en el framework base —
  créalo como mu-plugin *propio de la instancia*, nunca en el framework base; ver
  `docs/CREATE_INSTANCE.md §7`).
- **`VideoCard`** — tarjeta de vídeo (thumbnail, canal, vistas, duración). *Recibe todo por
  props.* 🧱 Disponible, no conectada.

### `components/products/` — catálogo

- **`ProductCard`** — tarjeta vertical (grid). *`CatalogProduct` por prop + `getSiteConfig()`
  interno para gatear wishlist.* ✅ Usada en `ProductGrid`/`InfiniteProductGrid`.
- **`ProductCardHorizontal`** — tarjeta horizontal compacta (pensada para resultados de
  búsqueda/listas densas). *Recibe `product: CatalogProduct` + rating/reviews opcionales por
  prop.* 🧱 Disponible; usada internamente por `SearchModal`.
- **`ProductGrid`** / **`InfiniteProductGrid`** — grid con/sin scroll infinito. ✅ Conectadas.
- **`CategoryCard`** — tarjeta de categoría con 4 variantes de color temático
  (`brand`/`secondary`/`accent`/`surface` — mapeadas a los tokens de diseño, ver §5). *Recibe
  `href`/`label`/`image` por prop.* 🧱 Disponible, no conectada — candidata natural para la
  home o el header de `/products`.
- **`FilterChips`** — chips de filtro activo/inactivo. *Controlado: recibe `options`/`active`/
  `onChange` por prop, sin lógica de URL propia.* 🧱 Disponible, no conectada — `/products`
  sigue usando un `<form>` HTML plano.
- **`SortDropdown`** — desplegable de ordenación. *Igual de controlado que `FilterChips`.* 🧱
  Disponible, no conectado.
- **`SearchModal`** — modal de búsqueda global (productos vía `ProductCardHorizontal` + otros
  resultados). *Presentacional puro: recibe `productResults`/`otherResults` ya cargados por el
  padre; `onSearch` es un callback, el componente NO llama a ninguna API.* 🧱 **Tiene un TODO
  explícito en el código**: conectar `onSearch` a la Store API (productos) y a WPGraphQL
  (posts/páginas). Si lo conectas, gátalo con `config.ecommerce.search_enabled`.
- **`ProductActions`**, **`VariationSelector`**, **`ReviewForm`**, **`WishlistButton`** — ✅
  conectados en la ficha de producto.

### `components/forms/` — formulario de contacto

- **`ContactForm`** — formulario + panel de "canales de contacto". Props: `email?`, `phone?`,
  `socials?: { facebook?, instagram?, youtube? }`, `branches?: Branch[]`. **Todo opcional y
  con default vacío** (una sección se oculta si no le pasas datos) — así el componente sirve
  para cualquier negocio, tenga o no sedes físicas. Envía a `POST /api/contact` (Zod +
  rate-limit; hoy solo loggea, tiene un TODO para reenviar a email/WordPress real). ✅
  Conectado en `/contact`. `country-selector.tsx` y `contact-form.data.ts`
  (`REQUEST_TYPES`/`COUNTRIES`) son genéricos, sin datos de negocio.

### `components/ui/` — primitivas

`Button`, `Input`, `Select`, `Textarea`, `Checkbox`, `Card`, `Badge`, `Modal`, `Alert`,
`Skeleton`, `Spinner`, `Paginator`, `QuantityCounter`, `DarkModeToggle`. Todas genéricas,
sin dependencia de negocio — son el vocabulario visual base;úsalas en vez de reinventar
botones/inputs sueltos.

### Resto (ya completos, no suelen necesitar cambios por instancia)

`components/cart/`, `components/checkout/`, `components/account/`, `components/auth/`,
`components/analytics/`, `components/i18n/`, `components/blog/`, `components/seo/` — lógica de
producto core del framework, no de marketing. Tócalos solo si el negocio pide una
funcionalidad de cuenta/carrito distinta al estándar.

---

## 4. Cómo componer una vista nueva (checklist)

1. **Server Component** (la página en `app/[locale]/…/page.tsx`): llama a
   `getSiteConfig()` / `lib/catalog`/`lib/blog` según lo que necesites, con `await Promise.all(...)`.
2. **Pasa los datos como props** a componentes de `components/` — no dupliques fetch en
   componentes cliente si el padre ya tiene el dato.
3. **Gatea funcionalidad opcional** con los flags de `config.ecommerce.*` (§1.1) — nunca la
   muestres incondicionalmente si tiene un flag correspondiente.
4. **i18n**: añade las keys que uses en `i18n/messages/es.json` **y** `en.json` en paralelo
   (mismo namespace/estructura). Usa `Link`/`redirect` de `@/i18n/navigation`, no de `next/*`.
5. **Diseño**: usa las clases utilitarias de marca (`brand`, `brand-dark`, `brand-light`,
   `secondary`, `secondary-dark`, `accent`, `surface`, `font-sans`, `font-heading`) en vez de
   colores/fuentes hardcodeados — así la vista respeta la paleta que cada instancia define en
   `wp-admin → HWE Config → Diseño` (ver `frontend/src/lib/config/tokens.ts` para el mapeo
   completo campo → variable CSS).
6. **SEO** (si la página es pública e indexable): `alternates: alternatesFor(...)` en
   `generateMetadata`, añade la ruta a `app/sitemap.ts` si debe indexarse, y JSON-LD si aplica
   (`lib/seo/jsonld.ts` tiene builders para `Product`, `Article`, `Person`, `BreadcrumbList`,
   `WebSite`/`Organization`).
7. **`setRequestLocale(locale)`** en páginas estáticas; `force-dynamic` solo si depende del
   usuario/sesión (ver `AGENTS.md §7`).
8. Verifica con `npx tsc --noEmit`, `npm run test`, y `npx next build` antes de dar la vista
   por terminada (`AGENTS.md §8`).

---

## 5. Design tokens (paleta/tipografía por instancia)

`wp-admin → HWE Config → Diseño` define 9 colores + 2 tipografías por instancia
(`design.colors.*`, `design.typography.*` en el schema — ver
`backend/wp-content/mu-plugins/hwe-control-center/Schema.php`). Se mapean a variables CSS en
`frontend/src/lib/config/tokens.ts` y se inyectan en runtime por `components/ui/theme-tokens.tsx`
(montado en `layout.tsx`). Nunca escribas un color hex en un componente: usa las clases
Tailwind (`bg-brand`, `text-secondary`, `border-accent/30`, `bg-surface`, `font-heading`…) —
así cualquier instancia puede cambiar toda la paleta sin tocar código.
