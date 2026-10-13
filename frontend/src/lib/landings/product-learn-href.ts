// ============================================================================
//  Dirección de la landing de un producto: `/products/<slug>/learn`.
//
//  Cuelga de la ficha, así que hereda su tramo de URL por idioma (`urlSlug`,
//  el slug traducido en un idioma secundario). Sin `urlSlug` se usa el nativo,
//  que la ficha redirige (308) al del idioma.
//
//  Módulo PURO: lo usan tarjetas, la ficha, el sitemap y los tests.
// ============================================================================

/** Tramo fijo que cuelga de la ficha del producto. */
export const PRODUCT_LEARN_SEGMENT = "learn";

interface Sluggable {
  slug: string;
  urlSlug?: string | null;
}

/** Ruta interna de la landing del producto, sin prefijo de idioma. */
export function productLearnHref(product: Sluggable): string {
  return `/products/${encodeURIComponent(product.urlSlug || product.slug)}/${PRODUCT_LEARN_SEGMENT}`;
}

/**
 * Ruta de la landing en cada idioma (`paths` de las alternativas hreflang y del
 * sitemap) a partir del tramo de URL de la ficha en cada uno.
 */
export function productLearnPaths(
  urlSlugs: Readonly<Record<string, string>>,
): Record<string, string> {
  return Object.fromEntries(
    Object.entries(urlSlugs).map(([locale, slug]) => [
      locale,
      productLearnHref({ slug, urlSlug: slug }),
    ]),
  );
}
