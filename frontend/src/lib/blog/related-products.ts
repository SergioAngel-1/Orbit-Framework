// ============================================================================
//  Relleno de la franja "Productos mencionados" de una entrada del blog.
//
//  La franja es `ProductStrip`, que en escritorio es `lg:grid-cols-4`: con uno
//  o dos productos enlazados en el cuerpo —el caso normal— la fila quedaba
//  coja, con dos huecos vacíos a la derecha. Aquí se completa hasta cuatro con
//  productos PARECIDOS a los mencionados, y el titular de la franja cambia
//  para no llamar "mencionado" a algo que el artículo no nombra.
//
//  Puro y sin `server-only` a propósito: recibe el catálogo que la página ya
//  ha traído para resolver los slugs enlazados, así que rellenar no cuesta
//  ninguna consulta más.
// ============================================================================

/** Tope de la franja: una fila exacta de `ProductStrip` en escritorio. */
export const MENTIONED_PRODUCTS_MAX = 4;

export interface MentionedProductsStrip<T> {
  items: T[];
  /**
   * `true` si hay al menos un producto que el artículo NO menciona. Es lo que
   * decide el titular: con relleno, «Productos mencionados y relacionados».
   */
  hasRelated: boolean;
}

/**
 * Parentesco de un candidato con los productos mencionados, de más a menos:
 *
 *  2 — comparte la categoría CANÓNICA de algún mencionado (la más específica,
 *      no la raíz de la marca).
 *  1 — comparte cualquier otra categoría.
 *  0 — no comparte nada; no es candidato.
 *
 * Los dos niveles existen porque la canónica es la que de verdad describe al
 * producto: sin ese desempate, una raíz de marca con 300 productos daría
 * "relacionados" que solo tienen en común ser de la marca.
 */
function kinship(
  candidate: { categorySlugs?: string[]; canonicalCategory?: string | null },
  canonical: ReadonlySet<string>,
  all: ReadonlySet<string>,
): number {
  const slugs = candidate.categorySlugs ?? [];
  if (candidate.canonicalCategory && canonical.has(candidate.canonicalCategory))
    return 2;
  if (slugs.some((s) => canonical.has(s))) return 2;
  return slugs.some((s) => all.has(s)) ? 1 : 0;
}

/**
 * Recorta los mencionados a `max` y, si no llegan, completa con productos del
 * mismo universo de categorías.
 *
 * El orden de los mencionados se respeta (es el orden en que aparecen en el
 * artículo) y el relleno va detrás, por parentesco y, a igualdad, por el orden
 * del catálogo — que ya viene ordenado por la consulta, así que el resultado
 * es estable entre renders y no baila en cada revalidación.
 */
export function buildMentionedProductsStrip<
  T extends {
    slug: string;
    categorySlugs?: string[];
    canonicalCategory?: string | null;
  },
>(
  mentioned: T[],
  catalog: readonly T[],
  max: number = MENTIONED_PRODUCTS_MAX,
): MentionedProductsStrip<T> {
  const items = mentioned.slice(0, max);
  if (items.length >= max || items.length === 0) {
    return { items, hasRelated: false };
  }

  const taken = new Set(items.map((p) => p.slug));
  const canonical = new Set(
    items.map((p) => p.canonicalCategory).filter((s): s is string => Boolean(s)),
  );
  const all = new Set(items.flatMap((p) => p.categorySlugs ?? []));

  const related = catalog
    .filter((p) => !taken.has(p.slug))
    .map((p) => ({ product: p, rank: kinship(p, canonical, all) }))
    .filter((c) => c.rank > 0)
    // `sort` estable en V8: a igual parentesco manda el orden del catálogo.
    .sort((a, b) => b.rank - a.rank)
    .slice(0, max - items.length)
    .map((c) => c.product);

  return { items: [...items, ...related], hasRelated: related.length > 0 };
}
