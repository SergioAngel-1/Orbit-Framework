// ============================================================================
//  Rutas internas de una ficha de producto y de una categoría.
//
//  `urlSlug` lo resuelven al idioma de la página las lecturas de
//  `lib/catalog/products.ts` (slug traducido en un idioma secundario, si lo hay);
//  sin él se usa el slug nativo, que la ficha redirige (308) al del idioma.
//
//  Módulo PURO: lo usan tarjetas de cliente y vistas de servidor.
// ============================================================================

interface Sluggable {
  slug: string;
  urlSlug?: string | null;
}

function segment(entity: Sluggable): string {
  return encodeURIComponent(entity.urlSlug || entity.slug);
}

export function productHref(product: Sluggable): string {
  return `/products/${segment(product)}`;
}

export function categoryHref(category: Sluggable): string {
  return `/categories/${segment(category)}`;
}
