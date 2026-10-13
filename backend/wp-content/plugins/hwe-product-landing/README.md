# HWE Product Landing

Módulo `product-landing` del framework: una **landing por producto** administrable desde la
ficha de WooCommerce y expuesta en WPGraphQL como `Product.landing`. Es un plugin regular
(activable) y no contiene lógica de ninguna instancia. Guía para editores: [`MANUAL.md`](MANUAL.md).

## Qué hace

- Registra la caja **«Landing del producto»** en la ficha. El editor rellena un molde fijo:
  un preámbulo de Publicación y SEO, una paleta opcional y 8 secciones (Hero, Educativa,
  Sobre el producto + componentes, Cualidades, Consejos, CTA de compra, Video y Cierre).
- Tres **listas dinámicas**: componentes (hasta 11), cualidades (hasta 11) y consejos o
  preguntas (hasta 20).
- **Traducción a N idiomas**: los campos escalares se registran en `hwe_translatable_fields`
  (los edita la caja «Traducciones» de `hwe-content-i18n`) y las filas de las listas llevan sus
  traducciones anidadas.
- `Product.landing` es **nulo salvo que esté publicada** (`_hwe_landing_published = '1'`).
- Revalida el tag ISR `products` al cambiar y lo declara en `hwe_revalidation_tags`.
- Compatible con HPOS: solo escribe meta de producto.

El frontend sabe si el módulo está activo por `config.modules['product-landing']` (lo calcula
WordPress según si el plugin está activo). Con el módulo apagado el frontend no consulta
ni pinta nada de esto.

## Estructura

```
hwe-product-landing/
  hwe-product-landing.php   — entrada, constantes y autoloader PSR-4 (HWE\ProductLanding\…)
  includes/
    Plugin.php              — registra las partes
    Fields.php              — esquema: SCALAR_FIELDS, REPEATERS, íconos
    MetaBox.php             — add_meta_box y assets
    Render.php              — HTML del formulario
    Save.php                — POST → meta (saneado, tope de filas, guarda de cambios)
    Translations.php        — idiomas y registro en hwe_translatable_fields
    Graphql.php             — Product.landing, translation(locale) y hweProductLandingsPublished
    Revalidation.php        — tag `products` y revalidación
    Palette.php             — sugerencias de color derivadas de la paleta de marca
  assets/                   — admin.js (listas, medios, color) y admin.css
  README.md  MANUAL.md
```

## Datos (post-meta del producto)

| Meta | Contenido |
|---|---|
| `_hwe_landing_<campo>` | Campo escalar en el idioma por defecto (`published`, `seo_title`, `hero_subtitle`, …) |
| `_hwe_landing_<campo>_<idioma>` | Traducción del campo (`pt-BR` → `pt_br`); la guarda `hwe-content-i18n` |
| `_hwe_landing_components`, `_hwe_landing_qualities`, `_hwe_landing_advice` | Lista de filas; cada fila lleva `i18n.<idioma>` con sus campos traducibles |

Tipos: `boolean` → `'1'`/`'0'`; `richtext` → `wp_kses_post`; `textarea` → texto sin HTML;
`image`/`url` → `esc_url_raw`; `color` → `#rrggbb` estricto; el resto → `sanitize_text_field`.
Solo son `richtext` `edu_body`, `about_body`, `advice_intro` y `closing_text`; los campos de
fila nunca lo son. El orden y los topes (`max_rows`) los fija `Fields::REPEATERS`.

Una fila 100 % vacía en el idioma por defecto se descarta. Un idioma que no sea uno de los
secundarios del sitio no se guarda.

## GraphQL

Tipos: `ProductLanding`, `ProductLandingTranslation`, `ProductLandingComponent`,
`ProductLandingQuality`, `ProductLandingAdvice` y la traducción de cada fila
(`…Translation`).

- `Product.landing` → `ProductLanding` (nulo si no está publicada). Campos escalares en
  camelCase, siempre en el idioma por defecto, más `components`, `qualities` y `adviceItems`.
- `ProductLanding.translation(locale: String!)` y `<Fila>.translation(locale: String!)` →
  los campos traducibles en ese idioma (cada uno puede ser nulo; nulo = usar el del idioma por
  defecto). Un idioma que no es secundario del sitio devuelve nulo.
- `RootQuery.hweProductLandingsPublished` → slugs de los productos con landing publicada
  (para prerender y sitemap sin N+1).

El frontend pide las traducciones con el marcador `# @translation <Tipo>: campos` de
`lib/i18n/translation.ts` y las lee con `pickTranslated`.

```graphql
{
  product(id: "mi-producto", idType: SLUG) {
    landing {
      published
      heroSubtitle
      tr_en: translation(locale: "en") { heroSubtitle }
      components { name icon tr_en: translation(locale: "en") { name } }
    }
  }
}
```

## Extensibilidad

| Hook | Tipo | Uso |
|---|---|---|
| `hwe_product_landing_icons` | filtro | Íconos del selector (`clave en minúsculas → etiqueta`). Se parte del set neutro; una clave inválida se descarta. La instancia declara los mismos en su frontend (el starter solo conoce el set neutro). |
| `hwe_translatable_fields` | filtro | Lo usa el propio plugin para registrar sus campos en el tipo `product`. |
| `hwe_revalidation_tags` | filtro | Lo usa el propio plugin para registrar `products`. |

Íconos del set neutro: `sparkles`, `heart`, `leaf`, `shield`, `sun`, `moon`, `droplets`,
`wind`, `flame`, `anchor`, `compass`, `sprout`. Un test del frontend comprueba que coinciden
con los del starter.

## Revalidación

1. El editor guarda la ficha; `Save` sanea y persiste.
2. Si algo cambió, llama a `WC_Product::save()` (lo que dispara el webhook nativo
   `product.updated`, si el sitio lo tiene) y pide `products` directamente al frontend.
3. Al guardar un producto con la landing publicada también se pide `products`: la traducción
   la guarda `hwe-content-i18n` y el producto puede no haber cambiado.
4. Se envía como mucho una vez por petición.

## Notas de desarrollo

- **Sin `__()`:** el panel está en español y no hay `languages/`. Lo que sí se traduce es el
  contenido de la landing.
- **Un booleano nunca guardado** vale `''` y su saneado `'0'`: es el mismo estado, no un
  cambio (si no, el primer guardado de cualquier producto dispararía un `save()`).
- **Booleanos sin guardar y GraphQL:** una landing publicada sin pasar por la caja reporta
  los valores por defecto del molde (Educativa, Cualidades y Consejos activas; Video apagado).
- **El selector de medios y los editores** usan `wp_enqueue_media()`/`wp_enqueue_editor()`
  solo en la pantalla de producto.

## Tests

`backend/tests/Unit/ProductLanding/`. Desde `backend/`: `composer test`.
