# Conectar un frontend con el framework — guía para agentes

> **Lee primero `AGENTS.md §1.1`**: el framework es backend + arquitectura (WordPress headless
> + el BFF de Next.js en `app/api/*`/`lib/*`). La UI — `components/**` (salvo `ui/`) y todas
> las vistas de `app/[locale]/*` — **no es núcleo del framework**: se hereda una vez al clonar
> y desde ahí es responsabilidad de cada instancia. El framework no la actualiza ni promete
> que se mantenga genérica.

Este documento tiene dos partes con garantías muy distintas:

- **Parte A — El contrato backend/BFF.** Esto es lo único que el framework promete mantener
  estable. Constrúyelo sobre esto tanto si usas la UI heredada como si escribes un frontend
  desde cero (otro framework, otro repo, lo que sea).
- **Parte B — Lo heredado al clonar.** Inventario de la UI que trae el repo hoy: qué existe,
  qué está conectado, qué es un building block sin usar. Es una foto del momento en que se
  escribió esto — no hay promesa de que el framework la actualice. Trátalo como el punto de
  partida de TU proyecto, no como una librería externa.

> **Smoke test del contrato:** `examples/minimal-consumer/` (script Node sin dependencias)
> ejercita la Parte A de punta a punta contra una pila levantada — útil para verificar una
> integración nueva o una actualización del framework.

---

# Parte A — El contrato backend/BFF (estable, mantenido por el framework)

## A.1 Lectura de catálogo/contenido: WPGraphQL + WooGraphQL

- Endpoint: `${WORDPRESS_INTERNAL_API_URL}` (servidor, red interna Docker) o
  `${NEXT_PUBLIC_WORDPRESS_API_URL}` (navegador) + `/graphql`.
- Queries de catálogo/posts son **públicas** (sin auth) — WooGraphQL para productos/
  categorías/variaciones, WPGraphQL nativo para posts. CORS restringido por
  `HEADLESS_ALLOWED_ORIGINS` (mu-plugin `headless-config.php`).
- Queries "como el usuario autenticado" (p. ej. reseñas propias) llevan
  `Authorization: Bearer <accessToken>`.
- **Introspección desactivada y límites de profundidad/complejidad activos**
  (`graphql-protection.php`) — no asumas que puedes introspectar el schema en producción;
  hazlo contra un WordPress local con la misma versión de plugins (ver
  `docs/COMPATIBILITY.md`) o lee las queries ya escritas en `lib/woocommerce/queries.ts` y
  `lib/queries.ts` como referencia del schema real en uso.
- Caché: ISR con `revalidateTag`, invalidado on-demand por webhook de WooCommerce contra
  `POST /api/revalidate` (firma HMAC, `lib/security/webhook.ts`).

## A.2 Escritura y datos privados: el BFF (`app/api/*`)

**Regla de oro: el navegador nunca habla directo con WordPress/WooCommerce para nada
sensible.** Todo pasa por estos Route Handlers, que guardan los secretos (`ck/cs` de
WooCommerce, secreto JWT, secretos de webhook) en el servidor.

| Grupo | Rutas | Qué hace |
|---|---|---|
| Auth | `POST /api/auth/{login,register,refresh,logout,logout-all}`, `GET /api/auth/me`, `POST /api/auth/{forgot-password,reset-password,verify-email,resend-verification,change-password}`, `POST /api/auth/2fa/{setup,activate,disable,status}`, `POST /api/auth/verify-2fa` | Sesión JWT en cookies httpOnly, 2FA TOTP opcional, verificación de email opcional no bloqueante. |
| Tienda | `GET/POST /api/store/cart`, `POST /api/store/cart/items`, `POST /api/store/checkout`, `GET/PUT /api/store/customer`, `GET/POST/DELETE /api/store/coupons`, `GET /api/store/orders/[id]`, `GET /api/store/products`, `GET/POST/DELETE /api/store/wishlist`, `GET/POST /api/store/reviews/[productId]`, `GET/PUT /api/store/addresses`, `GET /api/store/shipping` | Proxy autorizado a la Store API / WC REST v3 de WooCommerce. |
| Pagos | `POST /api/payments/create`, `POST /api/payments/webhook/[provider]`, `GET /api/payments/return` | Capa agnóstica de pasarela (`lib/payments/`). **La prueba de pago es el webhook verificado, nunca el `return`.** |
| Webhooks de Woo | `POST /api/webhooks/woocommerce/{order-created,order-updated}` | Firma HMAC verificada, dispara efectos (`lib/woocommerce/order-events.ts`). |
| Utilidad | `GET /api/csrf`, `GET /api/health` (+`/live`), `POST /api/contact`, `GET /api/og`, `GET /api/icon`, `POST /api/revalidate` | Token CSRF, sondas de salud, formulario de contacto, imágenes OG/icon dinámicas, revalidación on-demand. |

**Contrato de auth/CSRF** (aplica a cualquier frontend que consuma este BFF):
1. Sesión en **cookies httpOnly** (nunca `localStorage` ni body) — el frontend no maneja el
   JWT directamente, solo hace `fetch` con `credentials: "include"`.
2. Toda escritura (`POST`/`PUT`/`PATCH`/`DELETE`) exige **primero** `GET /api/csrf` y reenviar
   el token en la cabecera `X-CSRF-Token`. Sin esto, `guardMutation` (`lib/api/guard.ts`)
   rechaza la petición (también verifica `Origin` y aplica rate-limit).
3. Los importes de la Store API vienen en **unidades menores** (céntimos); los de WooGraphQL
   ya vienen formateados. No los mezcles sin convertir.

## A.3 Config pública dinámica: `GET /wp-json/hwe/v1/config`

Fuente única de verdad de marca/negocio, sin auth, cacheable. Forma exacta (ver
`backend/wp-content/mu-plugins/hwe-control-center/Schema.php` para el detalle de cada campo
y `frontend/src/lib/config/types.ts` para el tipo TypeScript equivalente):

```
brand:  { name, tagline, description, url, logo, locale, og_image }
social: { twitter, instagram, facebook, linkedin, youtube, wikipedia, wikidata }
legal:  { company, nif, email, address }
design: {
  colors:     { brand, brand_dark, brand_light, secondary, secondary_dark, accent, surface, background, foreground }
  typography: { font_sans, font_url, font_heading, font_heading_url }
}
ecommerce: { currency, country, products_per_page, reviews_enabled, wishlist_enabled, coupons_enabled, search_enabled }
banners: { enabled }
integrations: { analytics_provider, analytics_id }
seo: { title_template, robots, google_site_verification, default_og, product_brand,
       shipping_amount, return_days, return_category, organization_logo, founding_date,
       knows_about, founder_name, founder_role, founder_url }
geo: { ai_crawlers, llms_txt_enabled, faq, content_signal }
```

Solo se exponen aquí los campos marcados `'public' => true` en `Schema.php` — los grupos
`payments`/`integrations.smtp_*`/`backups` con campos `secret` **nunca** aparecen en esta
respuesta (se gestionan aparte, ver `docs/CONFIGURATION.md`).

**Flags de funcionalidad opcional** (`ecommerce.reviews_enabled`, `wishlist_enabled`,
`coupons_enabled`, `search_enabled`, configurables desde `wp-admin → HWE Config`): **cualquier
frontend que los consuma debe ocultar la funcionalidad correspondiente cuando el flag es
`false`**, no solo dejar de anunciarla en metadatos. Esto es parte del contrato, no un detalle
de implementación de la UI heredada.

- `brand.logo` — URL del logo de cabecera (medioteca WP). Vacío = renderizar `brand.name`
  como texto.
- `banners.enabled` — activa/desactiva el renderizado de banners. La autoría de los banners
  vive en el plugin **HWE Banners** (CPT `hwe_banner`, REST `hwe-banners/v1/banners`). Lector
  core: `lib/banners/getBannerPlacement(placement, locale)` con ISR tag `banners`.

### Banners — `GET /wp-json/hwe-banners/v1/banners[/{placement}]`

Servido por el plugin **HWE Banners** (activable/desactivable). Público y cacheable
(el frontend lo consume con ISR, tag `banners`, invalidado por webhook al editar un
banner). Gateado en el frontend por `config.banners.enabled`.

- `GET .../banners?lang=es` → `{ "<placement>": { "intervalMs": number, "slides": Slide[] } }`
- `GET .../banners/hero?lang=en` → `{ "intervalMs": number, "slides": Slide[] }`

`Slide` (camelCase, resuelto por locale): `id, placement, order, image, imageMobile,
title, subtitle, cta, ctaHref, badge, link, hideOverlay`. Placements por defecto:
`hero`, `middle`, `bottom` (filtro PHP `hwe_banners_placements`). El lector core está
en `frontend/src/lib/banners/` (`getBannerPlacement(placement, locale)`).

## A.4 Design tokens (paleta/tipografía dinámica)

`design.colors.*`/`design.typography.*` (§A.3) están pensados para mapearse 1:1 a variables
CSS (`--color-brand`, `--color-brand-dark`, `--color-brand-light`, `--color-secondary`,
`--color-secondary-dark`, `--color-accent`, `--color-surface`, `--background`, `--foreground`,
`--font-sans`, `--font-heading`) para que cambiar la paleta en `wp-admin` no requiera tocar
código. El mapeo campo → variable vive en `frontend/src/lib/config/tokens.ts`; el mecanismo de
inyección en runtime (`components/ui/theme-tokens.tsx`) es parte de la UI heredada (Parte B),
pero el **contrato de qué 9 colores + 2 tipografías existen** es estable.

## A.5 Webhooks salientes (WordPress/WooCommerce → tu frontend)

- **Pedido creado/actualizado**: `POST {frontend}/api/webhooks/woocommerce/order-{created,updated}`,
  firmado con `WC_WEBHOOK_SECRET` (HMAC). Payload = el de WooCommerce; el estado real se
  calcula por diff contra el último conocido (Redis), porque el payload solo trae el estado
  actual.
- **Revalidación de catálogo**: `POST {frontend}/api/revalidate`, firmado con
  `HWE_REVALIDATION_SECRET` (cabecera `X-HWE-Signature`).
- **Pago** (si integras una pasarela real): `POST {frontend}/api/payments/webhook/[provider]`,
  la verificación de firma/importe/moneda es responsabilidad del provider (`lib/payments/`).

## A.6 Menús de navegación (WordPress → cualquier frontend)

Los menús se gestionan en **wp-admin → Apariencia → Menús** y se exponen por WPGraphQL.
El tema `hwe-headless-base` registra cuatro *locations*; asignar un menú a una location
es la "selección del menú activo":

| Location | Enum WPGraphQL | Uso |
|---|---|---|
| `primary` | `PRIMARY` | Navegación principal (locale por defecto, `es`) |
| `primary_en` | `PRIMARY_EN` | Navegación principal en `/en` |
| `footer` | `FOOTER` | Columnas del pie (locale por defecto) |
| `footer_en` | `FOOTER_EN` | Columnas del pie en `/en` |

**Regla de contenido:** los items deben ser **enlaces personalizados con rutas relativas del
frontend** (`/products`, `/blog`, `/contact`…). No uses páginas/entradas de WP: sus permalinks
apuntan a WordPress, no al frontend.

Query de referencia:

```graphql
query MenuByLocation($location: MenuLocationEnum!) {
  menuItems(where: { location: $location }, first: 100) {
    nodes { id parentId label uri }
  }
}
```

En el frontend heredado ya hay un consumidor listo: `lib/navigation/menu.ts`
(`getMenu(area, locale)` — ISR 5 min, tag `menus`, fail-soft a `null`). Un item raíz **con
hijos** se renderiza como dropdown (header) o columna (footer). Si la location no tiene menú
asignado, el frontend heredado usa su navegación local por defecto.

---

# Parte B — Lo heredado al clonar (código de instancia, NO mantenido por el framework)

> Todo lo de aquí abajo describe el estado de `components/**` (salvo `ui/`) y
> `app/[locale]/*` **al momento de escribir esto**. Úsalo como inventario de tu punto de
> partida, no como referencia de una librería que vaya a actualizarse. Si lo reescribes por
> completo, este documento deja de aplicar a tu proyecto — eso es exactamente lo esperado.

## B.1 Inventario de páginas (`frontend/src/app/[locale]/`)

Leyenda: ✅ **completa** (usa datos reales, sin componentes de relleno) · 🧱 **mínima**
(funciona pero es un esqueleto — buen punto de partida, no un diseño terminado).

| Ruta | Estado | Componentes clave | Notas |
|---|---|---|---|
| `/` (home) | 🧱 | `HeroCarousel` (gateado por `banners.enabled`), `PostCard`, `FaqSection` | Título/descripción + carrusel de banners (Banner Manager, §A.3) + últimos posts + FAQ (`config.geo.faq`). No usa `PageHero`, `TrustBar`, `CategoryCard` — disponibles, no conectados aquí. Primer candidato a rediseñar por negocio. |
| `/products` | 🧱 | `InfiniteProductGrid` | Filtro con un `<form>` HTML plano (`search`/`category`/`minPrice`/`maxPrice`/`sort` por query string). No usa `FilterChips`, `SortDropdown`, `SearchModal`, `CategoryCard` — todos disponibles, ninguno conectado. |
| `/products/[slug]` | ✅ | `ProductActions`, `VariationSelector`, `ReviewForm` (gateado), `ProductGrid` (relacionados) | Ficha de producto completa: imagen, precio, variantes, JSON-LD `Product` + `BreadcrumbList`. |
| `/categories/[slug]` | ✅ | `ProductGrid` | Listado por categoría con JSON-LD breadcrumb. |
| `/blog` | ✅ | `PostCard` | Listado de entradas. |
| `/blog/[slug]` | ✅ | — | Entrada individual, JSON-LD `Article`. |
| `/about` | ✅ | — | 100% `getSiteConfig()` (descripción, fundador, áreas de expertise, JSON-LD `Person`) — sin componentes de layout nuevos, buen ejemplo de vista "de config pura". |
| `/contact` | ✅ | `ContactForm` | Usa `config.legal.email` + `config.social.*`. Sin teléfono/sedes por defecto (el schema no tiene esos campos — pásalos como prop si el negocio los necesita, ver B.3). |
| `/cart` | ✅ | `CartView` | — |
| `/checkout`, `/checkout/return` | ✅ | `CheckoutForm` | Cupón gateado por `coupons_enabled` (ver A.3). |
| `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email` | ✅ | `*-form.tsx` de `components/auth/` | Flujo de auth completo (JWT + 2FA opcional). |
| `/account/*` | ✅ | `components/account/*` | Perfil, pedidos, direcciones, wishlist (gateado), 2FA, cambio de contraseña. |
| `/legal/[slug]` | ✅ | — | Páginas legales (privacidad/cookies/términos/devoluciones) desde `i18n/messages`. |

**Lectura clave**: lo que resuelve funcionalidad de cuenta/carrito/checkout/auth está completo
y probado — normalmente no necesitas tocarlo. Lo que suele reescribirse por completo por
instancia son las **vistas de marketing/descubrimiento** (home, listado de productos): ahí es
donde vive la mayoría del trabajo de "construir el frontend de un negocio concreto".
`docs/FRONTEND_BUILD.md` ayuda a decidir qué construir ahí.

## B.2 Catálogo de componentes por dominio

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
  por prop — no hace fetch propio.* ✅ Conectado en la home vía Banner Manager
  (`config.banners.*` + `parseBanners`, gateado por `banners.enabled`).
- **`PageHero`** — cabecera de página con breadcrumb opcional, título/subtítulo, fondo con o
  sin overlay. *Recibe todo por props.* 🧱 Disponible, no conectada — buen candidato para dar
  identidad a `/products`, `/blog`, `/about` en vez del `<h1>` plano actual.
- **`Breadcrumb`** — usado internamente por `PageHero`; también usable suelto.

### `components/marketing/` — tarjetas de contenido editorial

- **`AllyCard`** — tarjeta de "aliado/partner" (logo, nombre, descripción, link). *Recibe
  todo por props.* 🧱 Disponible, no conectada. Si el negocio tiene partners/aliados reales,
  necesitas una fuente de datos propia (un CPT vía mu-plugin, o un array estático) — eso es
  100% código de tu instancia, no algo que el framework provea.
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
  (`brand`/`secondary`/`accent`/`surface` — mapeadas a los design tokens, ver A.4). *Recibe
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
  con default vacío** (una sección se oculta si no le pasas datos). Envía a `POST /api/contact`
  (§A.2; hoy solo loggea, tiene un TODO para reenviar a email/WordPress real). ✅ Conectado en
  `/contact`. `country-selector.tsx` y `contact-form.data.ts` (`REQUEST_TYPES`/`COUNTRIES`)
  son genéricos, sin datos de negocio.

### `components/ui/` — primitivas (esto sí es núcleo del framework, ver `AGENTS.md §1.1`)

`Button`, `Input`, `Select`, `Textarea`, `Checkbox`, `Card`, `Badge`, `Modal`, `Alert`,
`Skeleton`, `Spinner`, `Paginator`, `QuantityCounter`, `DarkModeToggle`. Sin dependencia de
negocio — vocabulario visual base. A diferencia del resto de `components/`, estas sí se
consideran parte del framework: úsalas en vez de reinventar botones/inputs sueltos.

### Resto (funcionalidad de producto, normalmente no se rediseña por instancia)

`components/cart/`, `components/checkout/`, `components/account/`, `components/auth/`,
`components/analytics/`, `components/i18n/`, `components/blog/`, `components/seo/` —
implementan flujos del BFF (carrito, checkout, cuenta, auth). Aunque técnicamente son
"heredados" igual que el resto de `components/`, en la práctica cambian poco entre
instancias porque siguen de cerca el contrato de la Parte A. Tócalos solo si el negocio pide
una funcionalidad de cuenta/carrito distinta al estándar.

## B.3 Cómo componer una vista (si partes de lo heredado)

1. **Server Component** (la página en `app/[locale]/…/page.tsx`): llama a
   `getSiteConfig()` / `lib/catalog`/`lib/blog` según lo que necesites, con `await Promise.all(...)`.
2. **Pasa los datos como props** a componentes de `components/` — no dupliques fetch en
   componentes cliente si el padre ya tiene el dato.
3. **Gatea funcionalidad opcional** con los flags de `config.ecommerce.*` (§A.3) — nunca la
   muestres incondicionalmente si tiene un flag correspondiente.
4. **i18n**: añade las keys que uses en `i18n/messages/es.json` **y** `en.json` en paralelo
   (mismo namespace/estructura). Usa `Link`/`redirect` de `@/i18n/navigation`, no de `next/*`.
5. **Diseño**: usa las clases utilitarias de marca (§A.4: `brand`, `brand-dark`, `brand-light`,
   `secondary`, `secondary-dark`, `accent`, `surface`, `font-sans`, `font-heading`) en vez de
   colores/fuentes hardcodeados.
6. **SEO** (si la página es pública e indexable): `alternates: alternatesFor(...)` en
   `generateMetadata`, añade la ruta a `app/sitemap.ts` si debe indexarse, y JSON-LD si aplica
   (`lib/seo/jsonld.ts` tiene builders para `Product`, `Article`, `Person`, `BreadcrumbList`,
   `WebSite`/`Organization`).
7. **`setRequestLocale(locale)`** en páginas estáticas; `force-dynamic` solo si depende del
   usuario/sesión (ver `AGENTS.md §7`).
8. Verifica con `npx tsc --noEmit`, `npm run test`, y `npx next build` antes de dar la vista
   por terminada (`AGENTS.md §8`).

## B.4 Si NO partes de lo heredado (frontend nuevo desde cero)

Todo lo de la Parte A sigue aplicando igual — es el contrato, no importa el stack. Lo único
que necesitas construir tú mismo:
- Un cliente GraphQL contra WPGraphQL/WooGraphQL (§A.1).
- Llamadas `fetch` al BFF con `credentials: "include"` + el flujo CSRF (§A.2).
- Lectura de `/wp-json/hwe/v1/config` y aplicación de los design tokens (§A.4) al sistema de
  diseño que elijas.
- Manejo de los flags de funcionalidad opcional (§A.3) en tu propia UI.

No hay SDK ni cliente oficial más allá del código Next.js heredado — si lo extraes como
referencia de "cómo se hizo una vez", perfecto; si no, la Parte A es toda la documentación que
necesitas.
