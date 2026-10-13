// ============================================================================
//  Listados con estado en la URL (búsqueda, filtros, orden, paginación).
//
//  La vista base es la que se indexa y se anuncia en el sitemap. Una variante
//  con estado (`?search=`, `?category=`, `?after=`…) es una vista derivada: va
//  `noindex, follow` —`follow` mantiene el descubrimiento de las fichas— y SIN
//  bloque `alternates`, porque bajo `noindex` el buscador ignora la canónica y
//  declararla solo añade ruido (y `hreflang` hacia una URL que no se indexa).
// ============================================================================

type RawParam = string | string[] | undefined;

/** ¿La URL lleva alguno de estos parámetros con valor? */
export function hasListingState(
  params: Record<string, RawParam>,
  keys: readonly string[],
): boolean {
  return keys.some((key) => {
    const value = params[key];
    return Array.isArray(value) ? value.some((v) => v !== "") : Boolean(value);
  });
}

/** `robots` de una vista derivada de un listado. */
export const DERIVED_LISTING_ROBOTS = { index: false, follow: true } as const;
