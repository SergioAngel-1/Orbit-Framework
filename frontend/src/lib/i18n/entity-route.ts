import "server-only";
import { resolveBySlugParam, type SlugPostType } from "@/lib/i18n/slug-resolution";

// ============================================================================
//  Ruta de una ficha (entrada, producto, categoría) a partir del tramo de URL.
//
//  Las páginas `[slug]` reciben el tramo de URL del idioma pedido, que puede ser
//  el slug traducido, el nativo o el de OTRO idioma (enlaces viejos, el selector
//  de idioma conserva los params). Aquí se resuelve a la ficha y se calcula su
//  URL canónica en el idioma de la página: si el tramo pedido no es el canónico,
//  la página redirige con 308 (`permanentRedirect`) a `canonicalHref`.
// ============================================================================

export interface EntityRoute<T> {
  entity: T;
  /** Slug nativo: la identidad de la ficha (carrito, cupones, consultas a WordPress). */
  nativeSlug: string;
  /** Tramo de URL canónico en el idioma de la página. */
  canonicalSlug: string;
  /** Ruta interna canónica (`/products/t-shirt`), lista para `permanentRedirect`. */
  canonicalHref: string;
  /** El tramo pedido ya es el canónico: no hace falta redirigir. */
  isCanonical: boolean;
}

/**
 * Resuelve el tramo `param` de una ruta `<prefix>/<param>` a su ficha. `load`
 * recibe el slug NATIVO y devuelve la ficha ya localizada (con `urlSlug`), o
 * null. Null si el tramo no corresponde a ninguna ficha en ningún idioma.
 */
export async function resolveEntityRoute<T extends { urlSlug?: string | null }>(
  postType: SlugPostType,
  prefix: string,
  param: string,
  locale: string,
  load: (nativeSlug: string) => Promise<T | null>,
): Promise<EntityRoute<T> | null> {
  const resolved = await resolveBySlugParam(postType, param, locale, load);
  if (!resolved) return null;
  const canonicalSlug = resolved.item.urlSlug || resolved.nativeSlug;
  return {
    entity: resolved.item,
    nativeSlug: resolved.nativeSlug,
    canonicalSlug,
    canonicalHref: `${prefix}/${encodeURIComponent(canonicalSlug)}`,
    isCanonical: canonicalSlug === param,
  };
}
